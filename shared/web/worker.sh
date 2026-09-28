#!/bin/sh
######################################################################
# Single Task Worker for yt-dlp
# Arguments: TASK_ID TASK_JSON_FILE
######################################################################

TASK_ID="$1"
TASK_FILE="$2"

if [ -z "$TASK_ID" ] || [ -z "$TASK_FILE" ] || [ ! -f "$TASK_FILE" ]; then
    echo "Usage: $0 <TASK_ID> <TASK_FILE>"
    exit 1
fi

WEB_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(cd "$WEB_DIR/.." && pwd)"
CONF_DIR="$BASE_DIR/conf"
BIN_DIR="$BASE_DIR/bin"
TASK_LOG_DIR="$CONF_DIR/logs/tasks/$TASK_ID"

export PATH="$BIN_DIR:/mnt/ext/opt/apache/bin:/mnt/ext/opt/apache/links:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
export TMPDIR="$CONF_DIR/tmp"
mkdir -p "$CONF_DIR/tmp" 2>/dev/null

# 动态寻找 PHP 解释器
PHP_BIN=""
for p in "$BIN_DIR/php" /mnt/ext/opt/apache/bin/php /mnt/ext/opt/apache/links/php /usr/bin/php /usr/local/bin/php /opt/bin/php; do
    if [ -x "$p" ]; then
        PHP_BIN="$p"
        break
    fi
done
[ -z "$PHP_BIN" ] && PHP_BIN="$(which php 2>/dev/null || echo 'php')"

mkdir -p "$TASK_LOG_DIR"
PID_FILE="$TASK_LOG_DIR/worker.pid"
PROGRESS_FILE="$TASK_LOG_DIR/progress.txt"
OUTPUT_LOG="$TASK_LOG_DIR/output.log"
EXIT_CODE_FILE="$TASK_LOG_DIR/exit_code"

# 优雅转发信号，防止 yt-dlp 和 ffmpeg 产生孤儿子进程
trap 'pkill -P $$ 2>/dev/null; kill $(jobs -p) 2>/dev/null; exit 143' TERM INT

echo "$$" > "$PID_FILE"
rm -f "$EXIT_CODE_FILE" "$PROGRESS_FILE"

# 调用 php 解析任务参数生成命令行
CMD=$("$PHP_BIN" "$WEB_DIR/build_command.php" "$TASK_FILE")
BUILD_EC=$?
if [ $BUILD_EC -ne 0 ] || [ -z "$CMD" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Failed to build command for task $TASK_ID (exit code: $BUILD_EC)" >> "$OUTPUT_LOG"
    echo "${BUILD_EC:-1}" > "$EXIT_CODE_FILE"
    rm -f "$PID_FILE"
    exit ${BUILD_EC:-1}
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting task $TASK_ID..." > "$OUTPUT_LOG"
echo "Command: $CMD" >> "$OUTPUT_LOG"

# 执行命令并捕获标准输出和标准错误
eval "$CMD" >> "$OUTPUT_LOG" 2>&1
EC=$?

echo "$EC" > "$EXIT_CODE_FILE"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Task finished with exit code $EC." >> "$OUTPUT_LOG"
rm -f "$PID_FILE"

# 任务结束，自动异步触发后续任务调度
"$PHP_BIN" "$WEB_DIR/scheduler.php" tick </dev/null >/dev/null 2>&1 &

exit $EC
