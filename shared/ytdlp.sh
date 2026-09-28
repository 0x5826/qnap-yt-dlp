#!/bin/sh
######################################################################
# QNAP QPKG Control Script for yt-dlp
######################################################################

CONF=/etc/config/qpkg.conf
QPKG_NAME="ytdlp"

if [ -x "/sbin/getcfg" ]; then
    QPKG_ROOT=$(/sbin/getcfg $QPKG_NAME Install_Path -f ${CONF} 2>/dev/null)
fi
if [ -z "$QPKG_ROOT" ] || [ ! -d "$QPKG_ROOT" ]; then
    QPKG_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fi

BIN_DIR="$QPKG_ROOT/bin"
CONF_DIR="$QPKG_ROOT/conf"
LOGS_DIR="$CONF_DIR/logs"
PID_FILE="$CONF_DIR/ytdlp_daemon.pid"
DAEMON_LOG="$LOGS_DIR/daemon.log"

export PATH="$BIN_DIR:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"

mkdir -p "$BIN_DIR" "$CONF_DIR" "$LOGS_DIR" 2>/dev/null

######################################################################
# 架构适配与软链接挂载
######################################################################
setup_arch_binaries() {
    local arch=$(uname -m)
    local src_dir=""
    case "$arch" in
        x86_64|amd64)
            src_dir="$QPKG_ROOT/x86_64"
            ;;
        aarch64|arm64|armv8*)
            src_dir="$QPKG_ROOT/arm_64"
            ;;
        *)
            src_dir="$QPKG_ROOT/x86_64"
            ;;
    esac

    if [ -d "$src_dir" ]; then
        for b in yt-dlp ffmpeg ffprobe; do
            if [ -f "$src_dir/$b" ]; then
                chmod +x "$src_dir/$b" 2>/dev/null || true
                ln -sf "$src_dir/$b" "$BIN_DIR/$b" 2>/dev/null || true
            elif [ -f "$QPKG_ROOT/$b" ]; then
                chmod +x "$QPKG_ROOT/$b" 2>/dev/null || true
                ln -sf "$QPKG_ROOT/$b" "$BIN_DIR/$b" 2>/dev/null || true
            fi
        done
    fi

    # 暴露 CLI 工具至系统路径
    if [ -f "$BIN_DIR/yt-dlp" ]; then
        ln -sf "$BIN_DIR/yt-dlp" /usr/bin/yt-dlp 2>/dev/null || true
    fi
}

######################################################################
# Web 目录原子软链接挂载
######################################################################
link_webui() {
    local web_src="$QPKG_ROOT/web"
    [ ! -d "$web_src" ] && return 0

    local target_web=""
    for d in /share/Web /share/Qweb /share/CACHEDEV1_DATA/Web /share/HDA_DATA/Web; do
        if [ -d "$d" ]; then
            target_web="$d"
            break
        fi
    done

    [ -z "$target_web" ] && return 0

    # 采用原子临时链接替换，消除 404 空窗
    ln -sfn "$web_src" "$target_web/.${QPKG_NAME}_tmp.$$" 2>/dev/null && \
    mv -Tf "$target_web/.${QPKG_NAME}_tmp.$$" "$target_web/$QPKG_NAME" 2>/dev/null || \
    (rm -rf "$target_web/$QPKG_NAME" 2>/dev/null; ln -sf "$web_src" "$target_web/$QPKG_NAME" 2>/dev/null)
}

######################################################################
# 系统图标自愈同步
######################################################################
sync_system_icons() {
    local icon_src="$QPKG_ROOT/icons"
    [ ! -d "$icon_src" ] && icon_src="$QPKG_ROOT/shared/icons"
    if [ -d "$icon_src" ]; then
        for sys_icon_dir in /home/httpd/RSS/images /home/httpd/cgi-bin/images /home/httpd/v3_images; do
            if [ -d "$sys_icon_dir" ]; then
                cp -f "$icon_src"/* "$sys_icon_dir/" 2>/dev/null || true
            fi
        done
        touch /etc/config/qpkg.conf 2>/dev/null || true
    fi
}

######################################################################
# 真实进程 PID 探针与动态自愈
######################################################################
get_actual_pids() {
    local matched_pids=""
    for proc_dir in /proc/[0-9]*; do
        [ ! -d "$proc_dir" ] && continue
        local p="${proc_dir##*/}"
        local cmdline=$(tr '\0' ' ' < "$proc_dir/cmdline" 2>/dev/null)
        case "$cmdline" in
            *"ytdlp.sh daemon"*)
                matched_pids="$matched_pids $p"
                ;;
        esac
    done
    echo "$matched_pids" | xargs 2>/dev/null
}

