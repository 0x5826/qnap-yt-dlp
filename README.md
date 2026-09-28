# 媒体下载 (QNAP QTS 原生套件)

基于 `yt-dlp` 与 `FFmpeg` 构建的 QNAP QTS 生产级原生 QPKG 套件与极客科技风 Web 管理控制台。

[![Build & Release QPKG](https://github.com/danteder/qnap-yt-dlp/actions/workflows/auto-release.yml/badge.svg)](https://github.com/danteder/qnap-yt-dlp/actions/workflows/auto-release.yml)
[![Version](https://img.shields.io/badge/version-1.0.3-0ea5e9.svg)](https://github.com/danteder/qnap-yt-dlp/releases)
[![Platform](https://img.shields.io/badge/platform-QNAP%20QTS%205.x%20%7C%20QuTS-10b981.svg)](#)
[![Arch](https://img.shields.io/badge/arch-x86__64%20%7C%20arm__64-818cf8.svg)](#)

---

## 核心亮点与架构创新

### 1. 双规格同构构建体系 (Slim / Full)
针对不同 NAS 用户的使用场景，提供两种差异化 Release 版本：
- **Slim 精简版 (~40MB)**：仅内嵌跨架构独立版 `yt-dlp`，深度复用 QNAP NAS 系统自带（`/usr/bin/ffmpeg`）或官方多媒体套件（`MultimediaConsole`）的 FFmpeg 引擎。极致轻巧，节省系统存储。
- **Full 全能版 (~98MB)**：内嵌完整跨架构纯静态编译的最新 `FFmpeg 6.1` 与 `ffprobe` 二进制。开箱即用，即便 NAS 从未安装任何多媒体组件，也能完美进行 4K/8K 音画合并与转码。

### 2. 零常驻事件驱动调度架构
- **零闲置资源消耗**：摒弃传统常驻死循环后台进程，采用基于 POSIX 异步管道的事件驱动按需调度（On-Demand Event-Driven）。
- **环境强兼容**：彻底剔除对 Linux `nohup` 命令的硬依赖，完美兼容 QTS 内置精简 BusyBox 环境。
- **排他并发与自愈**：利用非阻塞文件排他锁与 PID 指纹探测，杜绝重复调度与孤儿子进程，支持任务失败重试与断点续传。

### 3. 对齐 EasyTier 规范的工业级精致 Web 控制台
- **设计语言统一**：采用科技深蓝配色体系（`#0ea5e9`）与 980px 居中紧凑视口，内嵌深底分段控制器（Segmented Control）与发光激活胶囊。
- **对称双排指标卡片**：
  - **任务调度状态**：高亮双排呈现（`下载中: X 个` / `排队中: Y 个`）；
  - **全局下载速率**：醒目纯粹的大字号实时速率展示；
  - **目标存储空间**：双排显示（`可用: XX TB` / `总计: XX TB`），横向挤压减少 50%，内置祖先挂载点回溯探测算法，杜绝目录未建时的 0 B 假死；
  - **核心引擎与组件**：准确探测显示 yt-dlp 与 FFmpeg 版本及来源徽章（`[系统原生]` 绿色微光 / `[内置静态]` 蓝色徽章）。
- **任务全生命周期指标**：下载中显示实时进度、速率与剩余时间；下载完成后精准切换展示**文件大小、平均下载速率与实际执行耗时**。
- **一站式全局配置**：存储目录选择（支持原生 `/share` 共享卷动态浏览且不强制追加子目录）、文件名模板、并发限制、单任务限速、HTTP/SOCKS5 网络代理以及 Netscape 格式 `cookies.txt` 在线维护统一收纳。

### 4. QNAP 原生深度集成
- **QTS 官方鉴权机制继承**：无缝继承 QTS 桌面会话（Session & Cookie 校验），未授权访问自动阻断，杜绝内网越权。
- **全彩高精图标自愈分发**：基于 32 位全彩无损 PNG-backed GIF 规范生成超采样图标，安装与升级自动注入 QTS 图标缓存库。

---

## 快速安装与使用

### 1. 下载 QPKG 安装包
前往 [GitHub Releases](https://github.com/danteder/qnap-yt-dlp/releases) 页面，根据需求下载对应架构的安装包：
- **x86_64 设备**（Intel / AMD 处理器）：
  - `ytdlp_slim_1.0.3.0928_x86_64.qpkg`（推荐，体积小）
  - `ytdlp_full_1.0.3.0928_x86_64.qpkg`（全能内置版）
- **arm_64 设备**（Annapurna / Realtek 等 ARM 处理器）：
  - `ytdlp_slim_1.0.3.0928_arm_64.qpkg`
  - `ytdlp_full_1.0.3.0928_arm_64.qpkg`

### 2. 在 QTS 中手动安装
1. 登录 QNAP QTS 管理界面，打开 **App Center（App 俱乐部）**；
2. 点击右上角 **“手动安装”** 按钮；
3. 浏览并选择下载的 `.qpkg` 文件，点击 **“安装”**；
4. 安装完成后，桌面上将出现 **“媒体下载”** 官方图标，点击即可直达管理控制台。

---

## 项目目录结构

```text
qnap-yt-dlp/
├── .github/workflows/
│   └── auto-release.yml         # GitHub Actions 跨架构双规格自动打包流水线
├── qpkg.cfg                     # QPKG 打包规范元数据 (显示名: 媒体下载)
├── package_routines             # QTS 安装、升级、卸载生命周期钩子
├── scripts/
│   ├── download_binaries.sh     # 跨架构纯静态 FFmpeg 拉取与裁切工具
│   ├── generate_icons.py        # 32 位全彩无损高保真图标渲染工具
│   └── build_packages.sh        # Slim / Full 双版本多架构构建脚本
├── icons/                       # QDK 打包图标矩阵
├── shared/                      # QPKG 运行 Payload
│   ├── ytdlp.sh                 # 主服务启停控制与运行环境初始化脚本
│   ├── build_version            # 构建版本与修订日期标识
│   ├── conf/                    # 运行时配置、持久化任务队列与凭据
│   │   ├── config.json          # 全局配置参数
│   │   ├── tasks.json           # 任务状态队列数据库
│   │   └── cookies.txt          # Netscape Cookies 凭据文件
│   └── web/                     # 极客科技风轻量 Web 管理控制台
│       ├── index.php            # 单页控制台视图
│       ├── api.php              # RESTful API 后端控制器
│       ├── common.php           # 通用函数、存储探测与异步调度唤醒器
│       ├── scheduler.php        # 任务队列调度引擎
│       ├── worker.sh            # 单任务独立工作执行器
│       └── build_command.php    # yt-dlp 命令行动态拼装器
└── README.md
```

---

## 本地开发与构建

### 1. 编译环境要求
- Python 3.10+ (安装 Pillow: `pip install pillow`)
- Bash, curl, tar, bc
- QNAP QDK 2.3+ (`qbuild`)

### 2. 生成图标与构建双规格套件
```bash
# 1. 渲染生成 32 位全彩高精图标
python3 scripts/generate_icons.py

# 2. 执行本地双规格（Slim + Full）打包构建
chmod +x scripts/build_packages.sh
./scripts/build_packages.sh
```
构建生成的套件将输出至 `build/` 目录下。

---

## 许可证与致谢

本项目基于 MIT 协议开源。核心下载引擎基于 [yt-dlp](https://github.com/yt-dlp/yt-dlp) 与 [FFmpeg](https://ffmpeg.org/)。UI 视觉体系对齐 [qnap-easytier](https://github.com/danteder/qnap-easytier) 规范。
