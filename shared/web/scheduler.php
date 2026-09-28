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
            // 尝试读取实时进度输出
            $output_log = $task_dir . '/output.log';
            if (file_exists($output_log)) {
                // 读取最后 25 行提取进度与合并状态
                $lines = array_slice(file($output_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -25);
                $found_progress = false;
                foreach (array_reverse($lines) as $line) {
                    if (!$found_progress) {
                        $parsed = parse_ytdlp_log_line($line);
                        if ($parsed) {
                            if (isset($parsed['progress']) && $parsed['progress'] > 0) {
                                $task['progress'] = $parsed['progress'];
                            }
                            if (!empty($parsed['speed'])) {
                                $task['speed'] = $parsed['speed'];
                                $task['final_speed'] = $parsed['speed'];
                            }
                            if (!empty($parsed['eta'])) {
                                $task['eta'] = $parsed['eta'];
                            }
                            if (!empty($parsed['downloaded'])) {
                                $task['downloaded'] = $parsed['downloaded'];
                            }
                            if (!empty($parsed['total_size'])) {
                                $task['total_size'] = $parsed['total_size'];
                            }
                            $task['updated_at'] = $now;
                            $tasks_updated = true;
                            $found_progress = true;
                        }
                    }
                    if (preg_match('/Merging formats into [\"\x27]([^\x27\"]+)[\"\x27]/i', $line, $m)) {
                        $task['status'] = 'merging';
                        $task['target_file'] = $m[1];
                        $tasks_updated = true;
                        if (strpos($task['title'], '批量下载任务 - ') === 0) {
                            $fname = basename(trim($m[1]));
                            $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                            if (!empty($clean_t)) {
                                $task['title'] = $clean_t;
                            }
                        }
                    } elseif (preg_match('/\[download\] Destination:\s*(.+)$/i', $line, $m)) {
                        $task['dest_file'] = trim($m[1]);
                        if (strpos($task['title'], '批量下载任务 - ') === 0) {
                            $fname = basename(trim($m[1]));
                            $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                            if (!empty($clean_t)) {
                                $task['title'] = $clean_t;
                                $tasks_updated = true;
                            }
                        }
                    }
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
                if (!empty($target_file) && strpos($task['title'], '批量下载任务 - ') === 0) {
                    $fname = basename($target_file);
                    $clean_t = preg_replace('/(\.f[0-9]+)?\.[a-zA-Z0-9]+$/', '', $fname);
                    if (!empty($clean_t)) {
                        $task['title'] = $clean_t;
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
