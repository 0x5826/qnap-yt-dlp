#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"

cd "${ROOT_DIR}"
mkdir -p "${BUILD_DIR}"

# 提取版本号（支持参数 $1 或 TARGET_QPKG_VER 环境变量注入）
if [ -n "$1" ]; then
    QPKG_VER="$1"
elif [ -n "$TARGET_QPKG_VER" ]; then
    QPKG_VER="$TARGET_QPKG_VER"
else
    QPKG_VER=$(grep '^QPKG_VER=' qpkg.cfg | cut -d'"' -f2)
fi
[ -z "$QPKG_VER" ] && QPKG_VER="1.0.3.0928"

echo "=========================================================="
echo "==> Building QNAP yt-dlp Dual Packages (Version: ${QPKG_VER})"
echo "=========================================================="

# 备份初始 qpkg.cfg 与 build_version
cp qpkg.cfg qpkg.cfg.bak
cp shared/build_version shared/build_version.bak 2>/dev/null || true
trap 'mv -f qpkg.cfg.bak qpkg.cfg 2>/dev/null || true; mv -f shared/build_version.bak shared/build_version 2>/dev/null || true' EXIT INT TERM

# 写入目标版本号
python3 -c "import re; s=open('qpkg.cfg').read(); s=re.sub(r'QPKG_VER=.*', 'QPKG_VER=\"${QPKG_VER}\"', s); open('qpkg.cfg', 'w').write(s)"
echo "${QPKG_VER}" > shared/build_version

# 1. 确保 yt-dlp 核心文件存在（x86_64 和 arm_64）
mkdir -p x86_64 arm_64 /tmp/ytdlp_dl_tmp
if [ ! -f "x86_64/yt-dlp" ]; then
    echo "==> Fetching x86_64 yt-dlp standalone..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux" -o "x86_64/yt-dlp"
    chmod +x "x86_64/yt-dlp"
fi
if [ ! -f "arm_64/yt-dlp" ]; then
    echo "==> Fetching arm_64 yt-dlp standalone..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux_aarch64" -o "arm_64/yt-dlp"
    chmod +x "arm_64/yt-dlp"
fi

# ==========================================================
# 2. 构建 Slim 精简版（仅 yt-dlp，复用 NAS 系统自带 FFmpeg，~15MB）
# ==========================================================
echo "==> [1/2] Building Slim Packages (System FFmpeg Edition)..."
# 清理内置 ffmpeg/ffprobe
rm -f x86_64/ffmpeg x86_64/ffprobe arm_64/ffmpeg arm_64/ffprobe

python3 -c "import re; s=open('qpkg.cfg').read(); s=re.sub(r'QPKG_DISPLAY_NAME=.*', 'QPKG_DISPLAY_NAME=\"媒体下载 (Slim)\"', s); open('qpkg.cfg', 'w').write(s)"
rm -rf build_slim_tmp
mkdir -p build_slim_tmp

qbuild --build-dir build_slim_tmp

for pkg in build_slim_tmp/*.qpkg; do
    if [ -f "$pkg" ]; then
        fname=$(basename "$pkg")
        slim_name=$(echo "$fname" | sed 's|^ytdlp_|ytdlp_slim_|')
        mv "$pkg" "${BUILD_DIR}/${slim_name}"
        echo "    Produced: ${slim_name}"
    fi
done
rm -rf build_slim_tmp

# ==========================================================
# 3. 构建 Full 全能版（内置静态 FFmpeg 6.1 + FFprobe，~93MB）
# ==========================================================
echo "==> [2/2] Building Full Packages (Built-in Static FFmpeg Edition)..."
./scripts/download_binaries.sh

python3 -c "import re; s=open('qpkg.cfg').read(); s=re.sub(r'QPKG_DISPLAY_NAME=.*', 'QPKG_DISPLAY_NAME=\"媒体下载 (Full)\"', s); open('qpkg.cfg', 'w').write(s)"
rm -rf build_full_tmp
mkdir -p build_full_tmp

qbuild --build-dir build_full_tmp

for pkg in build_full_tmp/*.qpkg; do
    if [ -f "$pkg" ]; then
        fname=$(basename "$pkg")
        full_name=$(echo "$fname" | sed 's|^ytdlp_|ytdlp_full_|')
        mv "$pkg" "${BUILD_DIR}/${full_name}"
        echo "    Produced: ${full_name}"
    fi
done
rm -rf build_full_tmp

# 还原 qpkg.cfg
mv -f qpkg.cfg.bak qpkg.cfg

echo "==> All dual packages built successfully in ${BUILD_DIR}/"
