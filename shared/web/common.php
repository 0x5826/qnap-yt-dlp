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

// 确保核心目录完整存在
if (!is_dir(CONF_DIR)) @mkdir(CONF_DIR, 0755, true);
if (!is_dir(LOGS_DIR)) @mkdir(LOGS_DIR, 0755, true);

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
        'download_dir' => '/share/Download/yt-dlp',
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
    $local_bin = BIN_DIR . '/' . $name;
    if (file_exists($local_bin) && is_executable($local_bin)) {
        return $local_bin;
    }
    $which = trim((string)shell_exec("which " . escapeshellarg($name) . " 2>/dev/null"));
    if (!empty($which) && file_exists($which)) {
        return $which;
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
