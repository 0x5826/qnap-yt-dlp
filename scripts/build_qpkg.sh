#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "==> Preparing QPKG package structure..."

# 确保图标已生成
if [ ! -f "${ROOT_DIR}/icons/ytdlp.gif" ]; then
    echo "==> Generating icons..."
    if [ -f "${ROOT_DIR}/venv/bin/python3" ]; then
        "${ROOT_DIR}/venv/bin/python3" "${SCRIPT_DIR}/generate_icons.py"
    else
        python3 "${SCRIPT_DIR}/generate_icons.py"
    fi
fi

# 确保脚本权限
chmod +x "${ROOT_DIR}/package_routines" "${ROOT_DIR}/shared/ytdlp.sh" "${ROOT_DIR}/shared/web/worker.sh" 2>/dev/null || true

# 检查 qbuild 工具
if command -v qbuild >/dev/null 2>&1; then
    echo "==> Building QPKG via host qbuild..."
    cd "${ROOT_DIR}"
    qbuild --build-dir build
    echo "==> Build completed successfully."
else
    echo "==> Host 'qbuild' not found. You can run this in an Ubuntu/Debian container with QDK installed."
    echo "==> For local testing, verify package structure in ${ROOT_DIR}/shared."
fi
