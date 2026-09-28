<?php
// Scheduler engine for ytdlp QPKG: manages task concurrency and monitors worker PIDs
require_once __DIR__ . '/common.php';

$lock_file = CONF_DIR . '/.scheduler.lock';
$fp = @fopen($lock_file, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    // 另一个 scheduler 正在运行，安全退出
    exit(0);
}

$tasks = get_tasks();
if (empty($tasks)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
}

$config = get_app_config();
$max_concurrent = max(1, intval($config['max_concurrent_tasks'] ?? 2));
$tasks_updated = false;
$active_count = 0;

$now = time();

function clean_platform_prefix($title, $platform = '') {
    if (empty($title)) return '';
    $patterns = ['youtube', 'bilibili', 'tiktok', 'douyin', 'twitter', 'weibo'];
    if (!empty($platform) && is_string($platform)) {
        $trimmed_plat = trim($platform);
        if ($trimmed_plat !== '') {
            array_unshift($patterns, preg_quote($trimmed_plat, '/'));
        }
    }
    $plat_pattern = '/^(' . implode('|', $patterns) . ')[\s\-_]+/i';
    return preg_replace($plat_pattern, '', $title);
}

function is_auxiliary_asset_file($path) {
    if (empty($path) || !is_string($path)) return false;
    $clean = preg_replace('/(\.part|\.ytdl)$/i', '', trim($path));
    $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
    $aux_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'vtt', 'srt', 'ass', 'ssa', 'lrc', 'json', 'xml', 'description', 'mhtml', 'temp'];
    return in_array($ext, $aux_exts, true);
}

/**
 * 实时解析运行中任务的多流标准日志，进行流级别尺寸聚合与真实全局进度计算
 * 杜绝次级流覆盖主流、封面字幕污染、以及多流下载卡死在 99.5% 的核心痛点
 */
function inspect_running_task_streams($output_log, $is_multi_stream = true) {
    if (!file_exists($output_log)) return null;
    $lines = file($output_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (empty($lines)) return null;

    // 若日志超过 600 行，保留头部 50 行（提取首个流目标文件名）与尾部 500 行（实时多流进度），大幅降低 I/O 与 CPU 循环
    if (count($lines) > 600) {
        $lines = array_merge(array_slice($lines, 0, 50), array_slice($lines, -500));
    }

    $streams = [];
    $current_dest = 'main';
    $is_merging = false;
    $target_file = '';
    $latest_media_dest = '';
    $last_speed = '';
    $last_eta = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/Merging formats into [\"\x27]([^\x27\"]+)[\"\x27]/i', $line, $m)) {
            $is_merging = true;
            $target_file = $m[1];
        } elseif (preg_match('/\[download\] Destination:\s*(.+)$/i', $line, $m)) {
            $dest_cand = trim($m[1]);
            if (!is_auxiliary_asset_file($dest_cand)) {
                $current_dest = $dest_cand;
                $latest_media_dest = $dest_cand;
            } else {
                $current_dest = 'aux_' . $dest_cand;
            }
        } elseif (stripos($line, '[Merger]') !== false) {
            $is_merging = true;
        }

        if (strpos($current_dest, 'aux_') !== 0) {
            $parsed = parse_ytdlp_log_line($line);
            if ($parsed) {
                if (!isset($streams[$current_dest])) {
                    $streams[$current_dest] = ['total_b' => 0, 'dl_b' => 0, 'pct' => 0];
                }
                if (!empty($parsed['total_size'])) {
                    $sz_b = parse_size_str($parsed['total_size']);
                    if ($sz_b > 0) $streams[$current_dest]['total_b'] = max($streams[$current_dest]['total_b'], $sz_b);
                }
                if (!empty($parsed['downloaded'])) {
                    $dl_b = parse_size_str($parsed['downloaded']);
                    if ($dl_b > 0) $streams[$current_dest]['dl_b'] = max($streams[$current_dest]['dl_b'], $dl_b);
                }
                if (isset($parsed['progress'])) {
                    $streams[$current_dest]['pct'] = max($streams[$current_dest]['pct'], floatval($parsed['progress']));
                }
                if (!empty($parsed['speed'])) $last_speed = $parsed['speed'];
                if (!empty($parsed['eta'])) $last_eta = $parsed['eta'];
            }
        }
    }

    if (empty($streams)) {
        return [
            'is_merging' => $is_merging,
            'target_file' => $target_file,
            'dest_file' => $latest_media_dest,
            'speed' => $last_speed,
            'eta' => $last_eta,
            'total_size' => '',
            'downloaded' => '',
            'progress' => 0.0
        ];
    }

    $total_bytes = 0;
    $dl_bytes = 0;

    foreach ($streams as $dest => $s) {
        $st_total = $s['total_b'];
        $st_dl = $s['dl_b'];
        if ($s['pct'] >= 99.9 && $st_total > 0) {
            $st_dl = max($st_dl, $st_total);
        }
        $total_bytes += $st_total;
        $dl_bytes += $st_dl;
    }

    $calc_pct = 0.0;
    if ($total_bytes > 0) {
        $raw_ratio = ($dl_bytes / $total_bytes);
        if (count($streams) === 1) {
            if ($is_multi_stream) {
                // 首个主流下载阶段：为次级流平滑接续预留空间，上限保留在 92.5%，杜绝主流冲顶导致次级流全程被 max() 冻结
                $calc_pct = min(92.5, round($raw_ratio * 92.5, 1));
            } else {
                // 原生单流模式（如纯音频任务或单流视频）：无后续次级流，平滑递增至 99.8%
                $calc_pct = min(99.8, round($raw_ratio * 100, 1));
            }
        } else {
            // 双流/多流阶段：基于真实汇总大小与已下载量综合计算真实进度
            $calc_pct = min(99.8, round($raw_ratio * 100, 1));
        }
        $calc_pct = max(0.1, $calc_pct);
    }

    return [
        'is_merging' => $is_merging,
        'target_file' => $target_file,
        'dest_file' => $latest_media_dest,
        'total_size' => $total_bytes > 0 ? format_bytes($total_bytes) : '',
        'downloaded' => $dl_bytes > 0 ? format_bytes($dl_bytes) : '',
        'total_bytes' => $total_bytes,
        'downloaded_bytes' => $dl_bytes,
        'progress' => $calc_pct,
        'speed' => $last_speed,
        'eta' => $last_eta
    ];
}

