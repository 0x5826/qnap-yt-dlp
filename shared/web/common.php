<?php
// QNAP yt-dlp Common Utilities & Atomic Storage
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$detected_base = dirname(__DIR__);
if (file_exists('/sbin/getcfg')) {
    $qpkg_path = trim((string)shell_exec('/sbin/getcfg ytdlp Install_Path -f /etc/config/qpkg.conf 2>/dev/null'));
    if (!empty($qpkg_path) && is_dir($qpkg_path)) {
        $detected_base = $qpkg_path;
    }
}
define('BASE_DIR', $detected_base);
define('CONF_DIR', BASE_DIR . '/conf');
define('LOGS_DIR', CONF_DIR . '/logs');
define('TASKS_FILE', CONF_DIR . '/tasks.json');
define('CONFIG_FILE', CONF_DIR . '/config.json');
define('COOKIES_FILE', CONF_DIR . '/cookies.txt');
define('PID_FILE', CONF_DIR . '/ytdlp_daemon.pid');
define('BIN_DIR', BASE_DIR . '/bin');
define('CUSTOM_TMP_DIR', CONF_DIR . '/tmp');

// 关键规避：重定向 TMPDIR 至数据盘，彻底免疫 QTS 系统 /tmp 64MB 内存盘满载崩溃
if (!is_dir(CONF_DIR)) @mkdir(CONF_DIR, 0755, true);
if (!is_dir(LOGS_DIR)) @mkdir(LOGS_DIR, 0755, true);
if (!is_dir(CUSTOM_TMP_DIR)) @mkdir(CUSTOM_TMP_DIR, 0777, true);
putenv("TMPDIR=" . CUSTOM_TMP_DIR);
$_ENV['TMPDIR'] = CUSTOM_TMP_DIR;

/**
 * QTS 官方登录会话继承鉴权
 */
function checkQnapSession() {
    // 环境变量或调试模式白名单
    if (getenv('YTDLP_DEV_MODE') === 'true' || getenv('EASYTIER_DEV_MODE') === 'true') {
        return true;
    }

    // 本地 CLI 运行或非 Web 环境豁免
    if (php_sapi_name() === 'cli') {
        return true;
    }

    // 本地回环免鉴权（支持本地开发预览与内部 curl）
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '127.0.0.1' || $remote === '::1') {
        return true;
    }

    if (!empty($_SESSION['qnap_sid']) && !empty($_SESSION['qnap_auth_time'])) {
        if ((time() - $_SESSION['qnap_auth_time']) < 300) {
            return true;
        }
    }

    $sid = $_COOKIE['NAS_SID'] ?? $_GET['sid'] ?? $_POST['sid'] ?? '';
    if (empty($sid)) {
        foreach ($_COOKIE as $k => $v) {
            if (stripos($k, 'sid') !== false && !empty($v)) {
                $sid = $v;
                break;
            }
        }
    }

    if (empty($sid)) {
        return false;
    }

    $cleanSid = urlencode(trim($sid));
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true
        ]
    ]);

    foreach (['http://127.0.0.1:58080', 'http://127.0.0.1:5000', 'http://127.0.0.1:8080', 'http://127.0.0.1:80'] as $host) {
        $url = "$host/cgi-bin/authLogin.cgi?sid=$cleanSid";
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp && (strpos($resp, '<authPassed><![CDATA[1]]></authPassed>') !== false || strpos($resp, '<authPassed>1</authPassed>') !== false)) {
            $_SESSION['qnap_sid'] = $sid;
            $_SESSION['qnap_auth_time'] = time();
            return true;
        }
    }

    if (file_exists('/sbin/qweb')) {
        $cmd = "/sbin/qweb sid_check " . escapeshellarg($sid) . " 2>/dev/null";
        $result = @shell_exec($cmd);
        if ($result !== null && strpos($result, 'OK') !== false) {
            $_SESSION['qnap_sid'] = $sid;
            $_SESSION['qnap_auth_time'] = time();
            return true;
        }
    }

    return false;
}

/**
 * 工业级原子写入文件，防止高并发或断电截断
 */
