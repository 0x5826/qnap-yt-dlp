#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

mkdir -p "${ROOT_DIR}/x86_64" "${ROOT_DIR}/arm_64" /tmp/ytdlp_dl_tmp

echo "==> Downloading yt-dlp standalone binaries..."
# x86_64 yt-dlp
if [ ! -f "${ROOT_DIR}/x86_64/yt-dlp" ]; then
    echo "Fetching x86_64 yt-dlp..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux" -o "${ROOT_DIR}/x86_64/yt-dlp"
    chmod +x "${ROOT_DIR}/x86_64/yt-dlp"
fi

# aarch64 (arm_64) yt-dlp
if [ ! -f "${ROOT_DIR}/arm_64/yt-dlp" ]; then
    echo "Fetching arm_64 yt-dlp..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux_aarch64" -o "${ROOT_DIR}/arm_64/yt-dlp"
    chmod +x "${ROOT_DIR}/arm_64/yt-dlp"
fi

echo "==> Downloading pure static ffmpeg / ffprobe binaries (ffbinaries GitHub release)..."
# x86_64 ffmpeg & ffprobe
if [ ! -f "${ROOT_DIR}/x86_64/ffmpeg" ] || [ ! -f "${ROOT_DIR}/x86_64/ffprobe" ]; then
    echo "Fetching x86_64 pure static ffmpeg & ffprobe..."
    curl -fSL "https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffmpeg-6.1-linux-64.zip" -o /tmp/ytdlp_dl_tmp/ffmpeg-linux-64.zip
    curl -fSL "https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffprobe-6.1-linux-64.zip" -o /tmp/ytdlp_dl_tmp/ffprobe-linux-64.zip
    unzip -o /tmp/ytdlp_dl_tmp/ffmpeg-linux-64.zip -d "${ROOT_DIR}/x86_64/"
    unzip -o /tmp/ytdlp_dl_tmp/ffprobe-linux-64.zip -d "${ROOT_DIR}/x86_64/"
    chmod +x "${ROOT_DIR}/x86_64/ffmpeg" "${ROOT_DIR}/x86_64/ffprobe"
fi

# arm_64 ffmpeg & ffprobe
if [ ! -f "${ROOT_DIR}/arm_64/ffmpeg" ] || [ ! -f "${ROOT_DIR}/arm_64/ffprobe" ]; then
    echo "Fetching arm_64 pure static ffmpeg & ffprobe..."
    curl -fSL "https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffmpeg-6.1-linux-arm-64.zip" -o /tmp/ytdlp_dl_tmp/ffmpeg-linux-arm-64.zip
    curl -fSL "https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffprobe-6.1-linux-arm-64.zip" -o /tmp/ytdlp_dl_tmp/ffprobe-linux-arm-64.zip
    unzip -o /tmp/ytdlp_dl_tmp/ffmpeg-linux-arm-64.zip -d "${ROOT_DIR}/arm_64/"
    unzip -o /tmp/ytdlp_dl_tmp/ffprobe-linux-arm-64.zip -d "${ROOT_DIR}/arm_64/"
    chmod +x "${ROOT_DIR}/arm_64/ffmpeg" "${ROOT_DIR}/arm_64/ffprobe"
fi

rm -rf /tmp/ytdlp_dl_tmp
echo "==> All binaries downloaded and prepared successfully."
