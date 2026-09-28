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

$cmd = escapeshellcmd($ytdlp_bin);

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

// Continue partially downloaded files
$cmd .= ' -c --no-mtime';

// Output directory & filename template
$download_dir = !empty($task['download_dir']) ? $task['download_dir'] : $config['download_dir'];
if (!is_dir($download_dir)) {
    @mkdir($download_dir, 0755, true);
}

$template = !empty($task['filename_template']) ? $task['filename_template'] : $config['filename_template'];
$output_path = rtrim($download_dir, '/') . '/' . ltrim($template, '/');
$cmd .= ' -o ' . escapeshellarg($output_path);

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
    if (!empty($task['embed_subtitles'])) {
        $cmd .= ' --embed-subs';
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