function atomic_write_file($file_path, $content, $perms = 0666) {
    $dir = dirname($file_path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp_file = $file_path . '.' . uniqid('tmp_', true);
    $written = @file_put_contents($tmp_file, $content, LOCK_EX);
    if ($written !== false) {
        @chmod($tmp_file, $perms);
        if (@rename($tmp_file, $file_path)) {
            return true;
        }
        @unlink($tmp_file);
    }
    return false;
}

/**
 * 读取全局配置
 */
function get_app_config() {
    $default = [
        'autostart' => 1,
        'download_dir' => '/share/Download',
        'filename_template' => '%(title)s [%(id)s].%(ext)s',
        'max_concurrent_tasks' => 2,
        'rate_limit' => '',
        'proxy' => '',
        'embed_thumbnail' => true,
        'embed_metadata' => true,
        'default_video_quality' => 'bestvideo+bestaudio/best',
        'default_container' => 'mp4',
        'default_subtitles' => 'none',
        'custom_args' => ''
    ];

    if (file_exists(CONFIG_FILE)) {
        $content = @file_get_contents(CONFIG_FILE);
        $data = json_decode($content, true);
        if (is_array($data)) {
            return array_merge($default, $data);
        }
    }
    return $default;
}

/**
 * 保存全局配置
 */
function save_app_config($data) {
    $cur = get_app_config();
    $merged = array_merge($cur, $data);
    return atomic_write_file(CONFIG_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * 读取任务列表
 */
function get_tasks() {
    if (file_exists(TASKS_FILE)) {
        $content = @file_get_contents(TASKS_FILE);
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['tasks'])) {
            return $data['tasks'];
        }
    }
    return [];
}

/**
 * 保存任务列表
 */
function save_tasks($tasks) {
    $payload = [
        'updated_at' => time(),
        'tasks' => array_values($tasks)
    ];
    return atomic_write_file(TASKS_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取可执行文件路径
 */
function get_binary_path($name) {
    $arch = (php_uname('m') === 'aarch64' || php_uname('m') === 'arm64') ? 'arm_64' : 'x86_64';
    $candidates = [
        BIN_DIR . '/' . $name,
        BASE_DIR . '/' . $name,
        BASE_DIR . '/' . $arch . '/' . $name,
        '/usr/bin/' . $name,
        '/usr/local/bin/' . $name,
        '/opt/bin/' . $name
    ];

    foreach ($candidates as $bin) {
        if (file_exists($bin)) {
            @chmod($bin, 0755);
            if (is_executable($bin)) {
                return $bin;
            }
        }
    }

    $which = trim((string)shell_exec("which " . escapeshellarg($name) . " 2>/dev/null"));
    if (!empty($which) && file_exists($which)) {
        @chmod($which, 0755);
        if (is_executable($which)) {
            return $which;
        }
    }
    return false;
}

/**
 * 格式化存储大小
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * JSON 返回包装
 */
function json_response($data, $code = 200) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 动态获取系统 PHP CLI 解释器路径
 */
function get_php_binary() {
    $candidates = [
        BIN_DIR . '/php',
        '/mnt/ext/opt/apache/bin/php',
        '/mnt/ext/opt/apache/links/php',
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/opt/bin/php'
    ];
    foreach ($candidates as $p) {
        if (file_exists($p) && is_executable($p)) {
            return $p;
        }
    }
    $which = trim((string)shell_exec("which php 2>/dev/null"));
    if (!empty($which) && file_exists($which) && is_executable($which)) {
        return $which;
    }
    return 'php';
}

/**
 * 健壮探测目录所在磁盘卷的容量与可用空间
 * 支持子目录尚未创建时的自愈与祖先挂载点回溯探测，杜绝 0 B 假死
 */
function get_dir_disk_space($path) {
    $target = rtrim(trim($path ?: ''), '/');
    if (empty($target)) {
        $target = '/share';
    }

    // 1. 若路径已存在且可读，直接获取
    if (file_exists($target)) {
        $free = @disk_free_space($target);
        $total = @disk_total_space($target);
        if ($free !== false && $total !== false && $total > 0) {
            return [$free, $total];
        }
    }

    // 2. 若不存在，尝试自动创建
    if (@mkdir($target, 0777, true)) {
        $free = @disk_free_space($target);
        $total = @disk_total_space($target);
        if ($free !== false && $total !== false && $total > 0) {
            return [$free, $total];
        }
    }

    // 3. 逐级向上回溯存在的父级共享卷或挂载点
    $curr = $target;
    while ($curr && $curr !== '/' && $curr !== '.') {
        $curr = dirname($curr);
        if (file_exists($curr)) {
            $free = @disk_free_space($curr);
            $total = @disk_total_space($curr);
            if ($free !== false && $total !== false && $total > 0) {
                return [$free, $total];
            }
        }
    }

    // 4. Fallback 探测常见 QNAP 挂载卷
    $fallbacks = ['/share/Download', '/share/CACHEDEV1_DATA', '/share/CACHEDEV2_DATA', '/share/Public', '/share', '/'];
    foreach ($fallbacks as $fb) {
        if (file_exists($fb)) {
            $free = @disk_free_space($fb);
            $total = @disk_total_space($fb);
            if ($free !== false && $total !== false && $total > 0) {
                return [$free, $total];
            }
        }
    }

    return [0, 0];
}

/**
 * 事件驱动按需调度器唤醒
 * 异步执行 scheduler.php tick，不阻塞当前请求，实现零常驻后台开箱即用
 * 严禁依赖 nohup（QNAP 系统默认不存在 nohup）
 */
function trigger_scheduler_tick() {
    $script = __DIR__ . '/scheduler.php';
    if (file_exists($script)) {
        $php = get_php_binary();
        $cmd = escapeshellarg($php) . " " . escapeshellarg($script) . " tick </dev/null >/dev/null 2>&1 &";
        @shell_exec($cmd);
    }
}

/**
 * 解析单行下载日志，提取进度、速率、剩余时间与大小
 * 全面兼容：YTDLP_PROGRESS 前缀、download 前缀、以及无前缀的纯管道符百分比行
 */
function parse_ytdlp_log_line($line) {
    $line = trim($line);
    if (empty($line)) return null;

    $raw = '';
    if (strpos($line, 'YTDLP_PROGRESS:') !== false) {
        $raw = substr($line, strpos($line, 'YTDLP_PROGRESS:') + 15);
    } elseif (strpos($line, 'download:') === 0) {
        $raw = substr($line, 9);
    } elseif (preg_match('/^([\d\.]+)%\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)/', $line, $m)) {
        $raw = "{$m[1]}%|{$m[2]}|{$m[3]}|{$m[4]}|{$m[5]}";
    }

    if (!empty($raw)) {
        $parts = explode('|', $raw);
        if (count($parts) >= 3) {
            $pct = floatval(str_replace('%', '', trim($parts[0])));
            $spd = trim($parts[1]);
            $eta = trim($parts[2]);
            $dl = isset($parts[3]) ? trim($parts[3]) : '';
            $sz = isset($parts[4]) ? trim($parts[4]) : '';

            $res = ['progress' => $pct];
            if (!empty($spd) && $spd !== '--' && $spd !== 'NA' && stripos($spd, 'unknown') === false) {
                $res['speed'] = $spd;
            }
            if (!empty($eta) && $eta !== 'NA' && stripos($eta, 'unknown') === false) {
                $res['eta'] = $eta;
            }
            if (!empty($dl) && $dl !== 'NA') {
                $res['downloaded'] = $dl;
            }
            if (!empty($sz) && $sz !== 'NA' && stripos($sz, 'unknown') === false) {
                $res['total_size'] = $sz;
            }
            return $res;
        }
    }

    return null;
}

/**
 * 将带单位的大小字符串 (例如 181.66MiB, 15.00KiB) 解析为浮点字节数
 */
function parse_size_str($str) {
    $str = trim(str_ireplace('iB', 'B', $str ?: ''));
    if (preg_match('/^([\d\.]+)\s*([KMGTP]?B?)$/i', $str, $m)) {
        $val = floatval($m[1]);
        $unit = strtoupper($m[2]);
        if (strpos($unit, 'K') !== false) return $val * 1024;
        if (strpos($unit, 'M') !== false) return $val * 1024 * 1024;
        if (strpos($unit, 'G') !== false) return $val * 1024 * 1024 * 1024;
        if (strpos($unit, 'T') !== false) return $val * 1024 * 1024 * 1024 * 1024;
        return $val;
    }
    return 0;
}

/**
 * 从任务 output.log 深度提取关键元数据 (目标生成文件、总大小、最终速率)
 * 支持音视频双流 (分段音视频) 下载时各流大小的自动聚合累加
 */
function inspect_task_log($output_log) {
    if (!file_exists($output_log)) return [];

    $lines = file($output_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $info = [
        'target_file' => '',
        'dest_file' => '',
        'total_size' => '',
        'final_speed' => '',
        'last_downloaded' => '',
        'is_merging' => false
    ];

    $stream_sizes = [];
    $current_dest = 'main';

    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/Merging formats into [\"\x27]([^\x27\"]+)[\"\x27]/i', $line, $m)) {
            $info['target_file'] = $m[1];
            $info['is_merging'] = true;
        } elseif (preg_match('/\[download\] Destination:\s*(.+)$/i', $line, $m)) {
            $current_dest = trim($m[1]);
            if (empty($info['dest_file'])) $info['dest_file'] = $current_dest;
        } elseif (stripos($line, '[Merger]') !== false || stripos($line, 'Merging formats') !== false) {
            $info['is_merging'] = true;
        }

        $parsed = parse_ytdlp_log_line($line);
        if ($parsed) {
            if (!empty($parsed['speed'])) {
                $info['final_speed'] = $parsed['speed'];
            }
            if (!empty($parsed['total_size'])) {
                $sz_bytes = parse_size_str($parsed['total_size']);
                if ($sz_bytes > 0) {
                    $stream_sizes[$current_dest] = max($stream_sizes[$current_dest] ?? 0, $sz_bytes);
                }
            }
            if (!empty($parsed['downloaded'])) {
                $info['last_downloaded'] = $parsed['downloaded'];
            }
        }
    }

    // 优先采用各分段流的最大汇总字节数
    if (!empty($stream_sizes)) {
        $total_sum = array_sum($stream_sizes);
        if ($total_sum > 0) {
            $info['total_size'] = format_bytes($total_sum);
        }
    }

    return $info;
}


