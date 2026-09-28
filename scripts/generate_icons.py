#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
High-fidelity icon generator for QNAP ytdlp QPKG.
Outputs 32-bit RGBA PNG-backed GIF & PNG icon matrix:
64x64, 80x80, 100x100, 128x128, 256x256, and 80x80 grayscale.
Strictly adheres to 4x supersampling and Lanczos downsampling.
"""

import os
from PIL import Image, ImageDraw

SIZES = [
    ("ytdlp.gif", 64, False),
    ("ytdlp.png", 64, False),
    ("ytdlp_80.gif", 80, False),
    ("ytdlp_80.png", 80, False),
    ("ytdlp_100.gif", 100, False),
    ("ytdlp_100.png", 100, False),
    ("ytdlp_128.gif", 128, False),
    ("ytdlp_128.png", 128, False),
    ("ytdlp_256.gif", 256, False),
    ("ytdlp_256.png", 256, False),
    ("ytdlp_gray.gif", 80, True),
    ("ytdlp_gray.png", 80, True),
]

def draw_master_canvas(scale=4):
    """Draw master canvas at 1024x1024 (scaled) with antialiased geometric shapes."""
    size = 256 * scale
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # 10% inner padding for comfortable breathing room
    pad = int(size * 0.08)
    rect_box = [pad, pad, size - pad, size - pad]
    radius = int(size * 0.22)

    # Background card: deep high-tech slate gradient
    # Simulate rounded rectangle with dark gradient
    draw.rounded_rectangle(rect_box, radius=radius, fill=(15, 23, 42, 255), outline=(51, 65, 85, 255), width=max(2, int(size * 0.015)))

    # Inner circular halo
    center_x, center_y = size // 2, size // 2
    r_halo = int(size * 0.32)
    draw.ellipse([center_x - r_halo, center_y - r_halo, center_x + r_halo, center_y + r_halo],
                 fill=(225, 29, 72, 20), outline=(225, 29, 72, 60), width=max(1, int(size * 0.008)))

    # Tech download arrow + media play indicator
    # Upper arrow stem
    arrow_w = int(size * 0.11)
    arrow_top = int(size * 0.28)
    arrow_mid = int(size * 0.52)
    draw.rounded_rectangle([center_x - arrow_w, arrow_top, center_x + arrow_w, arrow_mid],
                           radius=int(arrow_w * 0.4), fill=(244, 63, 94, 255))

    # Downward triangle arrowhead
    arrow_head_w = int(size * 0.26)
    arrow_tip_y = int(size * 0.64)
    triangle = [
        (center_x - arrow_head_w, arrow_mid - int(size * 0.02)),
        (center_x + arrow_head_w, arrow_mid - int(size * 0.02)),
        (center_x, arrow_tip_y)
    ]
    draw.polygon(triangle, fill=(244, 63, 94, 255))

    # Bottom media tray bar (curved bottom tray)
    tray_w = int(size * 0.30)
    tray_h = int(size * 0.045)
    tray_y = int(size * 0.70)
    draw.rounded_rectangle([center_x - tray_w, tray_y, center_x + tray_w, tray_y + tray_h],
                           radius=int(tray_h // 2), fill=(56, 189, 248, 255))

    return img

def generate():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    project_root = os.path.dirname(script_dir)
    target_dirs = [
        os.path.join(project_root, "icons"),
        os.path.join(project_root, "shared", "icons")
    ]

    for d in target_dirs:
        os.makedirs(d, exist_ok=True)

    master = draw_master_canvas(scale=4)

    for filename, dim, is_gray in SIZES:
        resized = master.resize((dim, dim), Image.Resampling.LANCZOS)
        if is_gray:
            gray = resized.convert("L").convert("RGBA")
            # preserve original alpha channel
            gray.putalpha(resized.split()[-1])
            final_img = gray
        else:
            final_img = resized

        for d in target_dirs:
            out_path = os.path.join(d, filename)
            # CRITICAL: Always use format="PNG" even for .gif extension for 32-bit lossless PNG-backed GIF
            final_img.save(out_path, format="PNG")
            print(f"[OK] Generated: {out_path} ({dim}x{dim}, format=PNG-backed)")

if __name__ == "__main__":
    generate()
