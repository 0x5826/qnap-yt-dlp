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

    $ytdlp_bin = get_binary_path('yt-dlp');
    $ffmpeg_bin = get_binary_path('ffmpeg');
    $ffprobe_bin = get_binary_path('ffprobe');

    $ytdlp_ver = '未就绪';
    if ($ytdlp_bin) {
        $ver_out = shell_exec(escapeshellarg($ytdlp_bin) . " --version 2>/dev/null");
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
    $cmd = escapeshellarg($ytdlp_bin) . ' -J --no-warnings --no-check-certificates --flat-playlist --socket-timeout 20';

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
        'subtitles' => $input['subtitles'] ?? 'none',
        'embed_subtitles' => !empty($input['embed_subtitles']),
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

function handle_pause_task($input) {
    $task_id = $input['task_id'] ?? '';
    $tasks = get_tasks();
    $found = false;

    foreach ($tasks as &$t) {
        if ($t['id'] === $task_id) {
            $found = true;
            if ($t['status'] === 'downloading' || $t['status'] === 'merging') {
                $pid = intval($t['pid'] ?? 0);
                if ($pid > 0) {
                    shell_exec("pkill -P $pid 2>/dev/null; kill -15 $pid 2>/dev/null");
                }
            }
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
            $t['status'] = 'pending';
            $t['error_message'] = '';
            $t['progress'] = 0;
            $t['updated_at'] = time();
            break;
        }
    }
    unset($t);

    if ($found) {
        save_tasks($tasks);
        trigger_scheduler_tick();
        json_response(['code' => 0, 'message' => '任务已重置并加入队列']);
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
            if ($t['status'] === 'downloading' || $t['status'] === 'merging') {
                $pid = intval($t['pid'] ?? 0);
                if ($pid > 0) {
                    shell_exec("pkill -P $pid 2>/dev/null; kill -9 $pid 2>/dev/null");
                }
            }
            $task_dir = LOGS_DIR . '/tasks/' . $task_id;
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
    $cmd = escapeshellarg($ytdlp_bin) . ' -U 2>&1';
    $output = shell_exec($cmd);
    json_response(['code' => 0, 'message' => '更新指令执行完毕', 'data' => ['output' => $output]]);
}
