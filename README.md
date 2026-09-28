# QNAP yt-dlp 媒体下载管理插件

基于 `yt-dlp` 与静态编译版 `ffmpeg` 的 QNAP QTS 生产级原生 QPKG 插件与极客暗黑风轻量 Web 管理控制台。

---

## 核心特性

- **多架构独立运行时**：内嵌 `x86_64` 与 `aarch64 (ARM 64位)` 双架构独立 Standalone 二进制与静态 `ffmpeg` / `ffprobe`，零 Python 依赖，开箱即用。
- **媒体智能解析**：支持解析 YouTube、Bilibili、Twitter、Vimeo 等上千流媒体站点，智能结构化提取 4K/2K/1080P/720P 分辨率与原声轨、多语言字幕。
- **规格与格式自选**：
  - 支持指定分辨率合并为 MP4、MKV、WebM 容器；
  - 支持一键提取纯音频（MP3 320k、M4A 原声、FLAC 无损）；
  - 支持字幕选择（内嵌到视频或外挂导出）；
  - 支持自动内嵌高清封面图与章节元数据标签。
- **任务并发与队列控制**：内置轻量守护进程，支持排队调度、并发控制、断点续传、实时速率/进度/ETA 监控、任务暂停与重试。
- **QNAP 系统级深度集成**：
  - 自动适配 QNAP 共享文件夹路径（如 `/share/Download/yt-dlp`）；
  - 高保真 32 位全彩无损 PNG-backed GIF 图标自动分发至 QTS 现代桌面与 App Center；
  - 开机自启解耦管理（支持不拉起下载守护直接挂载 WebUI）。
- **凭据与高级扩展**：支持在线上传与维护 Netscape 格式 `cookies.txt`（突破会员与防盗链限制），支持 HTTP/SOCKS5 代理及核心组件一键在线升级。

---

## 项目目录结构

```text
qnap-yt-dlp/
├── .github/workflows/
│   └── auto-release.yml         # GitHub Actions 跨架构打包与 Release 发布流水线
├── qpkg.cfg                     # QPKG 打包元数据定义 (符合 QTS 10 字符版本限制)
├── package_routines             # QTS 安装、升级、卸载生命周期钩子
├── scripts/
│   ├── download_binaries.sh     # 上游跨架构静态二进制拉取脚本
│   ├── generate_icons.py        # 32 位全彩无损高保真图标超分渲染脚本
│   └── build_qpkg.sh            # 本地容器化打包构建工具
├── icons/                       # QDK 打包根图标矩阵
├── shared/                      # QPKG 安装 Payload 根目录
│   ├── ytdlp.sh                 # 主服务启停控制与任务调度守护脚本
│   ├── build_version            # 构建版本与修订日期标识
│   ├── conf/                    # 运行时配置、持久化任务队列与凭据
│   │   ├── config.json          # 全局配置
│   │   ├── tasks.json           # 任务队列状态机
│   │   └── cookies.txt          # Netscape Cookies 凭据
│   ├── icons/                   # 系统图标热分发自愈库
│   └── web/                     # 嵌入式轻量 Web 控制台
│       ├── index.php            # 单页控制台视图
│       ├── api.php              # RESTful API
│       ├── scheduler.php        # 调度引擎
│       ├── worker.sh            # 单任务执行工作进程
│       └── build_command.php    # yt-dlp 参数组装器
└── README.md
```

---

## 本地开发与测试

### 1. 生成高精图标
```bash
./venv/bin/python3 scripts/generate_icons.py
```

### 2. 预览 Web 控制台
在项目根目录启动 PHP 内置开发服务器：
```bash
php -S 127.0.0.1:8998 -t shared/web
```
在浏览器中打开 `http://127.0.0.1:8998` 即可调试控制台视图。
