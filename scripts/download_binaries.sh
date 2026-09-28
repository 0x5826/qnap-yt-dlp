#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

mkdir -p "${ROOT_DIR}/x86_64" "${ROOT_DIR}/arm_64" /tmp/ytdlp_dl_tmp

echo "==> Downloading yt-dlp standalone binaries..."
# x86_64
if [ ! -f "${ROOT_DIR}/x86_64/yt-dlp" ]; then
    echo "Fetching x86_64 yt-dlp..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux" -o "${ROOT_DIR}/x86_64/yt-dlp"
    chmod +x "${ROOT_DIR}/x86_64/yt-dlp"
fi

# aarch64 (arm_64)
if [ ! -f "${ROOT_DIR}/arm_64/yt-dlp" ]; then
    echo "Fetching arm_64 yt-dlp..."
    curl -fSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux_aarch64" -o "${ROOT_DIR}/arm_64/yt-dlp"
    chmod +x "${ROOT_DIR}/arm_64/yt-dlp"
fi

echo "==> Downloading static ffmpeg / ffprobe binaries..."
# x86_64 ffmpeg
if [ ! -f "${ROOT_DIR}/x86_64/ffmpeg" ] || [ ! -f "${ROOT_DIR}/x86_64/ffprobe" ]; then
    echo "Fetching x86_64 ffmpeg & ffprobe..."
    curl -fSL "https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz" -o /tmp/ytdlp_dl_tmp/ffmpeg-x64.tar.xz
    tar -xf /tmp/ytdlp_dl_tmp/ffmpeg-x64.tar.xz -C /tmp/ytdlp_dl_tmp/
    cp /tmp/ytdlp_dl_tmp/ffmpeg-master-latest-linux64-gpl/bin/ffmpeg "${ROOT_DIR}/x86_64/ffmpeg"
    cp /tmp/ytdlp_dl_tmp/ffmpeg-master-latest-linux64-gpl/bin/ffprobe "${ROOT_DIR}/x86_64/ffprobe"
    chmod +x "${ROOT_DIR}/x86_64/ffmpeg" "${ROOT_DIR}/x86_64/ffprobe"
fi

# arm_64 ffmpeg
if [ ! -f "${ROOT_DIR}/arm_64/ffmpeg" ] || [ ! -f "${ROOT_DIR}/arm_64/ffprobe" ]; then
    echo "Fetching arm_64 ffmpeg & ffprobe..."
    curl -fSL "https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linuxarm64-gpl.tar.xz" -o /tmp/ytdlp_dl_tmp/ffmpeg-arm64.tar.xz
    tar -xf /tmp/ytdlp_dl_tmp/ffmpeg-arm64.tar.xz -C /tmp/ytdlp_dl_tmp/
    cp /tmp/ytdlp_dl_tmp/ffmpeg-master-latest-linuxarm64-gpl/bin/ffmpeg "${ROOT_DIR}/arm_64/ffmpeg"
    cp /tmp/ytdlp_dl_tmp/ffmpeg-master-latest-linuxarm64-gpl/bin/ffprobe "${ROOT_DIR}/arm_64/ffprobe"
    chmod +x "${ROOT_DIR}/arm_64/ffmpeg" "${ROOT_DIR}/arm_64/ffprobe"
fi

rm -rf /tmp/ytdlp_dl_tmp
echo "==> All binaries downloaded and prepared successfully."
