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

// 强制初始化 UTF-8 语言环境，规避 Linux/QNAP C locale 下 escapeshellarg 吞噬中文文件名的致命缺陷
@setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8', 'zh_CN.UTF-8', 'UTF-8');
@setlocale(LC_ALL, 'en_US.UTF-8', 'C.UTF-8', 'zh_CN.UTF-8', 'UTF-8');
putenv('LC_ALL=en_US.UTF-8');
putenv('LANG=en_US.UTF-8');

/**
 * 免受操作系统 C locale 污染的安全命令行参数转义
 * 严格按照 POSIX 单引号替换，百分之百保留 UTF-8 多字节中文路径
 */
function safe_escapeshellarg($arg) {
    if ($arg === '' || $arg === null) return "''";
    return "'" . str_replace("'", "'\\''", (string)$arg) . "'";
}

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
        'filename_template' => '%(extractor_key)s-%(title)s [%(id)s].%(ext)s',
        'max_concurrent_tasks' => 2,
        'rate_limit' => '',
        'proxy' => '',
        'embed_thumbnail' => true,
        'embed_metadata' => true,
        'default_video_quality' => 'bestvideo+bestaudio/best',
        'default_container' => 'mp4',
        'default_subtitles' => 'all',
        'custom_args' => ''
    ];

    if (file_exists(CONFIG_FILE)) {
        $content = @file_get_contents(CONFIG_FILE);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $merged = array_merge($default, $data);
            if (($merged['filename_template'] ?? '') === '%(title)s [%(id)s].%(ext)s') {
                $merged['filename_template'] = '%(extractor_key)s-%(title)s [%(id)s].%(ext)s';
            }
            return $merged;
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
        '/share/CACHEDEV1_DATA/.qpkg/ytdlp/bin/' . $name,
        '/share/CACHEDEV1_DATA/.qpkg/ytdlp/' . $arch . '/' . $name,
        '/share/CACHEDEV1_DATA/.qpkg/ytdlp/' . $name,
        '/share/CACHEDEV1_DATA/.qpkg/ffmpeg/bin/' . $name,
        '/share/CACHEDEV1_DATA/.qpkg/ffmpeg/' . $name,
        '/share/CACHEDEV1_DATA/.qpkg/CodexPack/opt/ffmpeg/' . $name,
        '/mnt/ext/opt/medialibrary/bin/' . $name,
        '/mnt/ext/opt/ffmpeg/' . $name,
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
 * 判断指定 PID 进程是否存活
 */
function is_process_running($pid) {
    $pid = intval($pid);
    if ($pid <= 0) return false;
    if (file_exists("/proc/$pid")) return true;
    $output = shell_exec("kill -0 $pid 2>&1");
    return empty($output);
}

/**
 * 可靠终止指定 PID 及其全部子进程树 (yt-dlp, ffmpeg)
 */
function kill_process_tree($pid) {
    $pid = intval($pid);
    if ($pid <= 0) return;

    // 先发送 SIGTERM 让子进程和父进程优雅退出
    @shell_exec("pkill -P $pid 2>/dev/null; kill -15 $pid 2>/dev/null");
    usleep(150000); // 150ms 缓冲

    // 检查是否仍有残留，若未退出则升级为 SIGKILL 强杀
    if (is_process_running($pid)) {
        @shell_exec("pkill -9 -P $pid 2>/dev/null; kill -9 $pid 2>/dev/null");
    }
}

/**
 * 获取核心组件版本信息（带本地文件缓存，杜绝高频 shell_exec 频繁解压打爆系统 /tmp 内存盘）
 */
function get_cached_component_versions($force = false) {
    $cache_file = CONF_DIR . '/.component_versions.json';
    $now = time();
    if (!$force && file_exists($cache_file)) {
        $content = @file_get_contents($cache_file);
        $data = json_decode($content, true);
        if (is_array($data) && ($now - intval($data['cached_at'] ?? 0)) < 3600) {
            return $data;
        }
    }

    $ytdlp_bin = get_binary_path('yt-dlp');
    $ffmpeg_bin = get_binary_path('ffmpeg');

    $ytdlp_ver = '未就绪';
    if ($ytdlp_bin) {
        // 关键防护：命令前缀强制内联指定 TMPDIR 为数据盘，避免 PyInstaller 解压到 QTS 64MB /tmp
        $cmd = 'TMPDIR=' . escapeshellarg(CUSTOM_TMP_DIR) . ' ' . escapeshellarg($ytdlp_bin) . ' --version 2>/dev/null';
        $ver_out = shell_exec($cmd);
        if ($ver_out) $ytdlp_ver = trim($ver_out);
    }

    $ffmpeg_ver = '未就绪';
    $ffmpeg_source = '未安装';
    if ($ffmpeg_bin) {
        $real_path = @realpath($ffmpeg_bin) ?: $ffmpeg_bin;
        $is_external = is_link($ffmpeg_bin) || (strpos($real_path, BASE_DIR) !== 0);
        if ($is_external || strpos($real_path, 'MultimediaConsole') !== false || strpos($real_path, '/usr/') === 0 || strpos($real_path, '/mnt/') === 0 || strpos($real_path, '/opt/') === 0) {
            $ffmpeg_source = '系统原生';
        } else {
            $ffmpeg_source = '内置静态';
        }

        $ff_cmd = 'export LD_LIBRARY_PATH="/usr/lib:/usr/local/lib:/usr/local/medialibrary/lib:/opt/lib:$LD_LIBRARY_PATH"; ' . escapeshellarg($ffmpeg_bin) . ' -version 2>&1 | head -n 1';
        $ff_out = shell_exec($ff_cmd);
        if ($ff_out && preg_match('/version\s+([^\s]+)/i', $ff_out, $m)) {
            $ffmpeg_ver = $m[1];
        } elseif (!empty($ff_out)) {
            $ffmpeg_ver = '已安装';
        }
    }

    $result = [
        'cached_at' => $now,
        'ytdlp_version' => $ytdlp_ver,
        'ffmpeg_version' => $ffmpeg_ver,
        'ffmpeg_source' => $ffmpeg_source
    ];

    atomic_write_file($cache_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $result;
}

/**
 * 解析单行下载日志，提取进度、速率、剩余时间与大小
 * 全面兼容：YTDLP_PROGRESS 前缀、download 前缀、以及无前缀的纯管道符百分比行
 * 严防模板占位符穿透 (%(progress....)) 与命令行输出干扰
 */
function parse_ytdlp_log_line($line) {
    $line = trim($line);
    if (empty($line)) return null;

    // 严格过滤：若包含命令行标识或未替换的 Python 格式化占位符，坚决忽略
    if (stripos($line, 'Command:') !== false || strpos($line, '%(progress.') !== false || strpos($line, '%(') !== false) {
        return null;
    }

    $raw = '';
    if (strpos($line, 'YTDLP_PROGRESS:') !== false) {
        $raw = substr($line, strpos($line, 'YTDLP_PROGRESS:') + 15);
    } elseif (strpos($line, 'download:') === 0) {
        $raw = substr($line, 9);
    } elseif (preg_match('/^([\d\.]+)%\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)/', $line, $m)) {
        $raw = "{$m[1]}%|{$m[2]}|{$m[3]}|{$m[4]}|{$m[5]}";
    }

    if (!empty($raw)) {
        // 若提取出的内容仍含有模板特征字符串，直接废弃
        if (strpos($raw, '%(') !== false) return null;

        $parts = explode('|', $raw);
        if (count($parts) >= 3) {
            $raw_pct = trim(str_replace('%', '', $parts[0]));
            if (!is_numeric($raw_pct)) {
                return null;
            }
            $pct = floatval($raw_pct);
            $spd = trim($parts[1]);
            $eta = trim($parts[2]);
            $dl = isset($parts[3]) ? trim($parts[3]) : '';
            $sz = isset($parts[4]) ? trim($parts[4]) : '';

            // 防御二次检查：确保各个分段无非法模板占位符
            if (strpos($dl, '%(') !== false || strpos($sz, '%(') !== false || strpos($spd, '%(') !== false) {
                return null;
            }

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

/**
 * 智能提取媒体来源平台名称 (YouTube, Bilibili, 抖音, TikTok, Twitter/X 等)
 */
function get_media_platform_name($data, $url = '') {
    $raw = $data['extractor_key'] ?? $data['extractor'] ?? '';
    $raw_lower = strtolower($raw);
    if (strpos($raw_lower, 'youtube') !== false) return 'YouTube';
    if (strpos($raw_lower, 'bilibili') !== false) return 'Bilibili';
    if (strpos($raw_lower, 'tiktok') !== false) return 'TikTok';
    if (strpos($raw_lower, 'douyin') !== false) return '抖音';
    if (strpos($raw_lower, 'twitter') !== false || strpos($raw_lower, 'x') !== false) return 'Twitter/X';
    if (strpos($raw_lower, 'weibo') !== false) return '微博';
    if (strpos($raw_lower, 'kuaishou') !== false) return '快手';
    if (strpos($raw_lower, 'xiaohongshu') !== false) return '小红书';
    if (strpos($raw_lower, 'instagram') !== false) return 'Instagram';
    if (strpos($raw_lower, 'facebook') !== false) return 'Facebook';
    if (strpos($raw_lower, 'twitch') !== false) return 'Twitch';
    if (strpos($raw_lower, 'vimeo') !== false) return 'Vimeo';

    if (!empty($url)) {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                return ucfirst($parts[count($parts) - 2]);
            }
        }
    }
    return !empty($raw) ? ucfirst($raw) : 'WebMedia';
}

/**
 * 格式化秒数为人类易读时长
 */
function format_duration_human($seconds) {
    $seconds = intval($seconds);
    if ($seconds <= 0) return '--';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return "{$h}小时{$m}分{$s}秒";
    }
    if ($m > 0) {
        return "{$m}分{$s}秒";
    }
    return "{$s}秒";
}

/**
 * 使用 ffprobe 及 ffmpeg 双引擎获取音视频文件的详细结构化规格（分辨率、码率、编解码器、音轨、字幕等）
 */
function get_media_file_info($file_path) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return null;
    }

    $file_size = @filesize($file_path);
    $file_name = basename($file_path);
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    $res = [
        'file_name' => $file_name,
        'file_path' => $file_path,
        'file_size' => format_bytes($file_size),
        'file_size_bytes' => $file_size,
        'format_name' => strtoupper($ext),
        'duration_str' => '--',
        'duration_sec' => 0,
        'bit_rate_str' => '--',
        'video_streams' => [],
        'audio_streams' => [],
        'subtitle_streams' => []
    ];

    $tmp_dir = defined('CUSTOM_TMP_DIR') ? CUSTOM_TMP_DIR : '/tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $env_prefix = 'TMPDIR=' . escapeshellarg($tmp_dir) . ' ';

    // 引擎 1: ffprobe JSON 解析
    $ffprobe = get_binary_path('ffprobe');
    $probe_data = null;
    if ($ffprobe) {
        $cmd = $env_prefix . safe_escapeshellarg($ffprobe) . ' -v error -print_format json -show_format -show_streams ' . safe_escapeshellarg($file_path) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        if (!empty($output)) {
            $probe_data = json_decode($output, true);
        }
    }

    if ($probe_data && !empty($probe_data['format'])) {
        $fmt = $probe_data['format'];
        if (!empty($fmt['format_long_name'])) {
            $res['format_name'] = $fmt['format_long_name'];
        } elseif (!empty($fmt['format_name'])) {
            $res['format_name'] = strtoupper($fmt['format_name']);
        }
        if (!empty($fmt['duration'])) {
            $sec = floatval($fmt['duration']);
            $res['duration_sec'] = round($sec, 1);
            $res['duration_str'] = format_duration_human(intval($sec));
        }
        if (!empty($fmt['bit_rate'])) {
            $br = round(intval($fmt['bit_rate']) / 1000);
            $res['bit_rate_str'] = number_format($br) . ' kbps';
        }
    }

    if ($probe_data && !empty($probe_data['streams']) && is_array($probe_data['streams'])) {
        foreach ($probe_data['streams'] as $st) {
            $type = $st['codec_type'] ?? '';
            $idx = $st['index'] ?? 0;
            $codec = strtoupper($st['codec_name'] ?? '未知');
            $profile = $st['profile'] ?? '';
            $bit_rate = !empty($st['bit_rate']) ? number_format(round(intval($st['bit_rate']) / 1000)) . ' kbps' : '';

            if ($type === 'video') {
                $w = intval($st['width'] ?? 0);
                $h = intval($st['height'] ?? 0);
                $res_str = ($w > 0 && $h > 0) ? "{$w} × {$h}" : '--';

                $fps = '--';
                if (!empty($st['r_frame_rate']) && strpos($st['r_frame_rate'], '/') !== false) {
                    list($num, $den) = explode('/', $st['r_frame_rate']);
                    if (intval($den) > 0) {
                        $calc_fps = round(intval($num) / intval($den), 2);
                        if ($calc_fps > 0) $fps = $calc_fps . ' fps';
                    }
                } elseif (!empty($st['avg_frame_rate']) && strpos($st['avg_frame_rate'], '/') !== false) {
                    list($num, $den) = explode('/', $st['avg_frame_rate']);
                    if (intval($den) > 0) {
                        $calc_fps = round(intval($num) / intval($den), 2);
                        if ($calc_fps > 0) $fps = $calc_fps . ' fps';
                    }
                }

                $res['video_streams'][] = [
                    'index' => $idx,
                    'codec' => $codec . ($profile ? " ({$profile})" : ''),
                    'resolution' => $res_str,
                    'fps' => $fps,
                    'bit_rate' => $bit_rate ?: '--',
                    'pix_fmt' => $st['pix_fmt'] ?? '--',
                    'aspect_ratio' => $st['display_aspect_ratio'] ?? '--'
                ];
            } elseif ($type === 'audio') {
                $channels = intval($st['channels'] ?? 0);
                $chan_layout = $st['channel_layout'] ?? '';
                $chan_str = $channels > 0 ? "{$channels} 声道" : '--';
                if ($chan_layout) $chan_str .= " ({$chan_layout})";

                $sample_rate = !empty($st['sample_rate']) ? $st['sample_rate'] . ' Hz' : '--';
                $lang = $st['tags']['language'] ?? ($st['tags']['LANGUAGE'] ?? 'und');
                $title = $st['tags']['title'] ?? ($st['tags']['TITLE'] ?? '');

                $res['audio_streams'][] = [
                    'index' => $idx,
                    'codec' => $codec . ($profile ? " ({$profile})" : ''),
                    'channels' => $chan_str,
                    'sample_rate' => $sample_rate,
                    'bit_rate' => $bit_rate ?: '--',
                    'language' => $lang,
                    'title' => $title
                ];
            } elseif ($type === 'subtitle') {
                $lang = $st['tags']['language'] ?? ($st['tags']['LANGUAGE'] ?? 'und');
                $title = $st['tags']['title'] ?? ($st['tags']['TITLE'] ?? '');

                $res['subtitle_streams'][] = [
                    'index' => $idx,
                    'codec' => $codec,
                    'language' => $lang,
                    'title' => $title ?: '--'
                ];
            }
        }
    }

    // 引擎 2: 若 ffprobe 缺失或未能解析出有效流，自动回退调用 ffmpeg -i 深度解析
    if (empty($res['video_streams']) && empty($res['audio_streams'])) {
        $ffmpeg = get_binary_path('ffmpeg');
        if ($ffmpeg) {
            $cmd_ff = $env_prefix . safe_escapeshellarg($ffmpeg) . ' -i ' . safe_escapeshellarg($file_path) . ' 2>&1';
            $ff_out = shell_exec($cmd_ff);
            if (!empty($ff_out)) {
                if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/i', $ff_out, $dm)) {
                    $sec = intval($dm[1]) * 3600 + intval($dm[2]) * 60 + floatval($dm[3]);
                    $res['duration_sec'] = round($sec, 1);
                    $res['duration_str'] = format_duration_human(intval($sec));
                }
                if (preg_match('/bitrate:\s*(\d+)\s*kb\/s/i', $ff_out, $bm)) {
                    $res['bit_rate_str'] = number_format(intval($bm[1])) . ' kbps';
                }
                if (preg_match('/Input #0,\s*([^,]+),/i', $ff_out, $fm)) {
                    $res['format_name'] = strtoupper(trim($fm[1]));
                }

                $ff_lines = explode("\n", $ff_out);
                foreach ($ff_lines as $lidx => $line) {
                    $line_trim = trim($line);
                    if (preg_match('/Stream #(\d+:\d+)(?:\[0x[0-9a-fA-F]+\])?(?:\(([a-zA-Z0-9_-]+)\))?:\s*(Video|Audio|Subtitle):\s*(.*)/i', $line_trim, $sm)) {
                        $st_idx = $sm[1];
                        $st_lang = !empty($sm[2]) ? $sm[2] : 'und';
                        $st_type = strtolower($sm[3]);
                        $st_rest = $sm[4];

                        $st_title = '';
                        for ($ti = $lidx + 1; $ti < min(count($ff_lines), $lidx + 6); $ti++) {
                            if (strpos($ff_lines[$ti], 'Stream #') !== false) break;
                            if (preg_match('/^\s*title\s*:\s*(.+)$/i', $ff_lines[$ti], $tm)) {
                                $st_title = trim($tm[1]);
                                break;
                            }
                        }

                        $parts = explode(',', $st_rest);
                        $codec_raw = trim($parts[0] ?? '未知');

                        if ($st_type === 'video') {
                            $w_h = '--';
                            if (preg_match('/\b(\d{2,5}x\d{2,5})\b/', $st_rest, $whm)) {
                                $w_h = str_replace('x', ' × ', $whm[1]);
                            }
                            $fps_str = '--';
                            if (preg_match('/(\d+(?:\.\d+)?)\s*fps/i', $st_rest, $fpsm)) {
                                $fps_str = $fpsm[1] . ' fps';
                            }
                            $v_br = '--';
                            if (preg_match('/(\d+)\s*kb\/s/i', $st_rest, $brm)) {
                                $v_br = number_format(intval($brm[1])) . ' kbps';
                            }
                            $dar_str = '--';
                            if (preg_match('/DAR\s*(\d+:\d+)/i', $st_rest, $darm)) {
                                $dar_str = $darm[1];
                            }
                            $pix_str = '--';
                            if (preg_match('/\b(yuv\w+|rgb\w+|bgr\w+)\b/i', $st_rest, $pixm)) {
                                $pix_str = $pixm[1];
                            }

                            $res['video_streams'][] = [
                                'index' => $st_idx,
                                'codec' => strtoupper($codec_raw),
                                'resolution' => $w_h,
                                'fps' => $fps_str,
                                'bit_rate' => $v_br,
                                'pix_fmt' => $pix_str,
                                'aspect_ratio' => $dar_str
                            ];
                        } elseif ($st_type === 'audio') {
                            $sr_str = '--';
                            if (preg_match('/(\d+)\s*Hz/i', $st_rest, $srm)) {
                                $sr_str = $srm[1] . ' Hz';
                            }
                            $ch_str = '--';
                            if (preg_match('/\b(stereo|mono|5\.1\(side\)|5\.1|7\.1|\d+\s*channels?)\b/i', $st_rest, $chm)) {
                                $ch_raw = strtolower($chm[1]);
                                if ($ch_raw === 'stereo') $ch_str = '2 声道 (stereo)';
                                elseif ($ch_raw === 'mono') $ch_str = '1 声道 (mono)';
                                elseif (strpos($ch_raw, '5.1') !== false) $ch_str = '6 声道 (5.1 环绕声)';
                                else $ch_str = $ch_raw;
                            }
                            $a_br = '--';
                            if (preg_match('/(\d+)\s*kb\/s/i', $st_rest, $brm)) {
                                $a_br = number_format(intval($brm[1])) . ' kbps';
                            }

                            $res['audio_streams'][] = [
                                'index' => $st_idx,
                                'codec' => strtoupper($codec_raw),
                                'channels' => $ch_str,
                                'sample_rate' => $sr_str,
                                'bit_rate' => $a_br,
                                'language' => $st_lang,
                                'title' => $st_title
                            ];
                        } elseif ($st_type === 'subtitle') {
                            $res['subtitle_streams'][] = [
                                'index' => $st_idx,
                                'codec' => $codec_raw,
                                'language' => $st_lang,
                                'title' => $st_title ?: '--'
                            ];
                        }
                    }
                }
            }
        }
    }

    return $res;
}