// 1. 扫描与更新正在运行的任务
foreach ($tasks as $idx => &$task) {
    $task_id = $task['id'] ?? '';
    if (empty($task_id)) continue;

    $task_dir = LOGS_DIR . '/tasks/' . $task_id;
    $status = $task['status'] ?? 'pending';

    if ($status === 'downloading' || $status === 'merging') {
        $pid = intval($task['pid'] ?? 0);
        if ($pid <= 0 && file_exists($task_dir . '/worker.pid')) {
            $pid = intval(trim(@file_get_contents($task_dir . '/worker.pid')));
            if ($pid > 0) $task['pid'] = $pid;
        }
        $is_alive = is_process_running($pid);

        if ($is_alive) {
            $active_count++;
            $output_log = $task_dir . '/output.log';
            if (file_exists($output_log)) {
                $is_multi = empty($task['is_audio_only']) && (empty($task['format_id']) || strpos($task['format_id'], '+') !== false || strpos($task['format_id'], 'bestvideo') !== false);
                $st_info = inspect_running_task_streams($output_log, $is_multi);
                if ($st_info) {
                    if ($st_info['is_merging']) {
                        $task['status'] = 'merging';
                        $task['progress'] = 99.8;
                        $task['speed'] = '音画转码合并中';
                        $task['eta'] = '处理中';
                        if (!empty($st_info['total_size'])) {
                            $task['total_size'] = $st_info['total_size'];
                            $task['downloaded'] = $st_info['total_size'];
                        }
                        if (!empty($st_info['target_file'])) {
                            $task['target_file'] = $st_info['target_file'];
                            if (strpos($task['title'], '批量任务') !== false || strpos($task['title'], '批量下载任务') !== false) {
                                $fname = basename(trim($st_info['target_file']));
                                $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                                if (!empty($clean_t)) {
                                    $plat = $task['platform'] ?: '网络媒体';
                                    $clean_no_plat = clean_platform_prefix($clean_t, $plat);
                                    $spec = $task['media_spec'] ?: '';
                                    $task['title'] = !empty($spec) ? "{$plat} - {$clean_no_plat} - {$spec}" : "{$plat} - {$clean_no_plat}";
                                }
                            }
                        }
                    } else {
                        // 正在下载中（单流或多音视频流累计）
                        if ($st_info['progress'] > 0) {
                            $prev_pct = floatval($task['progress'] ?? 0);
                            $task['progress'] = max($prev_pct, $st_info['progress']);
                        }
                        if (!empty($st_info['speed'])) {
                            $task['speed'] = $st_info['speed'];
                            $task['final_speed'] = $st_info['speed'];
                        }
                        if (!empty($st_info['eta'])) {
                            $task['eta'] = $st_info['eta'];
                        }
                        if (!empty($st_info['total_size'])) {
                            $task['total_size'] = $st_info['total_size'];
                        }
                        if (!empty($st_info['downloaded'])) {
                            $task['downloaded'] = $st_info['downloaded'];
                        }
                        if (!empty($st_info['dest_file'])) {
                            $task['dest_file'] = $st_info['dest_file'];
                            if (strpos($task['title'], '批量任务') !== false || strpos($task['title'], '批量下载任务') !== false) {
                                $fname = basename(trim($st_info['dest_file']));
                                $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                                if (!empty($clean_t)) {
                                    $plat = $task['platform'] ?: '网络媒体';
                                    $clean_no_plat = clean_platform_prefix($clean_t, $plat);
                                    $spec = $task['media_spec'] ?: '';
                                    $task['title'] = !empty($spec) ? "{$plat} - {$clean_no_plat} - {$spec}" : "{$plat} - {$clean_no_plat}";
                                }
                            }
                        }
                    }
                    $task['updated_at'] = $now;
                    $tasks_updated = true;
                }
            }
        } else {
            // 进程已退出，检查退出状态码
            $exit_code_file = $task_dir . '/exit_code';
            $exit_code = file_exists($exit_code_file) ? trim(file_get_contents($exit_code_file)) : '-1';
            $output_log = $task_dir . '/output.log';

            if ($exit_code === '0') {
                $start_time = intval($task['started_at'] ?? $task['created_at'] ?? $now);
                $elapsed = max(1, $now - $start_time);
                $m = floor($elapsed / 60);
                $s = $elapsed % 60;
                $cost_str = ($m > 0) ? "{$m}分{$s}秒" : "{$s}秒";

                // 深度扫描输出日志获取最终产物路径与元数据
                $meta = inspect_task_log($output_log);
                $target_file = $meta['target_file'] ?: ($task['target_file'] ?? $meta['dest_file'] ?? $task['dest_file'] ?? '');
                if (!empty($target_file) && (strpos($task['title'], '批量任务') !== false || strpos($task['title'], '批量下载任务') !== false)) {
                    $fname = basename($target_file);
                    $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                    if (!empty($clean_t)) {
                        $plat = $task['platform'] ?: '网络媒体';
                        $clean_no_plat = clean_platform_prefix($clean_t, $plat);
                        $spec = $task['media_spec'] ?: '';
                        $task['title'] = !empty($spec) ? "{$plat} - {$clean_no_plat} - {$spec}" : "{$plat} - {$clean_no_plat}";
                    }
                }

                // 优先探测最终物理落盘文件大小
                $final_bytes = 0;
                if (!empty($target_file) && file_exists($target_file)) {
                    $final_bytes = @filesize($target_file);
                }

                // 若未直接命中，尝试在下载目录中搜索最近 5 分钟内生成的文件
                if ($final_bytes <= 0 && !empty($task['download_dir']) && is_dir($task['download_dir'])) {
                    $dir_files = @scandir($task['download_dir']);
                    if ($dir_files) {
                        foreach ($dir_files as $df) {
                            if ($df === '.' || $df === '..' || substr($df, -5) === '.part') continue;
                            $candidate = rtrim($task['download_dir'], '/') . '/' . $df;
                            if (is_file($candidate) && ($now - filemtime($candidate) < 300)) {
                                $final_bytes = @filesize($candidate);
                                $target_file = $candidate;
                                break;
                            }
                        }
                    }
                }

                // 格式化落盘大小
                if ($final_bytes > 0) {
                    $task['total_size'] = format_bytes($final_bytes);
                    $task['downloaded'] = $task['total_size'];
                    $task['target_file'] = $target_file;
                } else {
                    $task['total_size'] = $meta['total_size'] ?: ($task['total_size'] ?? $meta['last_downloaded'] ?? $task['downloaded'] ?? '完整');
                    if (empty($task['downloaded']) || $task['downloaded'] === '0 B') {
                        $task['downloaded'] = $task['total_size'];
                    }
                }

                // 计算全局平均下载/处理速率
                $final_spd = '';
                if ($final_bytes > 0 && $elapsed > 0) {
                    $avg_speed_val = $final_bytes / $elapsed;
                    $final_spd = format_bytes($avg_speed_val) . '/s';
                } elseif (!empty($meta['final_speed'])) {
                    $final_spd = $meta['final_speed'];
                } elseif (!empty($task['final_speed']) && $task['final_speed'] !== '--' && stripos($task['final_speed'], 'unknown') === false) {
                    $final_spd = $task['final_speed'];
                } else {
                    $final_spd = '已完成';
                }

                $task['status'] = 'completed';
                $task['progress'] = 100;
                $task['eta'] = '00:00';
                $task['completed_at'] = $now;
                $task['elapsed_time'] = $elapsed;
                $task['time_cost_str'] = $cost_str;
                $task['speed'] = $final_spd;
                $task['final_speed'] = $final_spd;

                @file_put_contents(CONF_DIR . '/logs/daemon.log', "[" . date('Y-m-d H:i:s') . "] [SUCCESS] 任务 [{$task_id}] 下载并处理完成 (大小: {$task['total_size']}, 耗时: {$cost_str}, 平均速率: {$final_spd}): {$task['title']}\n", FILE_APPEND | LOCK_EX);
            } else {
                $task['status'] = 'failed';
                $task['speed'] = '--';
                // 提取错误日志
                $err_snippet = 'Execution failed with code ' . $exit_code;
                if (file_exists($output_log)) {
                    $lines = array_slice(file($output_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -6);
                    $err_snippet = implode("\n", $lines);
                }
                $task['error_message'] = $err_snippet;
                @file_put_contents(CONF_DIR . '/logs/daemon.log', "[" . date('Y-m-d H:i:s') . "] [FAILED] 任务 [{$task_id}] 异常退出 (退出码: {$exit_code})\n", FILE_APPEND | LOCK_EX);
            }
            $task['pid'] = 0;
            $task['updated_at'] = $now;
            $tasks_updated = true;
        }
    }
}
unset($task);

// 2. 调度排队中的任务 (pending)
if ($active_count < $max_concurrent) {
    foreach ($tasks as $idx => &$task) {
        if (($task['status'] ?? '') === 'pending') {
            $task_id = $task['id'];
            $task_dir = LOGS_DIR . '/tasks/' . $task_id;
            if (!is_dir($task_dir)) @mkdir($task_dir, 0755, true);

            // 严防重复派发：检测该任务是否已存在正在运行的 worker
            $pid_file = $task_dir . '/worker.pid';
            if (file_exists($pid_file)) {
                $running_pid = intval(trim(@file_get_contents($pid_file)));
                if ($running_pid > 0 && is_process_running($running_pid)) {
                    // 已有活跃 worker 在跑该任务，纠正状态为 downloading，严禁重复启动
                    $task['status'] = 'downloading';
                    $task['pid'] = $running_pid;
                    $tasks_updated = true;
                    $active_count++;
                    continue;
                }
            }

            // 保存单个任务元数据
            $task_json_file = $task_dir . '/task.json';
            atomic_write_file($task_json_file, json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $worker_script = __DIR__ . '/worker.sh';
            $cmd = "/bin/sh " . escapeshellarg($worker_script) . " " . escapeshellarg($task_id) . " " . escapeshellarg($task_json_file) . " </dev/null >/dev/null 2>&1 & echo $!";
            $worker_pid = trim((string)shell_exec($cmd));

            $task['status'] = 'downloading';
            $task['pid'] = intval($worker_pid);
            $task['started_at'] = $now;
            $task['updated_at'] = $now;
            $tasks_updated = true;
            $active_count++;

            // 派发后立即持久化更新，封闭并发时序空隙
            save_tasks($tasks);

            @file_put_contents(CONF_DIR . '/logs/daemon.log', "[" . date('Y-m-d H:i:s') . "] 调度器自动派发任务 [{$task_id}] (PID: {$worker_pid}, 标题: {$task['title']})\n", FILE_APPEND | LOCK_EX);

            if ($active_count >= $max_concurrent) {
                break;
            }
        }
    }
    unset($task);
}

if ($tasks_updated) {
    save_tasks($tasks);
}

flock($fp, LOCK_UN);
fclose($fp);