sync_pid_file() {
    local active_pids=$(get_actual_pids)
    if [ -n "$active_pids" ]; then
        local first_pid=$(echo "$active_pids" | awk '{print $1}')
        echo "$first_pid" > "$PID_FILE" 2>/dev/null || true
        chmod 666 "$PID_FILE" 2>/dev/null || true
        return 0
    else
        rm -f "$PID_FILE" 2>/dev/null || true
        return 1
    fi
}

######################################################################
# 任务调度守护核心
######################################################################
run_daemon() {
    echo "$$" > "$PID_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] yt-dlp scheduler daemon started (PID: $$)" >> "$DAEMON_LOG"

    local scheduler_script="$QPKG_ROOT/web/scheduler.php"

    while true; do
        if [ -f "$scheduler_script" ]; then
            /usr/bin/php "$scheduler_script" tick >> "$DAEMON_LOG" 2>&1 || true
        fi
        sleep 2
    done
}

######################################################################
# 启停控制接口
######################################################################
case "$1" in
    start)
        # 防重入排他锁
        LOCK_DIR="$CONF_DIR/.start.lock"
        if ! mkdir "$LOCK_DIR" 2>/dev/null; then
            LOCK_AGE=0
            if which stat >/dev/null 2>&1; then
                LOCK_MTIME=$(stat -c %Y "$LOCK_DIR" 2>/dev/null || stat -f %m "$LOCK_DIR" 2>/dev/null || echo 0)
                NOW=$(date +%s)
                LOCK_AGE=$((NOW - LOCK_MTIME))
            fi
            if [ "$LOCK_AGE" -gt 15 ]; then
                rm -rf "$LOCK_DIR" 2>/dev/null
                mkdir "$LOCK_DIR" 2>/dev/null || exit 0
            else
                echo "$QPKG_NAME start is already in progress, skipping."
                exit 0
            fi
        fi
        trap 'rm -rf "$LOCK_DIR"' EXIT INT TERM

        setup_arch_binaries
        link_webui
        sync_system_icons

        # 检查开机自启解耦
        AUTOSTART=1
        if [ -f "$CONF_DIR/config.json" ]; then
            # 简单 grep 提取 autostart
            val=$(grep -o '"autostart"[[:space:]]*:[[:space:]]*[0-9]' "$CONF_DIR/config.json" | grep -o '[0-9]')
            [ -n "$val" ] && AUTOSTART="$val"
        fi

        if [ "$AUTOSTART" -eq 0 ] && [ "$2" != "force" ]; then
            echo "$QPKG_NAME autostart is disabled in config, Web UI is mounted."
            exit 0
        fi

        if sync_pid_file; then
            echo "$QPKG_NAME scheduler is already running."
            exit 0
        fi

        echo "Starting $QPKG_NAME scheduler daemon..."
        nohup "$0" daemon >/dev/null 2>&1 &

        # 弹性微循环检测启动状态
        STARTED=0
        for i in $(seq 1 10); do
            sleep 0.3
            if sync_pid_file; then
                STARTED=1
                break
            fi
        done

        if [ "$STARTED" -eq 1 ]; then
            echo "$QPKG_NAME scheduler started successfully."
        else
            echo "Failed to start $QPKG_NAME scheduler."
            exit 1
        fi
        ;;

    stop)
        echo "Stopping $QPKG_NAME..."
        active_pids=$(get_actual_pids)
        if [ -n "$active_pids" ]; then
            echo "Sending SIGTERM to PIDs: $active_pids"
            for p in $active_pids; do
                kill -15 "$p" 2>/dev/null || true
            done
            sleep 1
        fi

        # 再次检测残存
        remaining_pids=$(get_actual_pids)
        if [ -n "$remaining_pids" ]; then
            echo "Force killing remaining PIDs: $remaining_pids"
            for p in $remaining_pids; do
                kill -9 "$p" 2>/dev/null || true
            done
        fi

        # 兜底强杀调度器
        pkill -9 -f 'ytdlp.sh daemon' 2>/dev/null || true
        rm -f "$PID_FILE" 2>/dev/null || true
        echo "$QPKG_NAME stopped."
        ;;

    restart)
        "$0" stop
        sleep 1
        "$0" start force
        ;;

    status)
        if sync_pid_file; then
            echo "$QPKG_NAME is running (PID: $(cat "$PID_FILE" 2>/dev/null))."
            exit 0
        else
            echo "$QPKG_NAME is stopped."
            exit 1
        fi
        ;;

    daemon)
        run_daemon
        ;;

    *)
        echo "Usage: $0 {start|stop|restart|status|daemon}"
        exit 1
        ;;
esac

exit 0
