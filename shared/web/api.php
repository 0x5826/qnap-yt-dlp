<?php
// REST API handler for yt-dlp QNAP Web UI
require_once __DIR__ . '/common.php';

// 会话与鉴权检查
if (!checkQnapSession()) {
    json_response(['code' => 401, 'message' => '未授权的会话，请先登录 QNAP QTS 系统。'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 获取请求体 JSON 或 POST 表单
$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?: [];
if (empty($json_input) && !empty($_POST)) {
    $json_input = $_POST;
}

switch ($action) {
    case 'status':
        handle_status();
        break;

    case 'control_service':
        handle_control_service($json_input);
        break;

    case 'toggle_autostart':
        handle_toggle_autostart($json_input);
        break;

    case 'get_config':
        json_response(['code' => 0, 'data' => get_app_config()]);
        break;

    case 'save_config':
        handle_save_config($json_input);
        break;

    case 'parse_url':
        handle_parse_url($json_input);
        break;

    case 'get_tasks':
        handle_get_tasks();
        break;

    case 'add_task':
        handle_add_task($json_input);
        break;

    case 'add_batch_tasks':
        handle_add_batch_tasks($json_input);
        break;

    case 'pause_task':
        handle_pause_task($json_input);
        break;

    case 'resume_task':
        handle_resume_task($json_input);
        break;

    case 'retry_task':
        handle_retry_task($json_input);
        break;

    case 'delete_task':
        handle_delete_task($json_input);
        break;

    case 'clear_completed':
        handle_clear_completed();
        break;

    case 'get_task_log':
        handle_get_task_log();
        break;

    case 'get_media_info':
        handle_get_media_info();
        break;

    case 'get_daemon_log':
        handle_get_daemon_log();
        break;

    case 'clear_daemon_log':
        handle_clear_daemon_log();
        break;

    case 'browse_shares':
        handle_browse_shares();
        break;

    case 'get_cookies':
        handle_get_cookies();
        break;

    case 'save_cookies':
        handle_save_cookies($json_input);
        break;

    case 'update_ytdlp':
        handle_update_ytdlp();
        break;

    default:
        json_response(['code' => 400, 'message' => '未知动作请求: ' . htmlspecialchars($action)], 400);
        break;
}

// -----------------------------------------------------------------------------
// 具体业务实现
// -----------------------------------------------------------------------------

function handle_status() {
    trigger_scheduler_tick();

    $versions = get_cached_component_versions(false);
    $ytdlp_ver = $versions['ytdlp_version'] ?? '未就绪';
    $ffmpeg_ver = $versions['ffmpeg_version'] ?? '未就绪';
    $ffmpeg_source = $versions['ffmpeg_source'] ?? '未安装';

    $build_ver_file = BASE_DIR . '/build_version';
    $qpkg_ver = file_exists($build_ver_file) ? trim(file_get_contents($build_ver_file)) : '1.0.0';

    // 免守护进程按需调度架构：状态始终为就绪
    $daemon_alive = true;
    $daemon_pid = 0;

    $config = get_app_config();
    $download_dir = $config['download_dir'] ?? '/share/Download';
    list($free_space, $total_space) = get_dir_disk_space($download_dir);

    $tasks = get_tasks();
    $stats = [
        'total' => count($tasks),
        'pending' => 0,
        'downloading' => 0,
        'completed' => 0,
        'failed' => 0,
        'paused' => 0,
        'current_speed' => '--'
    ];

    foreach ($tasks as $t) {
        $st = $t['status'] ?? 'pending';
        if ($st === 'merging') $st = 'downloading';
        if (isset($stats[$st])) $stats[$st]++;
        if ($st === 'downloading' && !empty($t['speed']) && $t['speed'] !== '--') {
            $stats['current_speed'] = $t['speed'];
        }
    }

    json_response([
        'code' => 0,
        'data' => [
            'qpkg_version' => $qpkg_ver,
            'ytdlp_version' => $ytdlp_ver,
            'ffmpeg_version' => $ffmpeg_ver,
            'ffmpeg_source' => $ffmpeg_source,
            'daemon_alive' => $daemon_alive,
            'daemon_pid' => $daemon_pid,
            'autostart' => intval($config['autostart'] ?? 1),
            'system_arch' => php_uname('m'),
            'download_dir' => $download_dir,
            'free_space_str' => format_bytes($free_space),
            'total_space_str' => format_bytes($total_space),
            'free_space_bytes' => $free_space,
            'stats' => $stats
        ]
    ]);
}

function handle_control_service($input) {
    $cmd_type = $input['command'] ?? '';
    $sh = BASE_DIR . '/ytdlp.sh';
    if (!file_exists($sh)) {
        json_response(['code' => 500, 'message' => '找不到启停管理脚本 ytdlp.sh'], 500);
    }

    switch ($cmd_type) {
        case 'start':
            $out = shell_exec(escapeshellarg($sh) . " start force 2>&1");
            break;
        case 'stop':
            $out = shell_exec(escapeshellarg($sh) . " stop 2>&1");
            break;
        case 'restart':
            $out = shell_exec(escapeshellarg($sh) . " restart 2>&1");
            break;
        default:
            json_response(['code' => 400, 'message' => '不支持的控制指令'], 400);
            return;
    }

    json_response([
        'code' => 0,
        'message' => '服务控制指令已下发',
        'data' => ['output' => trim($out ?: '')]
    ]);
}

function handle_toggle_autostart($input) {
    $enable = !empty($input['enable']) ? 1 : 0;
    save_app_config(['autostart' => $enable]);
    json_response([
        'code' => 0,
        'message' => $enable ? '已启用开机自启' : '已禁用开机自启',
        'data' => ['autostart' => $enable]
    ]);
}

function handle_save_config($input) {
    if (empty($input)) {
        json_response(['code' => 400, 'message' => '参数不能为空'], 400);
    }
    if (save_app_config($input)) {
        json_response(['code' => 0, 'message' => '全局配置已成功保存']);
    } else {
        json_response(['code' => 500, 'message' => '保存设置失败，请检查写入权限'], 500);
    }
}

function handle_parse_url($input) {
    $url = trim($input['url'] ?? '');
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_response(['code' => 400, 'message' => '请输入有效的视频链接 (URL)'], 400);
    }

    $ytdlp_bin = get_binary_path('yt-dlp');
    if (!$ytdlp_bin) {
        json_response(['code' => 500, 'message' => 'yt-dlp 核心组件未就绪，请检查安装'], 500);
    }

    $config = get_app_config();
    $cmd = 'TMPDIR=' . escapeshellarg(CUSTOM_TMP_DIR) . ' ' . escapeshellarg($ytdlp_bin) . ' -J --no-warnings --no-check-certificates --flat-playlist --socket-timeout 20';

    if (!empty($config['proxy'])) {
        $cmd .= ' --proxy ' . escapeshellarg($config['proxy']);
    }
    if (file_exists(COOKIES_FILE) && filesize(COOKIES_FILE) > 10) {
        $cmd .= ' --cookies ' . escapeshellarg(COOKIES_FILE);
    }

    $cmd .= ' ' . escapeshellarg($url);

    $json_output = shell_exec($cmd . ' 2>&1');
    $data = json_decode($json_output, true);

    if (!$data || !is_array($data)) {
        $lines = explode("\n", (string)$json_output);
        $found = false;
        foreach ($lines as $line) {
            $t = json_decode($line, true);
            if ($t && isset($t['title'])) {
                $data = $t;
                $found = true;
                break;
            }
        }
        if (!$found) {
            json_response(['code' => 500, 'message' => '媒体解析失败: ' . substr($json_output, 0, 300)], 500);
        }
    }

    $formats = $data['formats'] ?? [];
    $resolutions = [];
    $audio_formats = [];

    foreach ($formats as $f) {
        $height = intval($f['height'] ?? 0);
        $vcodec = $f['vcodec'] ?? 'none';
        $acodec = $f['acodec'] ?? 'none';
        $ext = $f['ext'] ?? 'mp4';
        $fid = $f['format_id'] ?? '';
        $filesize = $f['filesize'] ?? $f['filesize_approx'] ?? 0;

        if ($vcodec !== 'none' && $height > 0) {
            $res_key = $height . 'p';
            if (!isset($resolutions[$res_key]) || ($f['fps'] ?? 30) > ($resolutions[$res_key]['fps'] ?? 0)) {
                $resolutions[$res_key] = [
                    'height' => $height,
                    'label' => $res_key . ($height >= 2160 ? ' (4K)' : ($height >= 1440 ? ' (2K)' : ($height >= 1080 ? ' (全高清)' : ''))),
                    'vcodec' => $vcodec,
                    'format_id' => $fid,
                    'filesize_str' => $filesize > 0 ? format_bytes($filesize) : '未知'
                ];
            }
        } elseif ($vcodec === 'none' && $acodec !== 'none') {
            $audio_formats[] = [
                'format_id' => $fid,
                'ext' => $ext,
                'abr' => $f['abr'] ?? 128,
                'acodec' => $acodec,
                'filesize_str' => $filesize > 0 ? format_bytes($filesize) : '未知'
            ];
        }
    }

    krsort($resolutions, SORT_NUMERIC);

    $subtitles = [];
    if (!empty($data['subtitles'])) {
        foreach ($data['subtitles'] as $lang => $meta) {
            $subtitles[] = ['code' => $lang, 'name' => $meta[0]['name'] ?? $lang];
        }
    }
    if (!empty($data['automatic_captions'])) {
        foreach ($data['automatic_captions'] as $lang => $meta) {
            $subtitles[] = ['code' => $lang, 'name' => ($meta[0]['name'] ?? $lang) . ' (自动生成)'];
        }
    }

    json_response([
        'code' => 0,
        'data' => [
            'id' => $data['id'] ?? '',
            'title' => $data['title'] ?? '未知标题',
            'uploader' => $data['uploader'] ?? $data['channel'] ?? '未知作者',
            'duration' => $data['duration'] ?? 0,
            'thumbnail' => $data['thumbnail'] ?? '',
            'description' => mb_substr($data['description'] ?? '', 0, 160),
            'webpage_url' => $data['webpage_url'] ?? $url,
            'platform' => get_media_platform_name($data, $url),
            'resolutions' => array_values($resolutions),
            'audio_formats' => $audio_formats,
            'subtitles' => $subtitles
        ]
    ]);
}

function handle_get_tasks() {
    trigger_scheduler_tick();
    $tasks = get_tasks();
    $needs_save = false;

    foreach ($tasks as &$t) {
        $st = $t['status'] ?? '';
        $tid = $t['id'] ?? '';
        if ($st === 'completed' && (!isset($t['total_size']) || empty($t['total_size']) || $t['total_size'] === '完整' || $t['total_size'] === '--')) {
            $out_log = LOGS_DIR . '/tasks/' . $tid . '/output.log';
            if (file_exists($out_log)) {
                $meta = inspect_task_log($out_log);
                $tgt = $meta['target_file'] ?: ($t['target_file'] ?? $meta['dest_file'] ?? $t['dest_file'] ?? '');
                if (!empty($tgt) && file_exists($tgt)) {
                    $t['total_size'] = format_bytes(filesize($tgt));
                    $t['target_file'] = $tgt;
                } elseif (!empty($meta['total_size'])) {
                    $t['total_size'] = $meta['total_size'];
                } elseif (!empty($meta['last_downloaded'])) {
                    $t['total_size'] = $meta['last_downloaded'];
                }

                if ((empty($t['final_speed']) || $t['final_speed'] === '已完成' || $t['final_speed'] === '--') && !empty($meta['final_speed'])) {
                    $t['final_speed'] = $meta['final_speed'];
                    $t['speed'] = $meta['final_speed'];
                }
                $needs_save = true;
            }
        }
    }
    unset($t);

    if ($needs_save) {
        save_tasks($tasks);
    }

    $tasks = array_reverse($tasks);
    json_response(['code' => 0, 'data' => $tasks]);
}

function handle_add_task($input) {
    $url = trim($input['url'] ?? '');
    if (empty($url)) {
        json_response(['code' => 400, 'message' => '目标 URL 不能为空'], 400);
    }

    $config = get_app_config();
    $task_id = 'task_' . date('ymdHis') . '_' . substr(md5(uniqid('', true)), 0, 4);

    $platform = trim($input['platform'] ?? '');
    $media_spec = trim($input['media_spec'] ?? '');
    $title_raw = trim($input['title'] ?? '媒体任务 ' . date('Y-m-d H:i:s'));

    // 格式化标准任务标题：视频平台 - 标题 - 下载的媒体参数配置
    $standard_title = $title_raw;
    if (!empty($platform) && !empty($media_spec) && strpos($title_raw, ' - ') === false) {
        $standard_title = "{$platform} - {$title_raw} - {$media_spec}";
    }

    $new_task = [
        'id' => $task_id,
        'url' => $url,
        'title' => $standard_title,
        'platform' => $platform,
        'media_spec' => $media_spec,
        'thumbnail' => $input['thumbnail'] ?? '',
        'duration' => intval($input['duration'] ?? 0),
        'format_id' => $input['format_id'] ?? $config['default_video_quality'],
        'container' => $input['container'] ?? $config['default_container'],
        'is_audio_only' => !empty($input['is_audio_only']),
        'audio_format' => $input['audio_format'] ?? 'mp3',
        'subtitles' => $input['subtitles'] ?? ($config['default_subtitles'] ?? 'all'),
        'embed_subtitles' => isset($input['embed_subtitles']) ? (bool)$input['embed_subtitles'] : true,
        'embed_thumbnail' => isset($input['embed_thumbnail']) ? (bool)$input['embed_thumbnail'] : (bool)$config['embed_thumbnail'],
        'embed_metadata' => isset($input['embed_metadata']) ? (bool)$input['embed_metadata'] : (bool)$config['embed_metadata'],
        'download_dir' => !empty($input['download_dir']) ? $input['download_dir'] : $config['download_dir'],
        'filename_template' => !empty($input['filename_template']) ? $input['filename_template'] : $config['filename_template'],
        'rate_limit' => $input['rate_limit'] ?? '',
        'proxy' => $input['proxy'] ?? '',
        'status' => 'pending',
        'progress' => 0.0,
        'speed' => '--',
        'eta' => '--',
        'downloaded' => '0 B',
        'total_size' => '--',
        'pid' => 0,
        'error_message' => '',
        'created_at' => time(),
        'updated_at' => time()
    ];

    $tasks = get_tasks();
    $tasks[] = $new_task;
    save_tasks($tasks);

    trigger_scheduler_tick();

    json_response(['code' => 0, 'message' => '任务已成功加入下载队列', 'data' => ['task_id' => $task_id]]);
}

function handle_add_batch_tasks($input) {
    $urls = $input['urls'] ?? [];
    if (!is_array($urls) || empty($urls)) {
        json_response(['code' => 400, 'message' => '未传入有效的视频链接列表'], 400);
    }

    $config = get_app_config();
    $tasks = get_tasks();
    $added_count = 0;
    $now = time();

    foreach ($urls as $idx => $url) {
        $url = trim($url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) continue;

        $task_id = 'task_' . date('ymdHis', $now) . '_' . substr(md5(uniqid('', true) . $idx), 0, 4);
        $format_id = $input['format_id'] ?? $config['default_video_quality'];
        $container = $input['container'] ?? $config['default_container'];

        $spec_desc = $input['media_spec'] ?? '最高画质 · MP4';
        if (!empty($input['is_audio_only'])) {
            $spec_desc = strtoupper($input['audio_format'] ?? 'MP3') . ' · 纯音频';
        }

        $new_task = [
            'id' => $task_id,
            'url' => $url,
            'title' => '批量下载任务 - ' . $url,
            'platform' => '网络媒体',
            'media_spec' => $spec_desc,
            'thumbnail' => '',
            'duration' => 0,
            'format_id' => $format_id,
            'container' => $container,
            'is_audio_only' => !empty($input['is_audio_only']),
            'audio_format' => $input['audio_format'] ?? 'mp3',
            'subtitles' => $input['subtitles'] ?? 'all',
            'embed_subtitles' => isset($input['embed_subtitles']) ? (bool)$input['embed_subtitles'] : true,
            'embed_thumbnail' => isset($input['embed_thumbnail']) ? (bool)$input['embed_thumbnail'] : (bool)$config['embed_thumbnail'],
            'embed_metadata' => isset($input['embed_metadata']) ? (bool)$input['embed_metadata'] : (bool)$config['embed_metadata'],
            'download_dir' => !empty($input['download_dir']) ? $input['download_dir'] : $config['download_dir'],
            'filename_template' => !empty($input['filename_template']) ? $input['filename_template'] : $config['filename_template'],
            'rate_limit' => $input['rate_limit'] ?? '',
            'proxy' => $input['proxy'] ?? '',
            'status' => 'pending',
            'progress' => 0.0,
            'speed' => '--',
            'eta' => '--',
            'downloaded' => '0 B',
            'total_size' => '--',
            'pid' => 0,
            'error_message' => '',
            'created_at' => $now,
            'updated_at' => $now
        ];

        $tasks[] = $new_task;
        $added_count++;
    }

    if ($added_count > 0) {
        save_tasks($tasks);
        trigger_scheduler_tick();
        json_response(['code' => 0, 'message' => "已成功将 {$added_count} 个任务批量加入下载队列", 'data' => ['added_count' => $added_count]]);
    } else {
        json_response(['code' => 400, 'message' => '未能识别到有效的下载链接'], 400);
    }
}

function handle_pause_task($input) {
    $task_id = $input['task_id'] ?? '';
    $tasks = get_tasks();
    $found = false;

    foreach ($tasks as &$t) {
        if ($t['id'] === $task_id) {
            $found = true;
            $task_dir = LOGS_DIR . '/tasks/' . $task_id;
            $pid = intval($t['pid'] ?? 0);
            if ($pid <= 0 && file_exists($task_dir . '/worker.pid')) {
                $pid = intval(trim(@file_get_contents($task_dir . '/worker.pid')));
            }
            if ($pid > 0) {
                kill_process_tree($pid);
            }
            @unlink($task_dir . '/worker.pid');

            $t['status'] = 'paused';
            $t['speed'] = '--';
            $t['pid'] = 0;
            $t['updated_at'] = time();
            break;
        }
    }
    unset($t);

    if ($found) {
        save_tasks($tasks);
        json_response(['code' => 0, 'message' => '任务已暂停']);
    } else {
        json_response(['code' => 404, 'message' => '未找到指定任务'], 404);
    }
}

function handle_resume_task($input) {
    $task_id = $input['task_id'] ?? '';
    $tasks = get_tasks();
    $found = false;

    foreach ($tasks as &$t) {
        if ($t['id'] === $task_id) {
            $found = true;
            $t['status'] = 'pending';
            $t['updated_at'] = time();
            break;
        }
    }
    unset($t);

    if ($found) {
        save_tasks($tasks);
        trigger_scheduler_tick();
        json_response(['code' => 0, 'message' => '任务已重新加入排队']);
    } else {
        json_response(['code' => 404, 'message' => '未找到指定任务'], 404);
    }
}

function handle_retry_task($input) {
    $task_id = $input['task_id'] ?? '';
    $tasks = get_tasks();
    $found = false;

    foreach ($tasks as &$t) {
        if ($t['id'] === $task_id) {
            $found = true;
            $task_dir = LOGS_DIR . '/tasks/' . $task_id;
            $pid = intval($t['pid'] ?? 0);
            if ($pid <= 0 && file_exists($task_dir . '/worker.pid')) {
                $pid = intval(trim(@file_get_contents($task_dir . '/worker.pid')));
            }
            if ($pid > 0) {
                kill_process_tree($pid);
            }
            @unlink($task_dir . '/worker.pid');
            @unlink($task_dir . '/exit_code');

            $t['status'] = 'pending';
            $t['error_message'] = '';
            $t['progress'] = 0;
            $t['speed'] = '--';
            $t['eta'] = '--';
            $t['pid'] = 0;
            $t['updated_at'] = time();
            break;
        }
    }
    unset($t);

    if ($found) {
        save_tasks($tasks);
        trigger_scheduler_tick();
        json_response(['code' => 0, 'message' => '任务已重置并重新加入队列']);
    } else {
        json_response(['code' => 404, 'message' => '未找到指定任务'], 404);
    }
}

function handle_delete_task($input) {
    $task_id = $input['task_id'] ?? '';
    $tasks = get_tasks();
    $new_tasks = [];
    $found = false;

    foreach ($tasks as $t) {
        if ($t['id'] === $task_id) {
            $found = true;
            $task_dir = LOGS_DIR . '/tasks/' . $task_id;
            $pid = intval($t['pid'] ?? 0);
            if ($pid <= 0 && file_exists($task_dir . '/worker.pid')) {
                $pid = intval(trim(@file_get_contents($task_dir . '/worker.pid')));
            }
            if ($pid > 0) {
                kill_process_tree($pid);
            }
            if (is_dir($task_dir)) {
                shell_exec("rm -rf " . escapeshellarg($task_dir));
            }
        } else {
            $new_tasks[] = $t;
        }
    }

    if ($found) {
        save_tasks($new_tasks);
        json_response(['code' => 0, 'message' => '任务已彻底删除']);
    } else {
        json_response(['code' => 404, 'message' => '未找到指定任务'], 404);
    }
}

function handle_clear_completed() {
    $tasks = get_tasks();
    $filtered = [];
    foreach ($tasks as $t) {
        if (($t['status'] ?? '') !== 'completed') {
            $filtered[] = $t;
        } else {
            $task_dir = LOGS_DIR . '/tasks/' . ($t['id'] ?? '');
            if (is_dir($task_dir)) {
                @shell_exec("rm -rf " . escapeshellarg($task_dir));
            }
        }
    }
    save_tasks($filtered);
    json_response(['code' => 0, 'message' => '已清除所有已完成任务']);
}

function handle_get_task_log() {
    $task_id = $_GET['task_id'] ?? '';
    $log_file = LOGS_DIR . '/tasks/' . $task_id . '/output.log';
    if (!file_exists($log_file)) {
        json_response(['code' => 0, 'data' => ['log' => '暂无日志输出。']]);
    }
    $content = shell_exec("tail -n 300 " . escapeshellarg($log_file));
    json_response(['code' => 0, 'data' => ['log' => $content ?: '']]);
}

function handle_get_media_info() {
    $task_id = $_GET['task_id'] ?? ($_POST['task_id'] ?? '');
    if (empty($task_id)) {
        json_response(['code' => 400, 'message' => '缺少任务 ID'], 400);
    }

    $tasks = get_tasks();
    $target_task = null;
    foreach ($tasks as $t) {
        if (($t['id'] ?? '') === $task_id) {
            $target_task = $t;
            break;
        }
    }

    if (!$target_task) {
        json_response(['code' => 404, 'message' => '未找到对应任务'], 404);
    }

    $task_dir = LOGS_DIR . '/tasks/' . $task_id;
    $cache_file = $task_dir . '/media_info.json';

    // 寻找目标产物物理文件路径
    $target_file = $target_task['target_file'] ?? '';
    if (empty($target_file) || !file_exists($target_file)) {
        $out_log = $task_dir . '/output.log';
        if (file_exists($out_log)) {
            $meta = inspect_task_log($out_log);
            $target_file = $meta['target_file'] ?: ($meta['dest_file'] ?? '');
        }
    }

    // 若依然为空，尝试在下载目录按标题匹配文件
    if ((empty($target_file) || !file_exists($target_file)) && !empty($target_task['download_dir']) && is_dir($target_task['download_dir'])) {
        $down_dir = rtrim($target_task['download_dir'], '/');
        $candidates = @scandir($down_dir) ?: [];
        foreach ($candidates as $c) {
            if ($c === '.' || $c === '..' || substr($c, -5) === '.part') continue;
            $full_c = $down_dir . '/' . $c;
            if (is_file($full_c)) {
                if (!empty($target_task['title']) && strpos($c, mb_substr($target_task['title'], 0, 15)) !== false) {
                    $target_file = $full_c;
                    break;
                }
            }
        }
    }

    if (empty($target_file) || !file_exists($target_file)) {
        json_response(['code' => 404, 'message' => '未找到已落盘的媒体文件，可能已被移除或尚未完成'], 404);
    }

    // 检查缓存
    if (file_exists($cache_file) && filemtime($cache_file) >= filemtime($target_file)) {
        $cached_data = json_decode(@file_get_contents($cache_file), true);
        if (is_array($cached_data)) {
            json_response(['code' => 0, 'data' => $cached_data]);
        }
    }

    // 调用 ffprobe 结构化解析
    $media_info = get_media_file_info($target_file);
    if (!$media_info) {
        json_response(['code' => 500, 'message' => '媒体信息提取失败，请检查文件是否可读'], 500);
    }

    // 写入缓存加速后续读取
    if (is_dir($task_dir)) {
        @file_put_contents($cache_file, json_encode($media_info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    json_response(['code' => 0, 'data' => $media_info]);
}

function handle_get_daemon_log() {
    $log_file = LOGS_DIR . '/daemon.log';
    if (!file_exists($log_file) || filesize($log_file) === 0) {
        $init_line = sprintf("[%s] [SYSTEM] yt-dlp 调度与运行服务就绪，按需事件驱动架构已激活，等待任务触发...\n", date('Y-m-d H:i:s'));
        @file_put_contents($log_file, $init_line, LOCK_EX);
        @chmod($log_file, 0666);
    }
    $content = shell_exec("tail -n 300 " . escapeshellarg($log_file));
    json_response(['code' => 0, 'data' => ['log' => $content ?: '暂无系统日志。']]);
}

function handle_clear_daemon_log() {
    $log_file = LOGS_DIR . '/daemon.log';
    if (file_exists($log_file)) {
        @file_put_contents($log_file, '');
    }
    json_response(['code' => 0, 'message' => '系统守护日志已清空']);
}

function handle_browse_shares() {
    $shares = [];
    $root_share = '/share';
    if (is_dir($root_share)) {
        $dirs = @scandir($root_share);
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..' || strpos($d, '.') === 0) continue;
                $full = $root_share . '/' . $d;
                if (is_dir($full)) {
                    list($s_free, $s_total) = get_dir_disk_space($full);
                    $shares[] = [
                        'name' => $d,
                        'path' => $full,
                        'free_space_str' => format_bytes($s_free)
                    ];
                }
            }
        }
    }
    json_response(['code' => 0, 'data' => $shares]);
}

function handle_get_cookies() {
    $content = file_exists(COOKIES_FILE) ? file_get_contents(COOKIES_FILE) : '';
    json_response(['code' => 0, 'data' => ['cookies' => $content]]);
}

function handle_save_cookies($input) {
    $content = $input['cookies'] ?? '';
    if (atomic_write_file(COOKIES_FILE, $content)) {
        json_response(['code' => 0, 'message' => 'Cookies 凭据已成功保存']);
    } else {
        json_response(['code' => 500, 'message' => '保存 Cookies 失败'], 500);
    }
}

function handle_update_ytdlp() {
    $ytdlp_bin = get_binary_path('yt-dlp');
    if (!$ytdlp_bin) {
        json_response(['code' => 500, 'message' => '未找到 yt-dlp 二进制文件'], 500);
    }
    $cmd = 'TMPDIR=' . escapeshellarg(CUSTOM_TMP_DIR) . ' ' . escapeshellarg($ytdlp_bin) . ' -U 2>&1';
    $output = shell_exec($cmd);
    get_cached_component_versions(true);
    json_response(['code' => 0, 'message' => '更新指令执行完毕', 'data' => ['output' => $output]]);
}
