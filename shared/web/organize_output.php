<?php
// QNAP yt-dlp Output Organizer
// 自动规整任务产物：
// - 若单个任务目标包含两个及以上文件，自动创建独立文件夹进行归档保存；
// - 若单个任务目标仅有一个文件，则直接落盘至下载根目录，无需创建子文件夹。

require_once __DIR__ . '/common.php';

$task_file = $argv[1] ?? '';
$task_log_dir = $argv[2] ?? '';

if (empty($task_file) || !file_exists($task_file)) {
    echo "[ORGANIZER] [ERROR] 任务配置文件不存在: " . $task_file . "\n";
    exit(1);
}

$task = json_decode(@file_get_contents($task_file), true);
if (!$task || empty($task['id'])) {
    echo "[ORGANIZER] [ERROR] 无效的任务数据\n";
    exit(1);
}

$task_id = $task['id'];
$download_dir = sanitize_download_dir($task['download_dir'] ?? '');
if (empty($download_dir) || !is_dir($download_dir)) {
    $config = get_app_config();
    $download_dir = sanitize_download_dir($config['download_dir'] ?? '/share/Download');
}

$staging_dir = rtrim($download_dir, '/') . '/.ytdlp_tmp_' . $task_id;
if (!is_dir($staging_dir)) {
    echo "[ORGANIZER] [INFO] 暂存目录不存在: " . $staging_dir . "\n";
    exit(0);
}

// 递归扫描暂存目录下的所有有效产物文件（忽略 .part, .ytdl, .tmp 等未完成临时文件）
function scan_completed_files($dir) {
    $files = [];
    $items = @scandir($dir);
    if (!$items) return $files;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $sub_files = scan_completed_files($path);
            $files = array_merge($files, $sub_files);
        } elseif (is_file($path)) {
            $lower = strtolower($item);
            if (substr($lower, -5) === '.part' || substr($lower, -5) === '.ytdl' || substr($lower, -4) === '.tmp') {
                continue;
            }
            if (@filesize($path) <= 0) {
                continue;
            }
            $files[] = $path;
        }
    }
    return $files;
}

// 提取单文件无扩展名及无语言标签的主干名
function extract_file_stem($filename) {
    // 剔除已知扩展名
    $stem = preg_replace('/\.(mp4|mkv|webm|mov|avi|flv|mp3|m4a|aac|flac|opus|wav|srt|vtt|ass|ssa|lrc|json|xml|description|jpg|jpeg|png|webp|gif)$/i', '', $filename);
    // 剔除字幕语言后缀如 .zh-Hans, .en, .ja 等
    $stem = preg_replace('/\.[a-zA-Z]{2,4}(?:-[a-zA-Z0-9]+)?$/i', '', $stem);
    return trim($stem);
}

// 净化目录名，移除非法字符并限制安全长度
function sanitize_dir_name($name, $fallback = 'Download') {
    $clean = preg_replace('~[\\/\\:\\*\\?\\"\\<\\>\\|]~', '_', $name);
    $clean = trim($clean, " ._\t\n\r\0\x0B");
    if (function_exists('mb_substr')) {
        $clean = mb_substr($clean, 0, 80, 'UTF-8');
    } else {
        $clean = substr($clean, 0, 80);
    }
    return !empty($clean) ? $clean : $fallback;
}

$files = scan_completed_files($staging_dir);
$file_count = count($files);

if ($file_count === 0) {
    echo "[ORGANIZER] [WARN] 未在暂存目录中检测到有效落盘产物\n";
    @shell_exec("rm -rf " . safe_escapeshellarg($staging_dir));
    exit(0);
}

$media_exts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'mp3', 'm4a', 'aac', 'flac', 'opus', 'wav'];

