<?php
// Build yt-dlp execution command line from task parameters
require_once __DIR__ . '/common.php';

$task_file = $argv[1] ?? '';
if (!file_exists($task_file)) {
    fwrite(STDERR, "Task file not found: $task_file\n");
    exit(1);
}

$task = json_decode(file_get_contents($task_file), true);
if (!$task || empty($task['url'])) {
    fwrite(STDERR, "Invalid task content in $task_file\n");
    exit(1);
}

$config = get_app_config();

$ytdlp_bin = get_binary_path('yt-dlp');
if (!$ytdlp_bin) {
    fwrite(STDERR, "yt-dlp binary not found\n");
    exit(1);
}

$cmd = 'TMPDIR=' . escapeshellarg(CUSTOM_TMP_DIR) . ' ' . escapeshellcmd($ytdlp_bin);

// FFmpeg location
$ffmpeg_bin = get_binary_path('ffmpeg');
if ($ffmpeg_bin) {
    $cmd .= ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpeg_bin));
}

// Cookies support
if (file_exists(COOKIES_FILE) && filesize(COOKIES_FILE) > 10) {
    $cmd .= ' --cookies ' . escapeshellarg(COOKIES_FILE);
}

// Proxy configuration
$proxy = !empty($task['proxy']) ? $task['proxy'] : $config['proxy'];
if (!empty($proxy)) {
    $cmd .= ' --proxy ' . escapeshellarg($proxy);
}

// Rate limit
$rate_limit = !empty($task['rate_limit']) ? $task['rate_limit'] : $config['rate_limit'];
if (!empty($rate_limit)) {
    $cmd .= ' --limit-rate ' . escapeshellarg($rate_limit);
}

// Continue partially downloaded files & error tolerance (-i ignores non-fatal postprocessing errors)
// --trim-filenames 150 严格限制文件名主干在 150 字符以内，防止 Linux EXT4 单文件名 255 字节超限导致 [Errno 36] File name too long
$cmd .= ' -c --no-mtime -i --trim-filenames 150';

// Output directory & filename template
$download_dir = !empty($task['download_dir']) ? $task['download_dir'] : $config['download_dir'];
if (!is_dir($download_dir)) {
    @mkdir($download_dir, 0755, true);
}

$template = !empty($task['filename_template']) ? $task['filename_template'] : ($config['filename_template'] ?? '%(extractor_key)s-%(title).100s [%(id)s].%(ext)s');
if ($template === '%(title)s [%(id)s].%(ext)s' || $template === '%(extractor_key)s-%(title)s [%(id)s].%(ext)s') {
    $template = '%(extractor_key)s-%(title).100s [%(id)s].%(ext)s';
}
$output_path = rtrim($download_dir, '/') . '/' . ltrim($template, '/');
$cmd .= ' -o ' . safe_escapeshellarg($output_path);

// Audio only mode
if (!empty($task['is_audio_only'])) {
    $audio_ext = !empty($task['audio_format']) ? $task['audio_format'] : 'mp3';
    $cmd .= ' -x --audio-format ' . escapeshellarg($audio_ext) . ' --audio-quality 0';
} else {
    // Video format selection
    $format = !empty($task['format_id']) ? $task['format_id'] : $config['default_video_quality'];
    $cmd .= ' -f ' . escapeshellarg($format);

    $container = !empty($task['container']) ? $task['container'] : $config['default_container'];
    if (!empty($container) && $container !== 'default') {
        $cmd .= ' --merge-output-format ' . escapeshellarg($container);
    }
}

// Subtitles
if (!empty($task['subtitles']) && $task['subtitles'] !== 'none') {
    $cmd .= ' --write-subs';
    if ($task['subtitles'] !== 'all') {
        $cmd .= ' --sub-langs ' . escapeshellarg($task['subtitles']);
    } else {
        $cmd .= ' --all-subs';
    }
    if (!empty($task['auto_subs'])) {
        $cmd .= ' --write-auto-subs';
    }
    // 统一将字幕转为标准 SRT 格式，大幅提升各播放器兼容性
    $cmd .= ' --convert-subs srt';

    if (!empty($task['embed_subtitles'])) {
        // MP4 容器内嵌字幕需要 FFmpeg 支持 mov_text 编码器
        // 若当前环境 FFmpeg 缺少 mov_text 编码器，强行注入 --embed-subs 会抛出 "Encoder not found" 致命中断
        // 此时自动降级保留独立同名外挂字幕 (--write-subs)，阻断任务失败
        $target_container = (!empty($task['container']) && $task['container'] !== 'default') ? $task['container'] : ($config['default_container'] ?? 'mp4');
        $can_embed = true;
        if (strtolower($target_container) === 'mp4' && !has_ffmpeg_encoder('mov_text')) {
            $can_embed = false;
        }
        if ($can_embed) {
            $cmd .= ' --embed-subs';
        }
    }
}

// Embed thumbnail & metadata
$embed_thumbnail = isset($task['embed_thumbnail']) ? $task['embed_thumbnail'] : $config['embed_thumbnail'];
if ($embed_thumbnail) {
    $cmd .= ' --embed-thumbnail';
}

$embed_metadata = isset($task['embed_metadata']) ? $task['embed_metadata'] : $config['embed_metadata'];
if ($embed_metadata) {
    $cmd .= ' --embed-metadata --embed-chapters';
}

// Custom user args
$custom_args = !empty($task['custom_args']) ? $task['custom_args'] : $config['custom_args'];
if (!empty($custom_args)) {
    $cmd .= ' ' . $custom_args;
}

// Progress template for machine parsing (download: is stripped by yt-dlp as type prefix)
$cmd .= ' --newline --progress-template ' . escapeshellarg('download:YTDLP_PROGRESS:%(progress._percent_str)s|%(progress._speed_str)s|%(progress._eta_str)s|%(progress._downloaded_bytes_str)s|%(progress._total_bytes_str)s');

// Target URL
$cmd .= ' ' . escapeshellarg($task['url']);

echo $cmd;
