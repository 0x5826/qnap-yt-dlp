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
TMP_DIR="$CONF_DIR/tmp"
PID_FILE="$CONF_DIR/ytdlp_daemon.pid"
DAEMON_LOG="$LOGS_DIR/daemon.log"

# 动态探测 QNAP 原生 PHP 解释器路径
PHP_BIN=""
for p in /mnt/ext/opt/apache/bin/php /mnt/ext/opt/apache/links/php /usr/bin/php /usr/local/bin/php /opt/bin/php; do
    if [ -x "$p" ]; then
        PHP_BIN="$p"
        break
    fi
done
[ -z "$PHP_BIN" ] && PHP_BIN="$(which php 2>/dev/null || echo 'php')"

export PATH="$BIN_DIR:/mnt/ext/opt/apache/bin:/mnt/ext/opt/apache/links:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
export TMPDIR="$TMP_DIR"

mkdir -p "$BIN_DIR" "$CONF_DIR" "$LOGS_DIR" "$TMP_DIR" 2>/dev/null
chmod 777 "$CONF_DIR" "$LOGS_DIR" "$TMP_DIR" 2>/dev/null || true

# 建立 php 软链接以备全局调用
if [ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ]; then
    ln -sf "$PHP_BIN" "$BIN_DIR/php" 2>/dev/null || true
    [ ! -f "/usr/bin/php" ] && ln -sf "$PHP_BIN" /usr/bin/php 2>/dev/null || true
fi

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

    for b in yt-dlp ffmpeg ffprobe; do
        # 破坏环形自引用死锁：若已存在指向自身或系统 wrapper 的死链接，坚决移除
        if [ -L "$BIN_DIR/$b" ]; then
            local cur_link_target=$(readlink "$BIN_DIR/$b" 2>/dev/null)
            if [ "$cur_link_target" = "/usr/bin/$b" ] || [ "$cur_link_target" = "/usr/local/bin/$b" ] || [ "$cur_link_target" = "$BIN_DIR/$b" ]; then
                rm -f "$BIN_DIR/$b" 2>/dev/null || true
            fi
        fi

        if [ -n "$src_dir" ] && [ -f "$src_dir/$b" ]; then
            chmod +x "$src_dir/$b" 2>/dev/null || true
            ln -sf "$src_dir/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ -f "$QPKG_ROOT/$b" ]; then
            chmod +x "$QPKG_ROOT/$b" 2>/dev/null || true
            ln -sf "$QPKG_ROOT/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ "$b" != "yt-dlp" ] && [ -x "/share/CACHEDEV1_DATA/.qpkg/CayinMediaViewer/CodexPackExt/static/bin/$b" ]; then
            ln -sf "/share/CACHEDEV1_DATA/.qpkg/CayinMediaViewer/CodexPackExt/static/bin/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ "$b" != "yt-dlp" ] && [ -x "/share/CACHEDEV1_DATA/.qpkg/CodexPack/opt/ffmpeg/$b" ]; then
            ln -sf "/share/CACHEDEV1_DATA/.qpkg/CodexPack/opt/ffmpeg/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ "$b" != "yt-dlp" ] && [ -f "/usr/bin/$b" ]; then
            ln -sf "/usr/bin/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ "$b" != "yt-dlp" ] && [ -f "/usr/local/bin/$b" ]; then
            ln -sf "/usr/local/bin/$b" "$BIN_DIR/$b" 2>/dev/null || true
        elif [ "$b" != "yt-dlp" ] && [ -f "/opt/bin/$b" ]; then
            ln -sf "/opt/bin/$b" "$BIN_DIR/$b" 2>/dev/null || true
        fi
    done

    # 暴露 CLI 工具至系统路径（采用安全 Wrapper 脚本强制将 TMPDIR 重定向至数据盘，杜绝根分区 /tmp 空间耗尽）
    # 严格校验：仅当 $BIN_DIR/yt-dlp 为有效独立执行文件且非自环软链接时方可注入 wrapper
    if [ -x "$BIN_DIR/yt-dlp" ] && [ "$(readlink -f "$BIN_DIR/yt-dlp" 2>/dev/null)" != "/usr/bin/yt-dlp" ]; then
        cat << EOF > /usr/bin/yt-dlp
