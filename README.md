# SimpleHashImg Pro V33 📸

一款极简、轻量、高性能且注重隐私保护的单文件 PHP 图床系统。无需数据库，开箱即用，支持秒传去重、分片断点传输、并发重试与 EXIF 隐私地理位置自动擦除。

![Badges](https://img.shields.io/badge/SimpleHashImg-PHP%207.4%2B%20%7C%20MIT%20%7C%20Flat%20File-blue)

---

## ✨ 核心特性

- **📄 单文件极简部署**：核心逻辑、后端 API 与响应式前端界面完美整合于单个 `index.php` 中，零数据库依赖，开箱即用。
- **🛡️ 隐私保护 (EXIF/GPS 自动擦除)**：
  - **JPG/JPEG 格式**：采用纯二进制流切片技术，精准剥离隐藏的 GPS 经纬度地理坐标、相机/手机型号、拍摄时间及 Photoshop 元数据（`0xE1`/`0xED` 标记块），**100% 保持原始画质**（零二次压缩）。
  - **PNG/WebP/BMP 格式**：检测到 GD 扩展时自动重构图像，彻底剥离软件描述文本与多余 Chunk 块。
  - **GIF/SVG 格式**：智能识别并保持原样，完美保留动态帧数与矢量路径。
- **⚡ 极速秒传与服务端去重**：前端基于 SparkMD5 实时计算本地哈希，同图秒传，服务端按哈希实现物理文件级别去重，极大节省磁盘空间。
- **🧩 512KB 分片并发上传与容错**：
  - 支持大图 512KB 分片并发切片上传与自动合并，打破免费主机（如 ByetHost / InfinityFree 等）的单文件上传体积限制。
  - 前端内置 **3 线程并发队列控制** 与 **3 次网络波动指数退避重试** 机制，极致保障大文件上传稳定性。
  - 具备中途取消与清理临时碎片 API（`action=abort`）。
- **🖼️ 零延迟预览与现代自适应 UI**：
  - **HTML5 Blob 原生指针预览**：上传前 0 CPU 占用、0 毫秒延迟秒级渲染预览图。
  - **会话持久化**：采用 `sessionStorage` 自动记忆已上传列表与正/倒序排列偏好，刷新页面不丢失批次结果。
  - 智能文件大小格式化显示（KB/MB）与独立序号标记（No.x）。
  - 支持拖拽上传、自动上传开关、一键复制外链/删除链接及动态汇总框。
- **🔐 智能批次管理与 HMAC 安全防护**：
  - 基于动态 Salt 生成 HMAC 签名 Token，防止遍历猜解删除。
  - **智能批次预检**：批次一键删除时自动检测图片存活状态；若图片已单张清空，自动清理 Session 记录并友好提示，避免无效二次确认。
  - 自动注入 `.htaccess` 规则防护，禁止直接访问 `data/` 敏感数据目录。
- **🧹 自动环境配置与 GC 垃圾回收**：
  - 首次运行自动完成 Apache 伪静态规则注入与 MIME 类型修正。
  - 内置 10% 概率触发的垃圾回收（GC）机制，自动清理超过 2 小时未完成的临时中断切片目录（`tmp_*`）。

---

## 🛠️ 环境要求

- **PHP**：PHP 7.4 或更高版本。
- **Web 服务器**：Apache / Nginx / LiteSpeed 等。
- **PHP 扩展**：
  - `openssl`（必须，用于生成高强度加密密钥）
  - `gd`（可选，用于 PNG/WebP/BMP 的辅助元数据清洗；JPG/JPEG 无损擦除无需任何扩展支持）

---

## 🚀 快速安装

1. 下载项目中的 `index.php`，上传至 Web 服务器根目录（或任意子目录）。
2. 设置当前目录及其子目录具备读写权限：
   ```bash
   chmod -R 755 .
   ```
3. 通过浏览器访问站点域名（如 `https://your-domain.com/`），系统将自动初始化目录结构并注入防护规则。

---

## ⚙️ 伪静态配置

### 1. Apache 环境
系统在首次运行时会**自动生成并注入**根目录 `.htaccess` 规则，通常无需手动修改。规则内容如下：

```apache
Options -Indexes
AddType image/svg+xml .svg .svgz
AddType image/webp .webp

# --- SimpleHashImg Rules ---
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^src/([a-f0-9]{32})\..*$ index.php?v=$1 [L,QSA]
    RewriteRule ^delete/([a-f0-9]{32})/([a-f0-9]{32})$ index.php?action=delete&hash=$1&token=$2 [L,QSA]
    RewriteRule ^delete-batch/([^/]+)/([a-f0-9]{32})$ index.php?action=delete_batch&sess=$1&token=$2 [L,QSA]
</IfModule>
# --- SimpleHashImg Rules End ---
```

### 2. Nginx 环境
若使用 Nginx 服务器，请将以下 rewrite 规则复制并粘贴至你的 Nginx 站点配置文件（`server block`）中：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/src/([a-f0-9]{32})\..*$ /index.php?v=$1 last;
        rewrite ^/delete/([a-f0-9]{32})/([a-f0-9]{32})$ /index.php?action=delete&hash=$1&token=$2 last;
        rewrite ^/delete-batch/([^/]+)/([a-f0-9]{32})$ /index.php?action=delete_batch&sess=$1&token=$2 last;
    }
}
```

---

## 📁 目录结构说明

系统运行后会自动构建以下扁平化结构：

```text
├── index.php             # 核心入口文件（整合前端 UI + 后端 API + 逻辑闭环）
├── uploads/              # 物理图片存储目录（按 YYYY-MM-DD 动态按日建夹）
│   └── .htaccess         # 防护文件（禁止目录遍历）
└── data/                 # 系统数据与索引目录
    ├── secret.php        # 自动生成的动态安全密钥 Salt
    ├── .htaccess         # 严密防护文件（Deny from all，防止越权访问敏感数据）
    ├── idx_xx            # 扁平化哈希索引文件（按前两位 Hash 分片存储）
    ├── sess_xx.json      # 批次上传会话记录文件
    └── tmp_xx_xx/        # 上传中分片临时缓存目录（GC 机制自动定期清理）
```

---

## 📄 开源许可

本项目采用 [MIT License](LICENSE) 许可协议。您可以自由地使用、修改、分发与商业化。