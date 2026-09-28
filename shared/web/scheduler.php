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
        $is_alive = false;

        if ($pid > 0) {
            if (file_exists("/proc/$pid")) {
                $is_alive = true;
            } else {
                // macOS / POSIX fallback for testing
                $output = shell_exec("kill -0 $pid 2>&1");
                if (empty($output)) {
                    $is_alive = true;
                }
            }
        }

        if ($is_alive) {
            $active_count++;
            // 尝试读取实时进度输出
            $output_log = $task_dir . '/output.log';
            if (file_exists($output_log)) {
                // 读取最后 10 行提取进度
                $lines = array_slice(file($output_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -10);
                foreach (array_reverse($lines) as $line) {
                    if (strpos($line, 'download:') === 0) {
                        $parts = explode('|', substr($line, 9));
                        if (count($parts) >= 3) {
                            $percent_raw = trim($parts[0]);
                            $task['progress'] = floatval(str_replace('%', '', $percent_raw));
                            $task['speed'] = trim($parts[1]);
                            $task['eta'] = trim($parts[2]);
                            if (isset($parts[3])) $task['downloaded'] = trim($parts[3]);
                            if (isset($parts[4])) $task['total_size'] = trim($parts[4]);
                            if (!empty($task['speed']) && $task['speed'] !== '--' && stripos($task['speed'], 'unknown') === false) {
                                $task['final_speed'] = $task['speed'];
                            }
                            $task['updated_at'] = $now;
                            $tasks_updated = true;
                            break;
                        }
                    } elseif (stripos($line, '[Merger]') !== false || stripos($line, 'Merging formats') !== false) {
                        $task['status'] = 'merging';
                        $tasks_updated = true;
                    }
                }
            }
        } else {
            // 进程已退出，检查退出状态码
            $exit_code_file = $task_dir . '/exit_code';
            $exit_code = file_exists($exit_code_file) ? trim(file_get_contents($exit_code_file)) : '-1';

            if ($exit_code === '0') {
                $start_time = intval($task['started_at'] ?? $task['created_at'] ?? $now);
                $elapsed = max(1, $now - $start_time);
                $m = floor($elapsed / 60);
                $s = $elapsed % 60;
                $cost_str = ($m > 0) ? "{$m}分{$s}秒" : "{$s}秒";

                $task['status'] = 'completed';
                $task['progress'] = 100;
                $task['eta'] = '00:00';
                $task['completed_at'] = $now;
                $task['elapsed_time'] = $elapsed;
                $task['time_cost_str'] = $cost_str;

                $final_spd = $task['final_speed'] ?? $task['speed'] ?? '';
                if (empty($final_spd) || $final_spd === '--' || stripos($final_spd, 'unknown') !== false) {
                    $final_spd = '已完成';
                }
                $task['speed'] = $final_spd;
                $task['final_speed'] = $final_spd;

                @file_put_contents(CONF_DIR . '/logs/daemon.log', "[" . date('Y-m-d H:i:s') . "] [SUCCESS] 任务 [{$task_id}] 下载并处理完成 (耗时: {$cost_str}, 速率: {$final_spd}): {$task['title']}\n", FILE_APPEND | LOCK_EX);
            } else {
                $task['status'] = 'failed';
                $task['speed'] = '--';
                // 提取错误日志
                $output_log = $task_dir . '/output.log';
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