#!/bin/sh
export TMPDIR="$TMP_DIR"
[ ! -d "\$TMPDIR" ] && mkdir -p "\$TMPDIR" 2>/dev/null
exec "$BIN_DIR/yt-dlp" "\$@"
EOF
        chmod +x /usr/bin/yt-dlp 2>/dev/null || true
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
# 启停控制接口（纯按需事件驱动架构，零后台常驻开销）
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

        # 释放系统 /tmp 内存盘：清理历史残留的 PyInstaller 临时解压孤儿目录
        rm -rf /tmp/_MEI* 2>/dev/null || true
        # 清理数据盘内部临时目录的历史孤儿文件
        rm -rf "$TMP_DIR"/_MEI* "$TMP_DIR"/tmp_* 2>/dev/null || true

        # 启动时自动触发一次队列补刀，接续未完成的断点任务
        if [ -f "$QPKG_ROOT/web/scheduler.php" ] && [ -n "$PHP_BIN" ]; then
            "$PHP_BIN" "$QPKG_ROOT/web/scheduler.php" tick >/dev/null 2>&1 &
        fi

        # 输出高可读性初始化与组件日志到调度日志
        now_str=$(date '+%Y-%m-%d %H:%M:%S')
        echo "[$now_str] [SYSTEM] yt-dlp QPKG 套件启动初始化完成 (架构: $(uname -m), 模式: 按需事件驱动)" >> "$DAEMON_LOG"
        echo "[$now_str] [RUNTIME] PHP 引擎: $PHP_BIN" >> "$DAEMON_LOG"

        if [ -x "$BIN_DIR/yt-dlp" ]; then
            local ytdlp_real=$(readlink -f "$BIN_DIR/yt-dlp" 2>/dev/null || echo "")
            if [ -n "$ytdlp_real" ] && [ "$ytdlp_real" != "/usr/bin/yt-dlp" ]; then
                ytdlp_v=$(TMPDIR="$TMP_DIR" "$BIN_DIR/yt-dlp" --version 2>/dev/null || echo "已就绪")
            else
                ytdlp_v="未就绪 (等待组件初始化)"
            fi
        fi
        if [ -x "$BIN_DIR/ffmpeg" ]; then
            real_ff=$(readlink -f "$BIN_DIR/ffmpeg" 2>/dev/null || echo "$BIN_DIR/ffmpeg")
            ff_ver_num=$("$BIN_DIR/ffmpeg" -version 2>/dev/null | head -n 1 | awk '{print $3}')
            if [ -L "$BIN_DIR/ffmpeg" ] || [ "${real_ff#$QPKG_ROOT}" = "$real_ff" ]; then
                ffmpeg_v="${ff_ver_num} (系统原生: $real_ff)"
            else
                ffmpeg_v="${ff_ver_num} (内置纯静态)"
            fi
        fi
        echo "[$now_str] [COMPONENTS] yt-dlp: $ytdlp_v | FFmpeg: $ffmpeg_v" >> "$DAEMON_LOG"
        echo "[$now_str] [READY] Web 管理控制台与下载任务调度器已就绪。" >> "$DAEMON_LOG"

        echo "$QPKG_NAME is ready (on-demand mode)."
        ;;

    stop)
        echo "Stopping all active $QPKG_NAME download tasks..."
        pkill -15 -f 'worker.sh' 2>/dev/null || true
        pkill -15 -f 'yt-dlp' 2>/dev/null || true
        pkill -15 -f 'ffmpeg' 2>/dev/null || true
        sleep 0.5
        pkill -9 -f 'worker.sh' 2>/dev/null || true
        pkill -9 -f 'yt-dlp' 2>/dev/null || true
        pkill -9 -f 'ffmpeg' 2>/dev/null || true
        rm -f "$PID_FILE" 2>/dev/null || true

        now_str=$(date '+%Y-%m-%d %H:%M:%S')
        echo "[$now_str] [SYSTEM] 收到停机指令，已终止所有活跃下载进程。" >> "$DAEMON_LOG"
        echo "$QPKG_NAME tasks stopped."
        ;;

    restart)
        "$0" stop
        sleep 1
        "$0" start
        ;;

    status)
        echo "$QPKG_NAME is ready (on-demand event-driven mode)."
        exit 0
        ;;

    tick|daemon)
        if [ -f "$QPKG_ROOT/web/scheduler.php" ] && [ -n "$PHP_BIN" ]; then
            "$PHP_BIN" "$QPKG_ROOT/web/scheduler.php" tick
        fi
        ;;

    *)
        echo "Usage: $0 {start|stop|restart|status|tick}"
        exit 1
        ;;
esac

exit 0