if ($file_count === 1) {
    // 单文件落盘场景：直接移动至下载根目录，无需创建子文件夹
    $src = $files[0];
    $filename = basename($src);
    $dest = rtrim($download_dir, '/') . '/' . $filename;

    if (file_exists($dest) && realpath($dest) !== realpath($src)) {
        $info = pathinfo($filename);
        $base = $info['filename'];
        $ext = !empty($info['extension']) ? '.' . $info['extension'] : '';
        $counter = 1;
        while (file_exists(rtrim($download_dir, '/') . '/' . $base . '_' . $counter . $ext)) {
            $counter++;
        }
        $dest = rtrim($download_dir, '/') . '/' . $base . '_' . $counter . $ext;
    }

    if (!@rename($src, $dest)) {
        @copy($src, $dest);
        @unlink($src);
    }
    @chmod($dest, 0666);

    @shell_exec("rm -rf " . safe_escapeshellarg($staging_dir));

    $final_bytes = @filesize($dest) ?: 0;
    echo "[ORGANIZER] 任务目标为单文件，已直接保存至根目录: " . basename($dest) . " (" . format_bytes($final_bytes) . ")\n";

    $result = [
        'file_count' => 1,
        'created_folder' => false,
        'folder_path' => '',
        'folder_name' => '',
        'primary_file' => $dest,
        'all_files' => [$dest],
        'total_bytes' => $final_bytes,
        'organized_at' => time()
    ];
    if (!empty($task_log_dir) && is_dir($task_log_dir)) {
        atomic_write_file($task_log_dir . '/organizer_result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    exit(0);
}

// 多文件落盘场景 (>= 2 个文件)：自动创建独立文件夹归档
// 1. 尝试推导公共文件名主干
$stems = [];
foreach ($files as $f) {
    $stems[] = extract_file_stem(basename($f));
}
$unique_stems = array_unique(array_filter($stems));

$folder_name = '';
if (count($unique_stems) === 1) {
    // 所有文件共享同一主干名（如 video.mp4, video.zh-Hans.srt, video.webp）
    $folder_name = sanitize_dir_name(reset($unique_stems), '');
}

if (empty($folder_name)) {
    // 列表任务或多集任务：从任务标题推导文件夹名
    $title_candidate = $task['title'] ?? '';
    // 去除末尾的媒体规格后缀，如 " - 最佳画质 · MP4"
    if (!empty($task['media_spec'])) {
        $spec_suffix = ' - ' . $task['media_spec'];
        if (substr($title_candidate, -strlen($spec_suffix)) === $spec_suffix) {
            $title_candidate = substr($title_candidate, 0, -strlen($spec_suffix));
        }
    }
    $folder_name = sanitize_dir_name($title_candidate, 'Task_' . $task_id);
}

$target_dir = rtrim($download_dir, '/') . '/' . $folder_name;
if (!is_dir($target_dir)) {
    @mkdir($target_dir, 0777, true);
}
@chmod($target_dir, 0777);

$moved_files = [];
$total_bytes = 0;
$primary_file = '';
$max_media_size = 0;

foreach ($files as $f) {
    $fn = basename($f);
    $dest = $target_dir . '/' . $fn;

    if (file_exists($dest) && realpath($dest) !== realpath($f)) {
        $info = pathinfo($fn);
        $base = $info['filename'];
        $ext = !empty($info['extension']) ? '.' . $info['extension'] : '';
        $counter = 1;
        while (file_exists($target_dir . '/' . $base . '_' . $counter . $ext)) {
            $counter++;
        }
        $dest = $target_dir . '/' . $base . '_' . $counter . $ext;
    }

    if (!@rename($f, $dest)) {
        @copy($f, $dest);
        @unlink($f);
    }
    @chmod($dest, 0666);

    $moved_files[] = $dest;
    $sz = @filesize($dest) ?: 0;
    $total_bytes += $sz;

    // 寻找主音视频媒体文件
    $ext_lower = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
    if (in_array($ext_lower, $media_exts, true)) {
        if ($sz > $max_media_size || empty($primary_file)) {
            $max_media_size = $sz;
            $primary_file = $dest;
        }
    }
}

if (empty($primary_file) && !empty($moved_files)) {
    $primary_file = $moved_files[0];
}

@shell_exec("rm -rf " . safe_escapeshellarg($staging_dir));

echo "[ORGANIZER] 任务目标包含多文件 (" . count($moved_files) . " 个)，已自动创建独立文件夹归档: " . $folder_name . " (总大小: " . format_bytes($total_bytes) . ")\n";

$result = [
    'file_count' => count($moved_files),
    'created_folder' => true,
    'folder_path' => $target_dir,
    'folder_name' => $folder_name,
    'primary_file' => $primary_file,
    'all_files' => $moved_files,
    'total_bytes' => $total_bytes,
    'organized_at' => time()
];
if (!empty($task_log_dir) && is_dir($task_log_dir)) {
    atomic_write_file($task_log_dir . '/organizer_result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
exit(0);
