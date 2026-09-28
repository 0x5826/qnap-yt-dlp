<?php
session_start();
require_once __DIR__ . '/common.php';

$isQnapAuth = checkQnapSession();
$assetVer = file_exists(__DIR__ . '/static/logo.png') ? filemtime(__DIR__ . '/static/logo.png') : time();

if (!$isQnapAuth):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>身份验证 - yt-dlp QNAP</title>
    <link rel="shortcut icon" href="static/favicon.png?v=<?= $assetVer ?>" type="image/png">
    <script>
        (function() {
            try {
                let foundSid = '';
                const m = document.cookie.match(/(?:^|;\s*)NAS_SID=([^;]+)/);
                if (m) foundSid = m[1];
                if (!foundSid && window.parent && window.parent !== window) {
                    try {
                        const pm = window.parent.document.cookie.match(/(?:^|;\s*)NAS_SID=([^;]+)/);
                        if (pm) foundSid = pm[1];
                    } catch(e) {}
                }
                if (foundSid && !window.location.search.includes('sid=')) {
                    const sep = window.location.search ? '&' : '?';
                    window.location.replace(window.location.href + sep + 'sid=' + encodeURIComponent(foundSid));
                }
            } catch(e) {}
        })();
    </script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #f8fafc;
        }
        .auth-container {
            background: rgba(30, 41, 59, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.1);
            padding: 40px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); text-align: center;
            max-width: 440px; width: 90%;
        }
        .lock-icon { margin-bottom: 20px; display: inline-flex; justify-content: center; }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; color: #fff; }
        .message {
            background: rgba(234, 179, 8, 0.15); border: 1px solid rgba(234, 179, 8, 0.3);
            color: #fef08a; padding: 14px; border-radius: 8px; margin: 20px 0; font-size: 13px; line-height: 1.6;
        }
        .btn-login {
            display: inline-block; background: #e11d48; color: white; padding: 12px 32px; border-radius: 8px;
            text-decoration: none; font-size: 14px; font-weight: 600; transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(225, 29, 72, 0.35);
        }
        .btn-login:hover { background: #f43f5e; transform: translateY(-1px); }
        .footer-info { margin-top: 24px; font-size: 12px; color: #94a3b8; }
        .countdown { color: #f43f5e; font-weight: bold; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="lock-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h1>QNAP 身份鉴权 · 媒体下载</h1>
        <div class="message">
            访问媒体下载管理控制台需要先验证 QTS 登录身份。<br>检测到当前未处于有效的管理员会话中。
        </div>
        <a href="/" class="btn-login">前往 QTS 桌面登录</a>
        <div class="footer-info">
            <span class="countdown" id="countdown">10</span> 秒后自动跳转至登录页
        </div>
    </div>
    <script>
        let seconds = 10;
        const countdownEl = document.getElementById('countdown');
        const timer = setInterval(() => {
            seconds--;
            countdownEl.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(timer);
                window.top.location.href = '/';
            }
        }, 1000);
    </script>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>媒体下载 - QNAP 管理控制台</title>
    <link rel="shortcut icon" href="static/favicon.png?v=<?= $assetVer ?>" type="image/png">
    <style>
        :root {
            --bg-base: #0f172a;
            --bg-surface: #1e293b;
            --bg-card: #1e293b;
            --bg-hover: #334155;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-focus: #0ea5e9;

            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;

            --primary: #0ea5e9;
            --primary-hover: #38bdf8;
            --accent-cyan: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;

            --shadow-card: 0 4px 20px -2px rgba(0, 0, 0, 0.4);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.25);
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "JetBrains Mono", monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
            padding: 16px;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
        }

        /* 顶部导航 (对齐 EasyTier / Lucky 架构规范) */
        .app-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .official-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: contain;
            filter: drop-shadow(0 3px 10px rgba(225, 29, 72, 0.35));
        }

        .title-meta h1 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-version {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 6px;
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border-radius: 4px;
            border: 1px solid rgba(16, 185, 129, 0.3);
            font-family: var(--font-mono);
        }

        .app-subtitle {
            font-size: 12px;
            color: var(--text-muted);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* 状态指示盒与开机自启动控制器 */
        .status-indicator-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(0, 0, 0, 0.25);
            padding: 5px 14px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-dot-running {
            background-color: var(--success);
            box-shadow: 0 0 10px var(--success);
            animation: pulse 2s infinite;
        }

        .status-dot-stopped {
            background-color: var(--danger);
            box-shadow: 0 0 8px var(--danger);
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .status-text {
            font-size: 13px;
            font-weight: 500;
        }

        .status-divider {
            width: 1px;
            height: 14px;
            background: rgba(255, 255, 255, 0.15);
            margin: 0 2px;
        }

        .autostart-control {
            display: flex;
            align-items: center;
            gap: 8px;
            user-select: none;
        }

        .autostart-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* 微型开关组件规范 */
        .switch {
            position: relative;
            display: inline-block;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #334155;
            transition: .25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .slider:before {
            position: absolute;
            content: "";
            background-color: #94a3b8;
            transition: .25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        input:checked + .slider {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        input:checked + .slider:before {
            background-color: #ffffff;
        }

        .switch-sm {
            width: 32px;
            height: 18px;
        }

        .switch-sm .slider {
            border-radius: 18px;
        }

        .switch-sm .slider:before {
            height: 14px;
            width: 14px;
            left: 2px;
            bottom: 1px;
            border-radius: 50%;
        }

        .switch-sm input:checked + .slider:before {
            transform: translateX(14px);
        }

        /* 按钮体系 */
        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            line-height: 1.4;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 8px rgba(225, 29, 72, 0.3);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-1px);
        }

        .btn-launch {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
        }

        .btn-launch:hover {
            background: var(--success);
            color: white;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* 核心状态指标卡片网格 (4 列) */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 16px;
        }

        @media (max-width: 900px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 500px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .bg-rose {
            background: rgba(225, 29, 72, 0.15);
            color: #fb7185;
            border: 1px solid rgba(225, 29, 72, 0.25);
        }

        .bg-blue {
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(14, 165, 233, 0.25);
        }

        .bg-emerald {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .bg-amber {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .stat-info {
            flex: 1;
            overflow: hidden;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            font-family: var(--font-mono);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-value-sub {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-main);
            font-family: var(--font-mono);
            line-height: 1.4;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .stat-line-item {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .engine-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(14, 165, 233, 0.3);
            letter-spacing: 0;
            display: inline-block;
        }
        .engine-badge-native {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.3);
        }

        /* 选项卡控制器 (对齐 EasyTier / Lucky 优雅分段控制器设计) */
        .tab-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 5px;
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab-btn.active {
            color: #fff;
            background: var(--primary);
            box-shadow: 0 2px 10px rgba(14, 165, 233, 0.35);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* 卡片容器 */
        .card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 600;
            color: #fff;
        }

        /* 表单与输入框 */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 9px 12px;
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 13px;
            transition: border-color 0.2s;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--border-focus);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }

        /* 视频解析预览卡片 */
        .parse-preview {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-top: 16px;
        }

        @media (max-width: 640px) {
            .parse-preview {
                grid-template-columns: 1fr;
            }
        }

        .preview-thumb {
            width: 100%;
            height: 160px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            background: #172033;
        }

        .preview-meta h3 {
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .preview-meta p {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        /* 分辨率规格胶囊 */
        .pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .pill-item {
            padding: 6px 12px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .pill-item:hover {
            border-color: rgba(255, 255, 255, 0.2);
            color: #fff;
        }

        .pill-item.active {
            background: rgba(14, 165, 233, 0.18);
            border-color: var(--primary);
            color: #38bdf8;
            box-shadow: 0 0 10px rgba(14, 165, 233, 0.28);
            font-weight: 600;
        }

        /* 任务调度队列项 */
        .task-item {
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .task-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .task-title-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }

        .task-title {
            font-weight: 600;
            color: #fff;
            word-break: break-all;
            font-size: 13px;
            user-select: text;
            -webkit-user-select: text;
        }

        .btn-copy-title {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--text-muted);
            border-radius: 4px;
            padding: 2px 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            flex-shrink: 0;
            line-height: 1;
        }

        .btn-copy-title:hover {
            color: #fff;
            background: rgba(59, 130, 246, 0.25);
            border-color: #3b82f6;
        }

        .task-subtitle {
            font-size: 11px;
            color: var(--text-muted);
        }

        .task-progress-bar {
            height: 6px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 3px;
            overflow: hidden;
        }

        .task-progress-inner {
            height: 100%;
            background: linear-gradient(90deg, #e11d48, #0ea5e9);
            width: 0%;
            transition: width 0.3s ease;
        }

        .task-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .task-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* 模式切换器 */
        .mode-toggle {
            display: inline-flex;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            padding: 3px;
            border: 1px solid var(--border-color);
        }

        .mode-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .mode-btn.active {
            background: #2563eb;
            color: #fff;
            font-weight: 500;
        }

        /* 折叠任务卡片 */
        .task-item {
            transition: all 0.2s ease;
        }

        .task-item.collapsed {
            padding: 10px 14px;
            gap: 0;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.02);
        }

        .task-item.collapsed:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(59, 130, 246, 0.4);
        }

        .task-item.collapsed .task-progress-bar,
        .task-item.collapsed .task-info-row,
        .task-item.collapsed .task-actions,
        .task-item.collapsed .task-err-msg {
            display: none !important;
        }

        .task-item.collapsed .task-title {
            margin-bottom: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 58vw;
        }

        .task-toggle-icon {
            display: inline-block;
            transition: transform 0.2s ease;
            font-size: 11px;
            color: var(--text-muted);
            margin-left: 8px;
        }

        .task-item.collapsed .task-toggle-icon {
            transform: rotate(-90deg);
        }

        /* 底部分页器 */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 10px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .pagination-buttons {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .page-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .page-btn:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .page-btn.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            font-weight: 600;
        }

        .page-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        /* 终端视窗模态框 */
        .modal {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 200;
        }

        .modal.show { display: flex; }

        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            width: 85%;
            max-width: 900px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        .modal-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 16px;
            flex: 1;
            overflow-y: auto;
            background: #090d16;
        }

        .term-log {
            font-family: var(--font-mono);
            font-size: 12px;
            color: #38bdf8;
            white-space: pre-wrap;
            line-height: 1.45;
        }

        /* 媒体信息模态框样式 (无 Emoji 极简风格) */
        .media-section-title {
            font-size: 13px;
            font-weight: 600;
            color: #e2e8f0;
            margin: 16px 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border-color);
            letter-spacing: 0.5px;
        }

        .media-section-title:first-child {
            margin-top: 0;
        }

        .media-specs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .media-spec-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 6px;
            padding: 8px 12px;
        }

        .media-spec-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .media-spec-val {
            font-size: 13px;
            font-weight: 500;
            color: #f8fafc;
            word-break: break-all;
            font-family: var(--font-mono);
        }

        .media-stream-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
        }

        .media-stream-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .media-stream-badge {
            background: #2563eb;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        .media-stream-badge.audio {
            background: #0284c7;
        }

        .media-stream-badge.subtitle {
            background: #475569;
        }

        .media-stream-props {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 8px;
            font-size: 12px;
        }

        .media-prop-key {
            color: var(--text-muted);
        }

        .media-prop-val {
            color: #e2e8f0;
            font-family: var(--font-mono);
        }

        /* 居中浮动 Toast */
        .toast {
            position: fixed;
            top: 24px;
            left: 50%;
            transform: translate(-50%, -20px);
            background: #1e293b;
            color: #fff;
            border: 1px solid var(--border-color);
            padding: 10px 20px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            font-size: 13px;
            font-weight: 500;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 999;
        }

        .toast.show {
            transform: translate(-50%, 0);
            opacity: 1;
        }

        .toast-success { border-color: rgba(16, 185, 129, 0.5); color: #34d399; }
        .toast-danger { border-color: rgba(239, 68, 68, 0.5); color: #f87171; }

        .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 头部导航与状态控制器 -->
        <header class="app-header">
            <div class="header-left">
                <img src="static/logo.png?v=<?= $assetVer ?>" alt="yt-dlp logo" class="official-logo">
                <div class="title-meta">
                    <h1>
                        媒体下载
                        <span class="badge-version" id="ver-badge">加载中</span>
                    </h1>
                    <div class="app-subtitle">基于 yt-dlp 与 FFmpeg 的高性能媒体下载套件</div>
                </div>
            </div>
            <div class="header-right">
                <div class="status-indicator-box">
                    <span class="pulse-dot status-dot-running" id="daemon-dot"></span>
                    <span class="status-text" id="daemon-text">正常运行</span>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-outline" onclick="fetchStatus()">
                        刷新状态
                    </button>
                </div>
            </div>
        </header>

        <!-- 核心状态指标卡片网格 (紧凑双排、高对称度设计) -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon bg-indigo">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polygon points="10 8 16 12 10 16 10 8"></polygon>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-label">任务调度状态</div>
                    <div class="stat-value-sub">
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">下载中:</span>
                            <span id="stat-dl-count" style="color: #10b981; font-weight: 700;">0</span>
                            <span style="color: var(--text-muted); font-size: 11px;">个</span>
                        </div>
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">排队中:</span>
                            <span id="stat-pending-count" style="color: #38bdf8; font-weight: 700;">0</span>
                            <span style="color: var(--text-muted); font-size: 11px;">个</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-blue">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-label">全局下载速率</div>
                    <div class="stat-value" id="stat-speed" style="font-size: 16px; color: #38bdf8;">--</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-emerald">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-label">目标存储空间</div>
                    <div class="stat-value-sub">
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">可用:</span>
                            <span id="stat-storage-free" style="color: #10b981; font-weight: 700;">--</span>
                        </div>
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">总计:</span>
                            <span id="stat-storage-total" style="color: var(--text-muted);">--</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-amber">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-label">核心引擎与组件</div>
                    <div class="stat-value-sub" id="stat-engine-ver">
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">yt-dlp:</span>
                            <span id="stat-ytdlp-val">加载中...</span>
                        </div>
                        <div class="stat-line-item">
                            <span style="color: var(--text-muted);">FFmpeg:</span>
                            <span id="stat-ffmpeg-val">加载中...</span>
                            <span id="stat-ffmpeg-badge"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 选项卡导航 -->
        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab('tab-parse')">新建下载任务</button>
            <button class="tab-btn" onclick="switchTab('tab-tasks')">任务列表 (<span id="task-badge">0</span>)</button>
            <button class="tab-btn" onclick="switchTab('tab-config')">全局配置</button>
            <button class="tab-btn" onclick="switchTab('tab-logs')">运行日志</button>
        </div>

        <!-- 选项卡 1: 新建下载任务 -->
        <div id="tab-parse" class="tab-content active">
            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="card-title">新建下载任务</span>
                    <div class="mode-toggle">
                        <button type="button" class="mode-btn active" id="btn-mode-single" onclick="setInputMode('single')">单链接解析</button>
                        <button type="button" class="mode-btn" id="btn-mode-batch" onclick="setInputMode('batch')">批量多行模式</button>
                    </div>
                </div>

                <!-- 单链接输入行 -->
                <div id="row-single-input" style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" id="input-url" class="form-input" style="flex: 1; min-width: 0;" placeholder="输入单个视频或播放列表链接 (如 YouTube, Bilibili, Twitter, Vimeo 等)...">
                    <button class="btn btn-primary" id="btn-parse" onclick="parseUrl()">
                        <span id="btn-parse-text">解析媒体</span>
                    </button>
                </div>

                <!-- 批量多行输入行 -->
                <div id="row-batch-input" style="display: none; flex-direction: column; gap: 8px;">
                    <textarea id="input-batch-urls" class="form-input" style="width: 100%; height: 110px; resize: vertical; font-family: var(--font-mono); font-size: 12px; line-height: 1.5;" placeholder="每行输入一个资源链接，支持从剪贴板直接粘贴多个 URL：&#10;https://www.youtube.com/watch?v=...&#10;https://www.bilibili.com/video/BV..." oninput="updateBatchStats()"></textarea>
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted);">
                        <span id="batch-stats-text">已识别: 0 个有效链接</span>
                        <a href="javascript:void(0)" onclick="clearBatchInput()" style="color: #ef4444; text-decoration: none;">清空输入</a>
                    </div>
                </div>

                <!-- 单链接解析元数据预览区 -->
                <div id="parse-preview-box" style="display: none; margin-top: 18px;">
                    <div class="parse-preview">
                        <img id="meta-thumb" class="preview-thumb" src="" alt="封面">
                        <div class="preview-meta">
                            <h3 id="meta-title">视频标题</h3>
                            <p>作者/发布者: <span id="meta-uploader">--</span></p>
                            <p>时长: <span id="meta-duration">--</span></p>
                            <p style="color: #64748b; margin-top: 6px;" id="meta-desc">--</p>
                        </div>
                    </div>
                </div>

                <!-- 统一下载参数设置面板 -->
                <div id="download-params-box" style="display: none; margin-top: 20px;">
                    <div style="font-size: 13px; font-weight: 600; color: #fff; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                        <span>下载参数设置</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">视频清晰度规格选择</label>
                            <select id="select-quality" class="form-select"></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">合并输出容器格式</label>
                            <select id="select-container" class="form-select">
                                <option value="mp4" selected>MP4 (高兼容性推荐)</option>
                                <option value="mkv">MKV (完整音画轨与多字幕)</option>
                                <option value="webm">WebM (原画流推荐)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">提取纯音频 (可选)</label>
                            <select id="select-audio" class="form-select" onchange="toggleAudioMode(this.value)">
                                <option value="" selected>不提取 (保留完整视频)</option>
                                <option value="mp3">提取并转码为 MP3 (320kbps)</option>
                                <option value="m4a">提取为原声 M4A (无损封包)</option>
                                <option value="flac">提取为 FLAC (无损压缩)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">字幕提取与集成</label>
                            <select id="select-subtitles" class="form-select">
                                <option value="all" selected>下载全部可用字幕 (默认推荐)</option>
                                <option value="zh-Hans,zh,en">优先简中与英文 (zh-Hans, en)</option>
                                <option value="none">不下载字幕</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="width: 100%;">
                            <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>存储目标路径 (QNAP 共享目录)<span id="target-dir-badge" style="color: #38bdf8; font-size: 11px; margin-left: 6px;"></span></span>
                                <a href="javascript:void(0)" onclick="setAsDefaultDir()" style="font-size: 11px; color: #38bdf8; text-decoration: none; cursor: pointer;">设为默认目录</a>
                            </label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="target-dir" class="form-input" style="flex: 1; min-width: 0;" placeholder="/share/Download">
                                <button class="btn btn-outline btn-sm" onclick="browseShares('task')">选择共享卷</button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 20px;">
                        <label class="checkbox-label">
                            <input type="checkbox" id="check-embed-subs" checked>
                            <span>内嵌字幕到视频文件 (--embed-subs)</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="check-embed-thumb" checked>
                            <span>内嵌视频封面图像 (--embed-thumbnail)</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="check-embed-meta" checked>
                            <span>内嵌元数据与章节标签 (--embed-metadata)</span>
                        </label>
                    </div>

                    <button class="btn btn-primary" id="btn-submit-task" style="width: 100%; padding: 12px;" onclick="submitDownloadTask()">
                        立即加入下载队列
                    </button>
                </div>
            </div>
        </div>

        <!-- 选项卡 2: 任务列表 -->
        <div id="tab-tasks" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">任务列表 (<span id="task-card-count">0</span>)</span>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="fetchTasks()">刷新列表</button>
                        <button class="btn btn-outline btn-sm" onclick="clearCompletedTasks()">清空已完成</button>
                    </div>
                </div>
                <div id="tasks-container">
                    <div style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                        当前任务列表中无任何任务
                    </div>
                </div>
            </div>
        </div>

        <!-- 选项卡 3: 全局配置 (包含存储与核心参数、网络代理、站点 Cookies 凭据) -->
        <div id="tab-config" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">存储与全局参数配置</span>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">默认下载存储目录 (QNAP 绝对路径，新任务将自动以此为目标)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="cfg-download-dir" class="form-input" style="flex: 1; min-width: 0;" placeholder="/share/Download">
                            <button class="btn btn-outline btn-sm" onclick="browseShares('config')">选择共享卷</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">文件名输出模板</label>
                        <input type="text" id="cfg-filename-template" class="form-input" placeholder="%(extractor_key)s-%(title)s [%(id)s].%(ext)s">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">最大并行下载任务数</label>
                        <input type="number" id="cfg-max-tasks" class="form-input" min="1" max="10" value="2">
                    </div>
                    <div class="form-group">
                        <label class="form-label">单任务下载限速 (例如 5M 或 500K，留空不限制)</label>
                        <input type="text" id="cfg-rate-limit" class="form-input" placeholder="5M">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">网络代理配置 (HTTP / SOCKS5，如 socks5://192.168.1.1:1080)</label>
                        <input type="text" id="cfg-proxy" class="form-input" placeholder="留空则直连">
                    </div>
                    <div class="form-group">
                        <label class="form-label">自定义 yt-dlp 补充命令行参数</label>
                        <input type="text" id="cfg-custom-args" class="form-input" placeholder="例如 --socket-timeout 30">
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 12px;">
                    <button class="btn btn-primary" onclick="saveConfig()">保存全局配置</button>
                    <button class="btn btn-outline" onclick="updateYtDlp()">检查并更新 yt-dlp 核心组件</button>
                </div>
            </div>

            <!-- 嵌入卡片: 站点账号凭据 (Cookies) -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <span class="card-title">站点账号凭据管理 (Netscape Cookies)</span>
                </div>
                <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 12px;">
                    适用于解析需要会员登录、受年龄限制或防止 1080P+ 防盗链限制的媒体源。请将浏览器导出的标准 Netscape 格式 cookies.txt 文本内容粘贴于下方并保存。
                </p>
                <div class="form-group">
                    <textarea id="cookies-content" class="form-textarea" rows="8" placeholder="# Netscape HTTP Cookie File&#10;# 粘贴 cookies.txt 内容..."></textarea>
                </div>
                <div style="display: flex; gap: 12px;">
                    <button class="btn btn-primary" onclick="saveCookies()">保存凭据文件</button>
                    <button class="btn btn-outline" onclick="loadCookies()">重载凭据</button>
                </div>
            </div>
        </div>

        <!-- 选项卡 5: 守护日志监控 -->
        <div id="tab-logs" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">运行日志</span>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="fetchDaemonLog()">刷新日志</button>
                        <button class="btn btn-outline btn-sm" onclick="clearDaemonLog()">清空日志</button>
                    </div>
                </div>
                <div class="modal-body" style="height: 380px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    <pre class="term-log" id="daemon-log-view">正在载入系统日志...</pre>
                </div>
            </div>
        </div>
    </div>

    <!-- 终端日志弹窗 -->
    <div id="modal-log" class="modal">
        <div class="modal-card">
            <div class="modal-header">
                <span class="card-title" id="log-modal-title">任务日志</span>
                <button class="btn btn-outline btn-sm" onclick="closeLogModal()">关闭</button>
            </div>
            <div class="modal-body">
                <pre class="term-log" id="log-modal-content">正在载入日志...</pre>
            </div>
        </div>
    </div>

    <!-- 共享卷选择弹窗 -->
    <div id="modal-shares" class="modal">
        <div class="modal-card" style="max-width: 500px;">
            <div class="modal-header">
                <span class="card-title">选择 QNAP 共享文件夹</span>
                <button class="btn btn-outline btn-sm" onclick="closeSharesModal()">关闭</button>
            </div>
            <div class="modal-body" id="shares-list">
                正在检索 /share 卷...
            </div>
        </div>
    </div>

    <!-- 媒体详细信息弹窗 (无 Emoji 极简风格) -->
    <div id="modal-media-info" class="modal" onclick="if(event.target===this)closeMediaInfoModal()">
        <div class="modal-card" style="max-width: 820px; max-height: 85vh;">
            <div class="modal-header">
                <span class="card-title" id="media-modal-title">媒体详细规格</span>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-outline btn-sm" id="btn-refresh-media-info" onclick="refreshCurrentMediaInfo()">重新检测</button>
                    <button class="btn btn-outline btn-sm" onclick="closeMediaInfoModal()">关闭</button>
                </div>
            </div>
            <div class="modal-body" id="media-modal-body" style="background: var(--bg-card); padding: 20px;">
                <div style="text-align: center; color: var(--text-muted); padding: 40px 0;">正在检索并分析媒体规格...</div>
            </div>
        </div>
    </div>

    <!-- 浮动 Toast 通知 -->
    <div id="toast" class="toast"></div>

    <script>
        let currentParsedMedia = null;
        let selectedFormatId = "bestvideo+bestaudio/best";
        let taskPollTimer = null;
        let isDaemonRunning = false;

        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').includes(tabId));
            if (activeBtn) activeBtn.classList.add('active');

            const content = document.getElementById(tabId);
            if (content) content.classList.add('active');

            if (tabId === 'tab-tasks') {
                fetchTasks();
            } else if (tabId === 'tab-config') {
                loadConfig();
                loadCookies();
            } else if (tabId === 'tab-logs') {
                fetchDaemonLog();
            }
        }

        function showToast(msg, type = 'normal', duration = 3000) {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast show';
            if (type === 'success') toast.classList.add('toast-success');
            if (type === 'danger') toast.classList.add('toast-danger');
            setTimeout(() => {
                toast.classList.remove('show');
            }, duration);
        }

        async function fetchStatus() {
            try {
                const res = await fetch('api.php?action=status');
                const json = await res.json();
                if (json.code === 0) {
                    const d = json.data;
                    document.getElementById('ver-badge').textContent = d.qpkg_version;

                    const dot = document.getElementById('daemon-dot');
                    const text = document.getElementById('daemon-text');
                    dot.className = 'pulse-dot status-dot-running';
                    text.textContent = '正常运行';

                    // 统计指标渲染
                    const st = d.stats;
                    const dlCountEl = document.getElementById('stat-dl-count');
                    const pendingCountEl = document.getElementById('stat-pending-count');
                    if (dlCountEl) dlCountEl.textContent = st.downloading;
                    if (pendingCountEl) pendingCountEl.textContent = st.pending;

                    document.getElementById('stat-speed').textContent = (st.current_speed && st.current_speed !== '--') ? st.current_speed : '--';

                    // 目标存储空间（双排显示：可用 / 总计，大幅减少横向挤压）
                    const freeEl = document.getElementById('stat-storage-free');
                    const totalEl = document.getElementById('stat-storage-total');
                    if (freeEl) freeEl.textContent = d.free_space_str;
                    if (totalEl) totalEl.textContent = d.total_space_str;

                    // 渲染核心引擎与组件规格（紧凑双行排版）
                    document.getElementById('stat-ytdlp-val').textContent = d.ytdlp_version;
                    document.getElementById('stat-ffmpeg-val').textContent = d.ffmpeg_version;
                    const badgeEl = document.getElementById('stat-ffmpeg-badge');
                    if (d.ffmpeg_source && d.ffmpeg_source !== '未安装') {
                        const isNative = d.ffmpeg_source === '系统原生';
                        badgeEl.className = isNative ? 'engine-badge engine-badge-native' : 'engine-badge';
                        badgeEl.textContent = d.ffmpeg_source;
                        badgeEl.style.display = 'inline-block';
                    } else {
                        badgeEl.style.display = 'none';
                    }

                    if (st && st.total !== undefined) {
                        updateTaskCountBadge(st.total);
                    } else if (cachedTasks) {
                        updateTaskCountBadge(cachedTasks.length);
                    }
                }
            } catch (e) {
                console.error('Fetch status error:', e);
            }
        }

        function updateTaskCountBadge(count) {
            const num = parseInt(count, 10) || 0;
            const badge = document.getElementById('task-badge');
            if (badge) badge.textContent = num;
            const cardCount = document.getElementById('task-card-count');
            if (cardCount) cardCount.textContent = num;
        }


        let currentInputMode = 'single';
        let taskCurrentPage = 1;
        const taskPageSize = 10;
        const expandedTaskIds = new Set();
        const userCollapsedTaskIds = new Set();
        let cachedTasks = [];

        function setInputMode(mode) {
            currentInputMode = mode;
            const btnSingle = document.getElementById('btn-mode-single');
            const btnBatch = document.getElementById('btn-mode-batch');
            const rowSingle = document.getElementById('row-single-input');
            const rowBatch = document.getElementById('row-batch-input');
            const previewBox = document.getElementById('parse-preview-box');
            const paramsBox = document.getElementById('download-params-box');
            const submitBtn = document.getElementById('btn-submit-task');
            const qualitySelect = document.getElementById('select-quality');

            if (mode === 'batch') {
                btnSingle.classList.remove('active');
                btnBatch.classList.add('active');
                rowSingle.style.display = 'none';
                rowBatch.style.display = 'flex';
                previewBox.style.display = 'none';
                paramsBox.style.display = 'block';

                // 批量模式下填充通用画质策略
                qualitySelect.innerHTML = `
                    <option value="bestvideo+bestaudio/best" selected>最高画质 (自适应最佳音视频自动合并 · 推荐)</option>
                    <option value="bestvideo[height<=2160]+bestaudio/best[height<=2160]">4K 超高清 (2160P 及以下)</option>
                    <option value="bestvideo[height<=1080]+bestaudio/best[height<=1080]">1080P 全高清 (推荐 · 兼顾画质与体积)</option>
                    <option value="bestvideo[height<=720]+bestaudio/best[height<=720]">720P 高清 (轻量省流)</option>
                    <option value="bestvideo[height<=480]+bestaudio/best[height<=480]">480P 标清</option>
                `;

                updateBatchStats();
            } else {
                btnBatch.classList.remove('active');
                btnSingle.classList.add('active');
                rowBatch.style.display = 'none';
                rowSingle.style.display = 'flex';

                if (currentParsedMedia) {
                    previewBox.style.display = 'block';
                    paramsBox.style.display = 'block';
                    submitBtn.innerHTML = '立即加入下载队列';
                    renderParseResult(currentParsedMedia);
                } else {
                    previewBox.style.display = 'none';
                    paramsBox.style.display = 'none';
                }
            }
        }

        function getBatchUrls() {
            const textarea = document.getElementById('input-batch-urls');
            if (!textarea) return [];
            return textarea.value.split('\n')
                .map(u => u.trim())
                .filter(u => u.length > 8 && /^https?:\/\//i.test(u));
        }

        function updateBatchStats() {
            if (currentInputMode !== 'batch') return;
            const urls = getBatchUrls();
            const statsText = document.getElementById('batch-stats-text');
            const submitBtn = document.getElementById('btn-submit-task');
            if (statsText) {
                statsText.textContent = `已识别: ${urls.length} 个有效链接`;
            }
            if (submitBtn) {
                submitBtn.innerHTML = `批量加入下载队列 (已识别 ${urls.length} 个链接)`;
            }
        }

        function clearBatchInput() {
            const textarea = document.getElementById('input-batch-urls');
            if (textarea) textarea.value = '';
            updateBatchStats();
        }

        async function parseUrl() {
            const urlInput = document.getElementById('input-url');
            const url = urlInput.value.trim();
            if (!url) {
                showToast('请输入媒体源链接', 'danger');
                return;
            }

            const btn = document.getElementById('btn-parse');
            const btnText = document.getElementById('btn-parse-text');
            btn.disabled = true;
            btnText.textContent = '正在解析...';

            try {
                const res = await fetch('api.php?action=parse_url', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({url: url})
                });
                const json = await res.json();
                if (json.code === 0) {
                    currentParsedMedia = json.data;
                    renderParseResult(json.data);
                    showToast('媒体源解析完成', 'success');
                } else {
                    showToast('解析失败: ' + json.message, 'danger');
                }
            } catch (e) {
                showToast('网络请求异常: ' + e.message, 'danger');
            } finally {
                btn.disabled = false;
                btnText.textContent = '解析媒体';
            }
        }

        function renderParseResult(data) {
            document.getElementById('parse-preview-box').style.display = 'block';
            document.getElementById('download-params-box').style.display = 'block';
            document.getElementById('meta-thumb').src = data.thumbnail || 'static/favicon.png';
            document.getElementById('meta-title').textContent = data.title;
            document.getElementById('meta-uploader').textContent = data.uploader;
            document.getElementById('meta-duration').textContent = formatDuration(data.duration);
            document.getElementById('meta-desc').textContent = data.description || '暂无说明';

            const qualitySelect = document.getElementById('select-quality');
            qualitySelect.innerHTML = '';

            const bestOpt = document.createElement('option');
            bestOpt.value = 'bestvideo+bestaudio/best';
            bestOpt.textContent = '最佳画质 (自动音画合并 · 推荐)';
            qualitySelect.appendChild(bestOpt);

            (data.resolutions || []).forEach(r => {
                const opt = document.createElement('option');
                opt.value = `bestvideo[height<=${r.height}]+bestaudio/best[height<=${r.height}]`;
                opt.textContent = `${r.label} [${r.filesize_str}]`;
                qualitySelect.appendChild(opt);
            });

            const subSelect = document.getElementById('select-subtitles');
            subSelect.innerHTML = '<option value="all" selected>下载全部可用字幕 (默认推荐)</option><option value="zh-Hans,zh,en">优先简中与英文 (zh-Hans, en)</option><option value="none">不下载字幕</option>';
            if (data.subtitles && data.subtitles.length > 0) {
                data.subtitles.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.code;
                    opt.textContent = `${s.name} (${s.code})`;
                    subSelect.appendChild(opt);
                });
            }

            const submitBtn = document.getElementById('btn-submit-task');
            if (submitBtn) {
                submitBtn.innerHTML = '立即加入下载队列';
            }
        }

        function toggleAudioMode(val) {
            const containerSelect = document.getElementById('select-container');
            const qualitySelect = document.getElementById('select-quality');
            if (val) {
                containerSelect.disabled = true;
                qualitySelect.disabled = true;
                qualitySelect.style.opacity = '0.5';
            } else {
                containerSelect.disabled = false;
                qualitySelect.disabled = false;
                qualitySelect.style.opacity = '1';
            }
        }

        async function submitDownloadTask() {
            const submitBtn = document.getElementById('btn-submit-task');
            if (submitBtn && submitBtn.disabled) return;

            const audioMode = document.getElementById('select-audio').value;
            const containerVal = (document.getElementById('select-container').value || 'mp4').toUpperCase();
            const formatVal = document.getElementById('select-quality').value || 'bestvideo+bestaudio/best';
            const qualityText = document.getElementById('select-quality').selectedOptions[0]?.textContent || '最佳画质';
            const cleanQualityText = qualityText.replace(/\s*\(.*?\)/g, '').replace(/\s*\[.*?\]/g, '').trim();
            const subtitlesVal = document.getElementById('select-subtitles').value || 'all';
            const embedSubs = document.getElementById('check-embed-subs').checked;
            const embedThumb = document.getElementById('check-embed-thumb').checked;
            const embedMeta = document.getElementById('check-embed-meta').checked;
            const downloadDir = document.getElementById('target-dir').value;

            let specStr = audioMode ? `${audioMode.toUpperCase()} · 纯音频` : `${cleanQualityText} · ${containerVal}`;

            if (currentInputMode === 'batch') {
                const urls = getBatchUrls();
                if (urls.length === 0) {
                    showToast('请在文本框中输入至少一个有效的媒体链接', 'danger');
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '正在批量加入队列...';
                }

                const batchPayload = {
                    urls: urls,
                    media_spec: specStr,
                    format_id: formatVal,
                    container: document.getElementById('select-container').value,
                    is_audio_only: !!audioMode,
                    audio_format: audioMode,
                    subtitles: subtitlesVal,
                    embed_subtitles: embedSubs,
                    embed_thumbnail: embedThumb,
                    embed_metadata: embedMeta,
                    download_dir: downloadDir
                };

                try {
                    const res = await fetch('api.php?action=add_batch_tasks', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(batchPayload)
                    });
                    const json = await res.json();
                    if (json.code === 0) {
                        showToast(json.message || `成功添加 ${urls.length} 个任务`, 'success');
                        clearBatchInput();
                        switchTab('tab-tasks');
                    } else {
                        showToast('批量添加失败: ' + json.message, 'danger');
                    }
                } catch (e) {
                    showToast('提交失败: ' + e.message, 'danger');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        updateBatchStats();
                    }
                }
            } else {
                if (!currentParsedMedia) {
                    showToast('请先解析媒体链接', 'danger');
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '正在加入队列...';
                }

                const platform = currentParsedMedia.platform || '网络媒体';
                const rawTitle = currentParsedMedia.title || '媒体任务';
                const standardTitle = `${platform} - ${rawTitle} - ${specStr}`;

                const payload = {
                    url: currentParsedMedia.webpage_url,
                    title: standardTitle,
                    platform: platform,
                    media_spec: specStr,
                    thumbnail: currentParsedMedia.thumbnail,
                    duration: currentParsedMedia.duration,
                    format_id: formatVal,
                    container: document.getElementById('select-container').value,
                    is_audio_only: !!audioMode,
                    audio_format: audioMode,
                    subtitles: subtitlesVal,
                    embed_subtitles: embedSubs,
                    embed_thumbnail: embedThumb,
                    embed_metadata: embedMeta,
                    download_dir: downloadDir
                };

                try {
                    const res = await fetch('api.php?action=add_task', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (json.code === 0) {
                        showToast('任务已成功加入下载队列', 'success');
                        document.getElementById('input-url').value = '';
                        currentParsedMedia = null;
                        document.getElementById('parse-preview-box').style.display = 'none';
                        document.getElementById('download-params-box').style.display = 'none';
                        switchTab('tab-tasks');
                    } else {
                        showToast('添加失败: ' + json.message, 'danger');
                    }
                } catch (e) {
                    showToast('提交失败: ' + e.message, 'danger');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '立即加入下载队列';
                    }
                }
            }
        }

        async function fetchTasks() {
            try {
                const res = await fetch('api.php?action=get_tasks');
                const json = await res.json();
                if (json.code === 0) {
                    cachedTasks = json.data || [];
                    updateTaskCountBadge(cachedTasks.length);
                    // 交互保护：若用户当前正在划选任务列表中的文本，暂缓粗暴清空重绘 DOM，避免打断划选
                    const sel = window.getSelection();
                    if (sel && sel.toString().trim().length > 0) {
                        return;
                    }
                    renderTasks(cachedTasks);
                }
            } catch (e) {
                console.error('Fetch tasks error:', e);
            }
        }

        function copyTaskTitle(e, title) {
            if (e) {
                e.stopPropagation();
                e.preventDefault();
            }
            if (!title) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(title).then(() => {
                    showToast('标题已复制到剪贴板', 'success');
                }).catch(() => {
                    fallbackCopyText(title);
                });
            } else {
                fallbackCopyText(title);
            }
        }

        function fallbackCopyText(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            ta.style.top = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try {
                document.execCommand('copy');
                showToast('标题已复制到剪贴板', 'success');
            } catch (err) {
                showToast('复制失败，请手动选择', 'danger');
            }
            document.body.removeChild(ta);
        }

        function toggleTaskCollapse(taskId) {
            const card = document.querySelector(`.task-item[data-task-id="${taskId}"]`);
            const isCurrentlyCollapsed = card ? card.classList.contains('collapsed') : false;

            if (isCurrentlyCollapsed) {
                userCollapsedTaskIds.delete(taskId);
                expandedTaskIds.add(taskId);
            } else {
                expandedTaskIds.delete(taskId);
                userCollapsedTaskIds.add(taskId);
            }
            renderTasks(cachedTasks);
        }

        function handleTaskCardClick(e, taskId) {
            // 如果用户正在划选文字（鼠标拖拽高亮文字），松开鼠标时不触发折叠
            const sel = window.getSelection();
            if (sel && sel.toString().trim().length > 0) {
                return;
            }
            // 如果点击的是按钮、链接、输入框或复制控件，不触发折叠切换
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('.btn-copy-title')) {
                return;
            }
            toggleTaskCollapse(taskId);
        }

        function goToTaskPage(pageNum) {
            taskCurrentPage = pageNum;
            renderTasks(cachedTasks);
        }

        function renderTasks(tasks) {
            const container = document.getElementById('tasks-container');
            if (!tasks || tasks.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 40px 0;">当前任务列表中无任何任务</div>';
                return;
            }

            const totalTasks = tasks.length;
            const totalPages = Math.max(1, Math.ceil(totalTasks / taskPageSize));
            if (taskCurrentPage > totalPages) {
                taskCurrentPage = totalPages;
            }

            const startIndex = (taskCurrentPage - 1) * taskPageSize;
            const pageTasks = tasks.slice(startIndex, startIndex + taskPageSize);

            container.innerHTML = '';
            pageTasks.forEach((t, pageIdx) => {
                const globalIdx = startIndex + pageIdx;
                const item = document.createElement('div');
                item.className = 'task-item';
                item.dataset.taskId = t.id;

                // 折叠逻辑：
                // 1. 多个下载中/合并中的活跃任务 (downloading / merging) 默认绝对不折叠，实时显示进度
                // 2. 非活跃任务（已完成、排队、失败、已暂停）：除全局最新任务（第1个）外，默认折叠收拢
                // 3. 用户手动点击折叠/展开的状态优先级最高
                const isActive = (t.status === 'downloading' || t.status === 'merging');
                const isUserCollapsed = userCollapsedTaskIds.has(t.id);
                const isUserExpanded = expandedTaskIds.has(t.id);

                let shouldCollapse = false;
                if (totalTasks > 1) {
                    if (isActive) {
                        shouldCollapse = isUserCollapsed;
                    } else {
                        shouldCollapse = isUserCollapsed || (globalIdx !== 0 && !isUserExpanded);
                    }
                }

                if (shouldCollapse) {
                    item.classList.add('collapsed');
                }

                item.onclick = (e) => handleTaskCardClick(e, t.id);

                let statusColor = '#94a3b8';
                let statusLabel = '排队中';
                if (t.status === 'downloading') {
                    statusColor = '#10b981';
                    statusLabel = '下载中';
                } else if (t.status === 'merging') {
                    statusColor = '#0ea5e9';
                    statusLabel = '音画转码合并中';
                } else if (t.status === 'completed') {
                    statusColor = '#3b82f6';
                    statusLabel = '已完成';
                } else if (t.status === 'failed') {
                    statusColor = '#ef4444';
                    statusLabel = '失败';
                } else if (t.status === 'paused') {
                    statusColor = '#f59e0b';
                    statusLabel = '已暂停';
                }

                let progress = parseFloat(t.progress) || 0;
                if (t.status === 'completed') {
                    progress = 100;
                } else if (t.status === 'merging') {
                    progress = Math.max(99.5, progress);
                }

                let infoRowHtml = '';
                const hasMultiFiles = t.file_count_str && (t.file_count_total > 1 || t.file_count_str !== '1/1');
                let fileCountBadgeHtml = '';
                if (hasMultiFiles) {
                    let badgeColor = (t.status === 'completed') ? '#10b981' : '#38bdf8';
                    let badgeBg = (t.status === 'completed') ? 'rgba(16,185,129,0.12)' : 'rgba(56,189,248,0.12)';
                    let badgeBorder = (t.status === 'completed') ? 'rgba(16,185,129,0.3)' : 'rgba(56,189,248,0.3)';
                    fileCountBadgeHtml = `
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; font-weight: 500;">
                            文件完成数: ${escapeHtml(t.file_count_str)}
                        </span>
                    `;
                }

                if (t.status === 'completed') {
                    const totalSz = (t.total_size && t.total_size !== '--') ? t.total_size : (t.downloaded || '完整');
                    const spd = (t.final_speed && t.final_speed !== '--') ? t.final_speed : (t.speed || '--');
                    const cost = t.time_cost_str || '--';
                    infoRowHtml = `
                        <span>大小: <strong style="color: var(--text-main);">${escapeHtml(totalSz)}</strong></span>
                        <span>平均速率: <strong style="color: #10b981;">${escapeHtml(spd)}</strong></span>
                        <span>耗时: <strong style="color: #38bdf8;">${escapeHtml(cost)}</strong></span>
                    `;
                } else if (t.status === 'downloading' || t.status === 'merging') {
                    let currentDl = t.current_file_dl || t.downloaded;
                    let currentTot = t.current_file_total || t.total_size;
                    if (t.status === 'merging' && currentTot && currentTot !== '--') {
                        currentDl = currentTot;
                    }
                    const dlSizeStr = (currentDl && currentTot && currentTot !== '--') ? ` (${escapeHtml(currentDl)} / ${escapeHtml(currentTot)})` : '';
                    const currentSpd = (t.status === 'merging') ? '转码合并中' : (t.speed || '--');
                    const currentEta = (t.status === 'merging') ? '处理中' : (t.eta || '--');
                    const stageStr = t.file_label ? `<span>当前阶段: <strong style="color: #38bdf8;">${escapeHtml(t.file_label)}</strong></span>` : '';
                    infoRowHtml = `
                        ${stageStr}
                        <span>当前进度: <strong style="color: var(--text-main);">${progress.toFixed(1)}%${dlSizeStr}</strong></span>
                        <span>速率: <strong style="color: #10b981;">${escapeHtml(currentSpd)}</strong></span>
                        <span>剩余: <strong style="color: #38bdf8;">${escapeHtml(currentEta)}</strong></span>
                    `;
                } else {
                    infoRowHtml = `
                        <span>进度: ${progress.toFixed(1)}%</span>
                        <span>状态: ${statusLabel}</span>
                        <span>格式: ${escapeHtml(t.container || 'mp4').toUpperCase()}</span>
                    `;
                }

                item.innerHTML = `
                    <div class="task-top">
                        <div style="flex: 1; min-width: 0;">
                            <div class="task-title-row">
                                <span class="task-title" title="${escapeHtml(t.title)}">${escapeHtml(t.title)}</span>
                                <button class="btn-copy-title" title="复制完整标题" data-title="${escapeHtml(t.title)}" onclick="copyTaskTitle(event, this.getAttribute('data-title'))">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </button>
                            </div>
                            <div class="task-subtitle">存储目录: ${escapeHtml(t.download_dir || '--')}</div>
                        </div>
                        <div style="text-align: right; display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                            ${fileCountBadgeHtml}
                            <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; background: rgba(255,255,255,0.06); color: ${statusColor};">
                                ${statusLabel}
                            </span>
                            <span class="task-toggle-icon">▼</span>
                        </div>
                    </div>
                    <div class="task-progress-bar">
                        <div class="task-progress-inner" style="width: ${progress}%;"></div>
                    </div>
                    <div class="task-info-row">
                        ${infoRowHtml}
                    </div>
                    ${t.error_message ? `<div class="task-err-msg" style="font-size: 11px; color: #ef4444; background: rgba(239,68,68,0.1); padding: 6px 10px; border-radius: 4px; word-break: break-all;">${escapeHtml(t.error_message)}</div>` : ''}
                    <div class="task-actions">
                        ${t.status === 'completed' ? `<button class="btn btn-outline btn-sm" onclick="viewMediaInfo('${t.id}')">媒体信息</button>` : ''}
                        ${t.status === 'downloading' ? `<button class="btn btn-outline btn-sm" onclick="pauseTask('${t.id}')">暂停</button>` : ''}
                        ${t.status === 'paused' ? `<button class="btn btn-outline btn-sm" onclick="resumeTask('${t.id}')">继续</button>` : ''}
                        ${t.status === 'failed' ? `<button class="btn btn-outline btn-sm" onclick="retryTask('${t.id}')">重试</button>` : ''}
                        <button class="btn btn-outline btn-sm" onclick="viewTaskLog('${t.id}')">任务日志</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteTask('${t.id}')">删除任务</button>
                    </div>
                `;
                container.appendChild(item);
            });

            // 渲染底部分页器
            if (totalPages > 1) {
                const paginationEl = document.createElement('div');
                paginationEl.className = 'pagination-container';

                let pageButtonsHtml = `
                    <button class="page-btn" ${taskCurrentPage <= 1 ? 'disabled' : ''} onclick="goToTaskPage(${taskCurrentPage - 1})">上一页</button>
                `;

                for (let p = 1; p <= totalPages; p++) {
                    pageButtonsHtml += `
                        <button class="page-btn ${p === taskCurrentPage ? 'active' : ''}" onclick="goToTaskPage(${p})">${p}</button>
                    `;
                }

                pageButtonsHtml += `
                    <button class="page-btn" ${taskCurrentPage >= totalPages ? 'disabled' : ''} onclick="goToTaskPage(${taskCurrentPage + 1})">下一页</button>
                `;

                paginationEl.innerHTML = `
                    <div>共 <strong>${totalTasks}</strong> 个下载任务 · 第 <strong>${taskCurrentPage}</strong> / <strong>${totalPages}</strong> 页</div>
                    <div class="pagination-buttons">
                        ${pageButtonsHtml}
                    </div>
                `;
                container.appendChild(paginationEl);
            }
        }

        async function pauseTask(taskId) {
            await fetch('api.php?action=pause_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: taskId})
            });
            fetchTasks();
        }

        async function resumeTask(taskId) {
            await fetch('api.php?action=resume_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: taskId})
            });
            fetchTasks();
        }

        async function retryTask(taskId) {
            await fetch('api.php?action=retry_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: taskId})
            });
            fetchTasks();
        }

        async function deleteTask(taskId) {
            await fetch('api.php?action=delete_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: taskId})
            });
            fetchTasks();
        }

        async function clearCompletedTasks() {
            await fetch('api.php?action=clear_completed');
            fetchTasks();
        }

        async function viewTaskLog(taskId) {
            document.getElementById('modal-log').classList.add('show');
            document.getElementById('log-modal-content').textContent = '正在获取任务输出日志...';
            try {
                const res = await fetch(`api.php?action=get_task_log&task_id=${taskId}`);
                const json = await res.json();
                if (json.code === 0) {
                    document.getElementById('log-modal-content').textContent = json.data.log;
                }
            } catch (e) {
                document.getElementById('log-modal-content').textContent = '读取日志失败: ' + e.message;
            }
        }

        function closeLogModal() {
            document.getElementById('modal-log').classList.remove('show');
        }

        let currentMediaInfoTaskId = null;

        async function viewMediaInfo(taskId, forceRefresh = false) {
            currentMediaInfoTaskId = taskId;
            const modal = document.getElementById('modal-media-info');
            const body = document.getElementById('media-modal-body');
            const titleEl = document.getElementById('media-modal-title');
            const btnRefresh = document.getElementById('btn-refresh-media-info');

            if (btnRefresh) {
                btnRefresh.disabled = true;
                btnRefresh.textContent = '检测中...';
            }

            if (!forceRefresh) {
                titleEl.textContent = '媒体详细规格';
                body.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 40px 0;">正在检索并分析媒体规格...</div>';
            }
            modal.classList.add('show');

            try {
                const url = `api.php?action=get_media_info&task_id=${encodeURIComponent(taskId)}${forceRefresh ? '&refresh=1' : ''}`;
                const res = await fetch(url);
                const json = await res.json();

                if (btnRefresh) {
                    btnRefresh.disabled = false;
                    btnRefresh.textContent = '重新检测';
                }

                if (json.code !== 0) {
                    body.innerHTML = `
                        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 6px; padding: 16px; color: #ef4444; font-size: 13px;">
                            <strong>获取媒体信息失败:</strong> ${escapeHtml(json.message || '未知错误')}
                        </div>
                    `;
                    return;
                }

                const d = json.data;
                titleEl.textContent = '媒体详细规格 - ' + d.file_name;

                let html = '';

                // 1. 基本规格
                html += `
                    <div class="media-section-title">基本规格信息</div>
                    <div class="media-specs-grid">
                        <div class="media-spec-item">
                            <div class="media-spec-label">文件名称</div>
                            <div class="media-spec-val" title="${escapeHtml(d.file_name)}">${escapeHtml(d.file_name)}</div>
                        </div>
                        <div class="media-spec-item">
                            <div class="media-spec-label">存储路径</div>
                            <div class="media-spec-val" title="${escapeHtml(d.file_path)}">${escapeHtml(d.file_path)}</div>
                        </div>
                        <div class="media-spec-item">
                            <div class="media-spec-label">文件大小</div>
                            <div class="media-spec-val">${escapeHtml(d.file_size)}</div>
                        </div>
                        <div class="media-spec-item">
                            <div class="media-spec-label">封装格式</div>
                            <div class="media-spec-val">${escapeHtml(d.format_name)}</div>
                        </div>
                        <div class="media-spec-item">
                            <div class="media-spec-label">媒体时长</div>
                            <div class="media-spec-val">${escapeHtml(d.duration_str)}</div>
                        </div>
                        <div class="media-spec-item">
                            <div class="media-spec-label">综合码率</div>
                            <div class="media-spec-val">${escapeHtml(d.bit_rate_str)}</div>
                        </div>
                    </div>
                `;

                // 2. 视频轨
                html += `<div class="media-section-title">视频轨 (Video Stream)</div>`;
                if (d.video_streams && d.video_streams.length > 0) {
                    d.video_streams.forEach(v => {
                        html += `
                            <div class="media-stream-card">
                                <div class="media-stream-header">
                                    <span class="media-stream-badge">视频流 #${v.index}</span>
                                    <span style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);">${escapeHtml(v.codec)}</span>
                                </div>
                                <div class="media-stream-props">
                                    <div><span class="media-prop-key">分辨率: </span><span class="media-prop-val">${escapeHtml(v.resolution)}</span></div>
                                    <div><span class="media-prop-key">帧率: </span><span class="media-prop-val">${escapeHtml(v.fps)}</span></div>
                                    <div><span class="media-prop-key">视频码率: </span><span class="media-prop-val">${escapeHtml(v.bit_rate)}</span></div>
                                    <div><span class="media-prop-key">像素格式: </span><span class="media-prop-val">${escapeHtml(v.pix_fmt)}</span></div>
                                    <div><span class="media-prop-key">显示宽高比: </span><span class="media-prop-val">${escapeHtml(v.aspect_ratio)}</span></div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += `<div style="font-size: 12px; color: var(--text-muted); padding: 8px 0;">未检测到独立视频流</div>`;
                }

                // 3. 音频轨
                html += `<div class="media-section-title">音频轨 (Audio Stream)</div>`;
                if (d.audio_streams && d.audio_streams.length > 0) {
                    d.audio_streams.forEach(a => {
                        html += `
                            <div class="media-stream-card">
                                <div class="media-stream-header">
                                    <span class="media-stream-badge audio">音频流 #${a.index}</span>
                                    <span style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);">${escapeHtml(a.codec)}</span>
                                </div>
                                <div class="media-stream-props">
                                    <div><span class="media-prop-key">声道配置: </span><span class="media-prop-val">${escapeHtml(a.channels)}</span></div>
                                    <div><span class="media-prop-key">采样率: </span><span class="media-prop-val">${escapeHtml(a.sample_rate)}</span></div>
                                    <div><span class="media-prop-key">音频码率: </span><span class="media-prop-val">${escapeHtml(a.bit_rate)}</span></div>
                                    <div><span class="media-prop-key">语言标识: </span><span class="media-prop-val">${escapeHtml(a.language)}</span></div>
                                    ${a.title ? `<div><span class="media-prop-key">音轨标题: </span><span class="media-prop-val">${escapeHtml(a.title)}</span></div>` : ''}
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += `<div style="font-size: 12px; color: var(--text-muted); padding: 8px 0;">未检测到独立音频流</div>`;
                }

                // 4. 字幕轨
                html += `<div class="media-section-title">字幕轨 (Subtitle Stream)</div>`;
                if (d.subtitle_streams && d.subtitle_streams.length > 0) {
                    d.subtitle_streams.forEach(s => {
                        html += `
                            <div class="media-stream-card">
                                <div class="media-stream-header">
                                    <span class="media-stream-badge subtitle">字幕流 #${s.index}</span>
                                    <span style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);">${escapeHtml(s.codec)}</span>
                                </div>
                                <div class="media-stream-props">
                                    <div><span class="media-prop-key">语言标识: </span><span class="media-prop-val">${escapeHtml(s.language)}</span></div>
                                    <div><span class="media-prop-key">字幕标题: </span><span class="media-prop-val">${escapeHtml(s.title)}</span></div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += `<div style="font-size: 12px; color: var(--text-muted); padding: 8px 0;">未检测到内嵌字幕流</div>`;
                }

                body.innerHTML = html;
            } catch (err) {
                if (btnRefresh) {
                    btnRefresh.disabled = false;
                    btnRefresh.textContent = '重新检测';
                }
                body.innerHTML = `
                    <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 6px; padding: 16px; color: #ef4444; font-size: 13px;">
                        <strong>请求失败:</strong> ${escapeHtml(err.message)}
                    </div>
                `;
            }
        }

        function refreshCurrentMediaInfo() {
            if (currentMediaInfoTaskId) {
                viewMediaInfo(currentMediaInfoTaskId, true);
            }
        }

        function closeMediaInfoModal() {
            document.getElementById('modal-media-info').classList.remove('show');
        }

        let shareTargetInputType = 'task';

        async function browseShares(type = 'task') {
            shareTargetInputType = type;
            document.getElementById('modal-shares').classList.add('show');
            const listEl = document.getElementById('shares-list');
            listEl.innerHTML = '正在读取 QNAP /share 卷...';
            try {
                const res = await fetch('api.php?action=browse_shares');
                const json = await res.json();
                if (json.code === 0 && json.data.length > 0) {
                    let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
                    json.data.forEach(s => {
                        html += `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #172033; border-radius: 6px;">
                                <div>
                                    <div style="font-weight: 600; color: #fff;">${escapeHtml(s.name)}</div>
                                    <div style="font-size: 11px; color: var(--text-muted);">${escapeHtml(s.path)} (${s.free_space_str} 可用)</div>
                                </div>
                                <button class="btn btn-outline btn-sm" onclick="selectSharePath('${escapeHtml(s.path)}')">选择</button>
                            </div>
                        `;
                    });
                    html += '</div>';
                    listEl.innerHTML = html;
                } else {
                    listEl.innerHTML = '未扫描到可用的共享目录';
                }
            } catch (e) {
                listEl.innerHTML = '获取共享目录失败: ' + e.message;
            }
        }

        function selectSharePath(p) {
            const finalPath = p;
            if (shareTargetInputType === 'config') {
                document.getElementById('cfg-download-dir').value = finalPath;
            } else {
                document.getElementById('target-dir').value = finalPath;
            }
            closeSharesModal();
        }

        function closeSharesModal() {
            document.getElementById('modal-shares').classList.remove('show');
        }

        async function setAsDefaultDir() {
            const dir = document.getElementById('target-dir').value.trim();
            if (!dir) {
                showToast('目标存储路径不能为空', 'danger');
                return;
            }

            try {
                const res = await fetch('api.php?action=save_config', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({download_dir: dir})
                });
                const json = await res.json();
                if (json.code === 0) {
                    document.getElementById('cfg-download-dir').value = dir;
                    document.getElementById('target-dir-badge').textContent = `(当前默认)`;
                    showToast('已设为全局默认目录，后续所有下载均默认保存至此处', 'success');
                    fetchStatus();
                } else {
                    showToast('设置失败: ' + json.message, 'danger');
                }
            } catch (e) {
                showToast('设置失败: ' + e.message, 'danger');
            }
        }

        async function loadConfig() {
            try {
                const res = await fetch('api.php?action=get_config');
                const json = await res.json();
                if (json.code === 0) {
                    const c = json.data;
                    document.getElementById('cfg-download-dir').value = c.download_dir || '';
                    document.getElementById('cfg-filename-template').value = c.filename_template || '';
                    document.getElementById('cfg-max-tasks').value = c.max_concurrent_tasks || 2;
                    document.getElementById('cfg-rate-limit').value = c.rate_limit || '';
                    document.getElementById('cfg-proxy').value = c.proxy || '';
                    document.getElementById('cfg-custom-args').value = c.custom_args || '';

                    const defDir = c.download_dir || '/share/Download';
                    document.getElementById('target-dir').value = defDir;
                    document.getElementById('target-dir-badge').textContent = `(默认: ${defDir})`;
                }
            } catch (e) {
                console.error('Load config error:', e);
            }
        }

        async function saveConfig() {
            const payload = {
                download_dir: document.getElementById('cfg-download-dir').value.trim(),
                filename_template: document.getElementById('cfg-filename-template').value.trim(),
                max_concurrent_tasks: parseInt(document.getElementById('cfg-max-tasks').value) || 2,
                rate_limit: document.getElementById('cfg-rate-limit').value.trim(),
                proxy: document.getElementById('cfg-proxy').value.trim(),
                custom_args: document.getElementById('cfg-custom-args').value.trim()
            };

            try {
                const res = await fetch('api.php?action=save_config', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.code === 0) {
                    showToast('全局配置已成功保存', 'success');
                } else {
                    showToast('保存失败: ' + json.message, 'danger');
                }
            } catch (e) {
                showToast('保存异常: ' + e.message, 'danger');
            }
        }

        async function updateYtDlp() {
            showToast('正在向官方源请求更新核心组件，请稍候...');
            try {
                const res = await fetch('api.php?action=update_ytdlp');
                const json = await res.json();
                if (json.code === 0) {
                    showToast('更新指令执行完毕', 'success');
                    fetchStatus();
                } else {
                    showToast('更新失败: ' + json.message, 'danger');
                }
            } catch (e) {
                showToast('更新指令异常: ' + e.message, 'danger');
            }
        }

        async function loadCookies() {
            try {
                const res = await fetch('api.php?action=get_cookies');
                const json = await res.json();
                if (json.code === 0) {
                    document.getElementById('cookies-content').value = json.data.cookies || '';
                }
            } catch (e) {
                console.error('Load cookies error:', e);
            }
        }

        async function saveCookies() {
            const content = document.getElementById('cookies-content').value;
            try {
                const res = await fetch('api.php?action=save_cookies', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({cookies: content})
                });
                const json = await res.json();
                if (json.code === 0) {
                    showToast('Cookies 凭据保存成功', 'success');
                } else {
                    showToast('保存失败: ' + json.message, 'danger');
                }
            } catch (e) {
                showToast('保存异常: ' + e.message, 'danger');
            }
        }

        async function fetchDaemonLog() {
            const logPre = document.getElementById('daemon-log-view');
            try {
                const res = await fetch('api.php?action=get_daemon_log');
                const json = await res.json();
                if (json.code === 0) {
                    logPre.textContent = json.data.log;
                }
            } catch (e) {
                logPre.textContent = '获取日志失败: ' + e.message;
            }
        }

        async function clearDaemonLog() {
            try {
                await fetch('api.php?action=clear_daemon_log');
                fetchDaemonLog();
                showToast('守护日志已清空', 'success');
            } catch (e) {
                showToast('清空日志失败: ' + e.message, 'danger');
            }
        }

        function formatDuration(sec) {
            sec = parseInt(sec) || 0;
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            if (h > 0) {
                return `${h}:${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
            }
            return `${m}:${s < 10 ? '0' : ''}${s}`;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m];
            });
        }

        window.onload = function() {
            fetchStatus();
            loadConfig();
            fetchTasks();
            taskPollTimer = setInterval(() => {
                const tasksTabActive = document.getElementById('tab-tasks').classList.contains('active');
                if (tasksTabActive) {
                    fetchTasks();
                }
                fetchStatus();
            }, 2500);
        };
    </script>
</body>
</html>
