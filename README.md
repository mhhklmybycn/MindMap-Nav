# 思维导图导航站（MindMap Nav）

一个自带自动安装向导的轻量站点系统：前台用思维导图形式展示入口，后台可维护站点名称、背景图、自定义 HTML 以及任意数量的导图节点。

**当前版本 3.0**，在 2.0 基础上新增：

- 分支网站节点数据的导出与导入（JSON 格式，支持追加 / 覆盖两种模式）
- 导出与导入**仅包含分支网站节点**，不包含任何访问记录
- 前台样式模板：内置「夜幕深蓝」「清晨浅灰」「紫夜星云」三套，一键切换
- 已移除 2.0 的前 / 后台自定义背景色功能（保留自定义背景图功能）

2.0 已有功能：

- 前台每个节点旁实时显示目标页面的在线状态与延迟
- 引导页首页与分支节点的访问记录（次数、时间、设备、IP）
- 后台「访问统计」页面：汇总、节点聚合、明细筛选分页与清除记录

**版本快照**：根目录为最新 3.0 源码；`1.0/`、`2.0/` 目录分别为 1.0、2.0 版本的完整源码快照，仅供存档比对，不参与运行。

**当前源码处于「未安装」状态**：不含 `config/config.php`，不含默认管理员账号，首次访问会自动进入安装向导。

---

## 一、技术方案与环境要求

### 1. 技术选型

| 项目 | 选择 |
| --- | --- |
| 后端 | 原生 PHP 8（过程式 + PDO），**不使用 ThinkPHP / Laravel** |
| 数据库 | MySQL 或 MariaDB |
| 前端 | 原生 HTML5 + CSS3 + JavaScript（ES5 兼容写法），**不使用 Vue / React** |
| 依赖 | **无需 Composer、无需 npm、无任何 CDN 外部资源** |
| 图表库 | 未引入第三方思维导图库，分支连线由原生 SVG 按节点实时位置绘制 |

> 框架版本：不适用。项目是零框架、零构建步骤的纯 PHP 站点，上传即可运行。

### 2. 环境要求

| 项目 | 最低要求 | 推荐 / 已验证 |
| --- | --- | --- |
| PHP | 8.0 | 8.1 - 8.4；本项目在 **PHP 8.4.27** 完成端到端验证 |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 11.x；本项目在 **MariaDB 11.4.13** 完成端到端验证 |
| Web 服务器 | Apache 2.4 / Nginx 1.18 | Apache 需允许 `.htaccess`（见部署章节） |
| 浏览器 | 现代 Chrome / Edge / Firefox / Safari | 建议使用最新版本 |

#### 必需 PHP 扩展

- `PDO`（内置）
- `pdo_mysql`：**必需**，用于连接 MySQL
- `fileinfo`：**必需**，用于校验上传图片的真实 MIME 类型
- `session`、`json`（默认内置）

#### 可选 PHP 扩展

- `mbstring`：推荐启用，用于更准确的中文长度计算；未启用时程序会自动回退到 `strlen`
- 不需要 `GD`，背景图校验使用内置的 `getimagesize()`，不做图片裁剪

#### 推荐 PHP 配置

```ini
file_uploads = On
upload_max_filesize = 8M
post_max_size = 10M
memory_limit = 128M
max_execution_time = 60
```

> `session.save_path` 必须可写，否则后台无法登录。
> 图片上传上限在程序内限制为 5 MB；如果 PHP 的 `upload_max_filesize` 小于 5 MB，以 PHP 配置为准。

### 3. 程序运行逻辑

```
访问站点根目录
      │
      ├── config/config.php 不存在或 installed 不为 true
      │        └── 302 跳转到 install/ 安装向导
      │
      └── 已安装
               └── 直接显示前台思维导图首页
```

- 安装表单：数据库主机、数据库名称、数据库账号、数据库密码、管理员用户名、管理员密码、网站名称。
- 提交后自动：检测数据库连通性 → 创建三张数据表 → 写入管理员与站点配置 → 生成 `config/config.php`（含数据库配置与 `installed => true`）→ 显示成功页和后台登录入口。
- 已安装后再次访问 `install/` 只会显示「程序已经安装」，不会重复建表或覆盖数据。

---

## 二、项目目录结构

```
ydy/
├── index.php                     # 前台思维导图首页（记录首页访问、显示节点状态）
├── go.php                        # 节点跳转统计入口：记录点击后 302 跳转
├── .htaccess                     # Apache 安全配置（禁止目录浏览、隐藏文件）
├── .gitignore                    # 忽略安装配置与上传文件
├── README.md                     # 本文档
├── 1.0/                          # 1.0 版本源码快照（存档用，不参与运行）
├── 2.0/                          # 2.0 版本源码快照（存档用，不参与运行）
│
├── config/
│   ├── .htaccess                 # Apache 下禁止访问配置目录
│   └── config.php                # 【安装时自动生成，源码中不存在】
│
├── includes/
│   ├── functions.php             # 公共函数：转义、会话、CSRF、配置、URL 校验、访问记录等
│   └── bootstrap.php             # 前台/后台启动层：安装检查、2.0 结构升级、安全头、PDO 连接 db()
│
├── install/
│   ├── index.php                 # 自动安装向导：环境检测、建表、生成配置
│   └── install.css               # 安装页样式
│
├── admin/
│   ├── login.php                 # 后台登录（限流、CSRF、会话重建）
│   ├── logout.php                # 退出登录（仅 POST + CSRF）
│   ├── index.php                 # 管理概览
│   ├── nodes.php                 # 节点列表、删除、上移/下移
│   ├── node_form.php             # 新增 / 编辑节点
│   ├── stats.php                 # 访问统计与清除记录
│   ├── transfer.php              # 数据备份：节点导出 / 导入（3.0 新增）
│   ├── settings.php              # 网站名称、背景图、前台样式模板、自定义 HTML
│   ├── account.php               # 管理员用户名与密码修改
│   └── partials/
│       ├── header.php            # 后台公共头部与侧栏
│       └── footer.php            # 后台公共尾部
│
├── assets/
│   ├── css/
│   │   ├── front.css             # 前台首页样式（默认「夜幕深蓝」模板）
│   │   ├── admin.css             # 后台、登录页样式
│   │   └── themes/               # 额外套餐模板（在 front.css 之后加载）
│   │       ├── light.css         # 「清晨浅灰」浅色模板
│   │       └── violet.css        # 「紫夜星云」模板
│   └── js/
│       ├── front.js              # 前台 SVG 分支连线绘制 + 节点状态探测
│       └── admin.js              # 后台交互：侧栏、确认、密码显隐、上传预览、模板选择、导入确认
│
└── uploads/
    ├── .htaccess                 # 禁止上传目录执行 PHP 等脚本
    └── backgrounds/
        └── .gitkeep              # 背景图上传目录（运行时写入）
```

### 数据库表（安装时自动创建，无需导入 SQL）

| 表名 | 作用 | 主要字段 |
| --- | --- | --- |
| `admins` | 管理员 | `id`、`username`、`password_hash`、`created_at`、`updated_at` |
| `mindmap_nodes` | 思维导图节点 | `id`、`node_name`、`jump_url`、`sort_order`、`created_at`、`updated_at` |
| `site_settings` | 网站配置（单行） | `id`、`site_name`、`background_image`、`front_theme`、`custom_html`、`updated_at` |
| `access_logs` | 访问记录 | `id`、`target_type`(home/node)、`node_id`、`node_name`、`jump_url`、`ip`、`user_agent`、`device`、`referer`、`created_at` |

- 密码字段仅保存 `password_hash()` 生成的哈希，永不保存明文。
- 节点按 `sort_order ASC, id ASC` 排序；新增节点自动追加到末尾。
- `site_settings` 固定使用 `id = 1` 的单行配置。
- `access_logs` 的 `target_type` 区分引导页首页访问（`home`）与分支节点点击（`node`）。
- **从 1.0 / 2.0 升级**：直接用 3.0 文件覆盖旧文件即可，程序启动时会自动创建 `access_logs` 表并补齐 `front_theme` 字段，无需手动执行 SQL。2.0 遗留的 `front_bg_color` / `admin_bg_color` 字段会保留在旧库中但不再使用。

---

## 三、源码说明

完整源码已写入上述文件，以下说明每个文件的职责，便于二次开发。

### 1. 公共层

**`includes/functions.php`**

- `e()`：统一 HTML 转义，防 XSS。
- `start_secure_session()`：设置 `HttpOnly`、`SameSite=Lax`、HTTPS 下自动 `Secure` 的会话 Cookie。
- `app_base_path()` / `app_url()`：自动识别部署在域名根目录还是子目录，生成正确的站内链接。
- `csrf_token()` / `csrf_field()` / `verify_csrf()`：CSRF 生成与校验。
- `load_config()` / `is_installed()`：读取安装配置与安装标记。
- `is_valid_node_url()` / `safe_node_url()`：只允许 `http://`、`https://` 或站内 `/` 开头路径，拒绝 `javascript:`、`data:` 等危险协议。
- `normalize_background_path()`：把背景图路径限制在 `uploads/backgrounds/` 内，防目录穿越。
- `normalize_front_theme()`：校验前台样式模板名（dark / light / violet 白名单），非法值回退默认模板。
- `get_client_ip()`：读取访客 IP（仅信任 `REMOTE_ADDR`）。
- `parse_device()`：根据 User-Agent 粗略识别「设备类型 · 操作系统 · 浏览器」。
- `log_access()`：写入访问记录，失败只记日志、不影响访问。
- `get_site_settings()` / `admin_user()` / `require_admin()` / `logout_admin()`：站点配置、管理员登录态与后台鉴权。
- `flash_set()` / `flash_get_all()`：一次性操作提示。

**`includes/bootstrap.php`**

- 设置时区、错误日志；生产环境不把错误详情输出到页面。
- 发送安全响应头。
- 未安装时强制跳转 `install/`。
- `ensure_schema_latest()`：历史版本数据结构自动升级——创建 `access_logs` 表、为 `site_settings` 补充 `front_theme` 字段；全部使用幂等语句，重复执行无副作用，失败只记日志。
- 定义 `db()`：从 `config/config.php` 读取数据库参数，创建全局 PDO 连接；连接失败直接返回明确错误。

### 2. 安装向导

**`install/index.php`**

- 环境检测：PHP 版本、`pdo_mysql`、`fileinfo`、`config` 目录、上传目录写权限。
- 服务端校验所有安装字段。
- 使用 PDO 连接数据库，捕获 `PDOException` 并显示具体错误。
- 自动执行三条 `CREATE TABLE IF NOT EXISTS`。
- 事务内写入管理员（`password_hash` 加密）和默认站点配置。
- 原子写入 `config/config.php`；写入失败会回滚并清理临时文件。
- 已存在管理员账号时拒绝重复安装。

### 3. 前台

**`index.php`**

- 经 `bootstrap.php` 自动判断是否安装。
- 读取网站名称、背景图、自定义 HTML。
- 读取前台样式模板：非默认模板时在 `front.css` 之后追加加载 `assets/css/themes/{模板}.css`（模板名白名单校验）。
- 读取节点并按排序输出，**左右交替分布**形成思维导图分支。
- 每次访问写入一条 `home` 类型访问记录。
- 节点卡片链接指向 `go.php?id=节点ID`，并通过 `data-check-url` 携带真实目标地址供状态探测。
- 自定义 HTML 在页面下方原样渲染；为空时不输出该区域。
- 空节点时显示引导提示。

**`go.php`（2.0 新增）**

- 节点跳转统计入口：校验节点 ID 与跳转地址合法性（复用 URL 白名单，危险地址直接回首页）。
- 写入一条 `node` 类型访问记录后 302 跳转到目标地址。
- 即使访客禁用 JavaScript，点击统计也不会丢失。

**`assets/js/front.js`**

- 根据根节点与两侧卡片在屏幕上的实际位置，绘制 SVG 贝塞尔曲线。
- 支持窗口缩放、字体加载完成、卡片入场动画结束后的重新定位。
- 移动端（≤980px）自动隐藏连线，改为纵向卡片布局。
- 节点状态探测：对每个节点目标地址发起 `HEAD` 请求（外链使用 `no-cors` 模式），6 秒超时，逐个错开；在卡片上显示 检测中 / 在线 / 离线 与延迟毫秒数。

### 4. 后台

**`admin/partials/header.php` / `footer.php`**

- 固定侧栏、顶部标题、提示消息、退出登录按钮。
- 所有导航链接使用 `app_url()`，兼容子目录部署。

**`admin/login.php`**

- 已登录自动跳转后台。
- 密码使用 `password_verify()` 校验。
- 同一会话 10 分钟内最多失败 5 次。
- 账号不存在时也执行一次哈希校验，降低账号枚举的时间差异。
- 登录成功后 `session_regenerate_id(true)` 防会话固定，并刷新 CSRF Token。
- 当 PHP 默认哈希算法升级时自动 `password_needs_rehash()` 更新哈希。

**`admin/logout.php`**

- 仅接受 `POST`，且校验 CSRF，避免被诱导退出。

**`admin/index.php`**

- 节点总数、背景设置状态、最近更新时间、最近 5 个节点、快捷入口。
- 数据读取异常只记录日志，不破坏页面渲染。

**`admin/nodes.php`**

- 节点列表。
- 删除节点（POST + CSRF + 前端确认框）。
- 上移 / 下移：在事务中锁定全部节点，交换位置后按 10、20、30… 重新编号，顺带修复历史重复排序值。

**`admin/node_form.php`**

- 新增 / 编辑节点。
- 名称不超过 100 字符；地址必填并校验协议。
- 新增节点自动排在最后。
- 提供前台卡片实时预览。

**`admin/settings.php`**

- 修改网站名称。
- 上传背景图：
  - 只允许 JPG / PNG / GIF / WEBP；
  - 校验 `is_uploaded_file()`、文件大小、`finfo` 真实 MIME、`getimagesize()` 图像结构；
  - 随机重命名文件；
  - 保存成功后删除旧背景文件；
  - 可勾选移除背景图，恢复默认渐变背景。
- 前台样式模板（3.0 新增）：内置三套前台皮肤——夜幕深蓝（默认）、清晨浅灰、紫夜星云；
  - 模板名称经白名单校验，非法值自动回退默认模板；
  - 模板文件位于 `assets/css/themes/`，在默认样式之后层叠加载，仅覆盖配色变量与背景渐变。
- 自定义 HTML 代码编辑框，保存后前台原样渲染。

**`admin/stats.php`（2.0 新增）**

- 汇总卡片：首页累计/今日访问、节点累计/今日点击、累计/今日独立 IP。
- 分支节点访问情况：每个节点的点击次数与最近访问时间，可单独清除该节点记录。
- 访问明细：时间、访问目标（首页/节点名）、IP、设备（悬停可见完整 UA）、来源页面；支持按类型筛选与分页（每页 25 条）。
- 清除记录：清除首页记录、清空全部记录，均为 POST + CSRF + 浏览器确认。

**`admin/transfer.php`（3.0 新增）**

- 导出：把全部分支节点按排序输出为 JSON 文件下载，包含 `node_name`、`jump_url`、`sort_order` 与导出元信息；**不包含访问记录**。
- 导入：上传 `.json` 备份文件，支持两种模式：
  - 追加导入：保留现有节点，导入节点按文件顺序追加到列表末尾；
  - 覆盖导入：先删除现有全部节点再写入，前端有二次确认，数据库事务保证失败回滚。
- 严格校验：文件必须为合法 JSON 且包含 `nodes` 数组；每个节点的名称（1-100 字符）与跳转地址（协议白名单）逐条校验，**任何一条不合法，整个文件拒绝导入**，不产生半截数据。
- 上传安全：校验 `is_uploaded_file()`、文件 ≤ 2MB、扩展名 `.json`；单次最多导入 500 个节点。
- 导入的节点排序号按 10、20、30… 重新分配，保持文件中的先后顺序。

**`admin/account.php`**

- 修改管理员用户名。
- 修改密码必须验证当前密码；新密码可选，留空表示不修改。
- 两次新密码必须一致，长度 8 - 72。
- 修改成功后刷新 Session ID。

### 5. 样式与脚本

- `assets/css/front.css`：默认「夜幕深蓝」模板、深色玻璃质感、分支卡片、响应式布局、`prefers-reduced-motion` 适配。
- `assets/css/themes/light.css` / `violet.css`：额外套餐模板，通过覆盖 CSS 变量与背景规则实现换肤。
- `assets/css/admin.css`：后台布局、登录页、表格、表单、上传预览、移动端侧栏。
- `assets/js/admin.js`：移动端侧栏、删除确认、密码显隐、图片即时预览、节点实时预览、模板选择高亮、导入文件名显示与覆盖确认、提示自动收起。

---

## 四、部署步骤

### 1. 准备数据库

程序**不会创建数据库本身**，只会在指定数据库内自动建表。请先创建一个使用 `utf8mb4` 的数据库：

```sql
CREATE DATABASE `mindmap_nav`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

创建一个专用账号（按实际密码替换）：

```sql
CREATE USER 'mindmap_user'@'localhost' IDENTIFIED BY '请替换为强密码';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
  ON `mindmap_nav`.* TO 'mindmap_user'@'localhost';
FLUSH PRIVILEGES;
```

> 安装程序需要 `CREATE` 权限来建表。如果使用宝塔 / 虚拟主机面板，通常可直接用面板创建数据库并授权同一账号。

### 2. 上传源码

把本项目所有文件上传到 Web 根目录，例如：

- 域名根目录：`/var/www/mindmap-nav/` 或 `htdocs/`
- 子目录：`htdocs/mindmap/`

程序会自动识别子目录并生成正确的资源路径，**不需要修改任何配置文件**。

### 3. 设置目录权限（Linux）

```bash
cd /var/www/mindmap-nav

# 所有目录可读可进入，所有文件可读
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# 安装期间需要写入配置和上传目录
chmod 775 config uploads uploads/backgrounds

# 让 Web 用户拥有目录（按实际用户替换，如 nginx / www-data）
chown -R www-data:www-data /var/www/mindmap-nav
```

Windows（XAMPP / phpStudy）通常无需额外设置；若提示不可写，请检查 `config`、`uploads/backgrounds` 目录属性以及 PHP 运行账户权限。

### 4. Apache 配置

确保虚拟主机允许 `.htaccess` 生效：

```apache
<VirtualHost *:80>
    ServerName nav.example.com
    DocumentRoot /var/www/mindmap-nav

    <Directory /var/www/mindmap-nav>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/mindmap-error.log
    CustomLog ${APACHE_LOG_DIR}/mindmap-access.log combined
</VirtualHost>
```

启用后重启 Apache。项目自带：

- 根目录 `.htaccess`：禁止目录浏览、禁止访问点文件。
- `config/.htaccess`：禁止直接访问数据库配置文件。
- `uploads/.htaccess`：禁止上传目录执行 PHP 等脚本。

> XAMPP 默认 `AllowOverride All`，通常无需额外配置。

### 5. Nginx 配置

Nginx **不会读取 `.htaccess`**，必须手动添加保护规则：

```nginx
server {
    listen 80;
    server_name nav.example.com;
    root /var/www/mindmap-nav;
    index index.php;

    # 禁止访问数据库配置
    location ^~ /config/ {
        deny all;
    }

    # 上传目录禁止执行脚本（必须放在通用 PHP 规则之前）
    location ~* ^/uploads/.*\.(php|php[0-9]?|phtml|pl|py|cgi|sh|asp|aspx|jsp|shtml)$ {
        deny all;
    }

    # 禁止访问点文件
    location ~ /\.(?!well-known) {
        deny all;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # 按实际 PHP-FPM 版本修改
    }
}
```

修改后执行 `nginx -t` 检查并重载。

### 6. 运行安装向导

1. 浏览器访问站点地址，例如 `https://nav.example.com/`。
2. 未安装时会自动跳转到 `https://nav.example.com/install/`。
3. 确认右侧「环境检测」全部为绿色；若有标红项，先修复并刷新页面。
4. 填写：
   - 数据库主机：`localhost` 或 `127.0.0.1`；非默认端口可写 `127.0.0.1:3307`
   - 数据库名称：第 1 步创建的数据库
   - 数据库账号 / 密码
   - 管理员用户名：3 - 30 位中文、字母、数字、下划线或短横线
   - 管理员密码：8 - 72 个字符
   - 网站名称：不超过 100 个字符
5. 点击「开始安装并创建数据表」。
6. 成功后页面显示「站点已成功安装」，并提供「登录管理后台」和「查看网站首页」入口。

安装成功后会自动生成：

```php
<?php
return [
    'installed' => true,
    'installed_at' => '...',
    'database' => [
        'host' => '...',
        'port' => 3306,
        'name' => '...',
        'user' => '...',
        'password' => '...',
        'charset' => 'utf8mb4',
    ],
];
```

> **不要手动编辑 `config/config.php`**。如确需迁移数据库，修改后清理缓存并验证连接。

### 7. 安装后的加固

```bash
# 安装完成后，配置文件只读即可
chmod 640 config/config.php
chmod 755 config

# 上传目录仍需可写（后台要上传背景图）
chmod 775 uploads/backgrounds
```

建议：

- 为站点启用 HTTPS。
- 定期备份数据库和 `config/config.php`。
- 后台路径可进一步通过 Web 服务器限制来源 IP（可选）。
- 不需要再次安装时，可保留 `install/`（已安装会自动锁定）；如担心暴露，可在确认安装稳定后删除或限制访问该目录。

### 8. 重新安装

程序检测到已安装会拒绝重复安装。若确实需要重装：

1. 备份现有数据库与 `config/config.php`。
2. 删除 `config/config.php`。
3. 删除数据库中的四张表，或直接删除数据库后重新创建。
4. 重新访问站点，即可再次进入安装向导。

> 如果数据库中仍存在管理员记录，安装程序会提示「该数据库已存在管理员账号」并停止安装。

---

## 五、使用教程

### 1. 登录后台

- 入口：`https://你的域名/admin/login.php`
- 使用安装时创建的管理员账号登录。
- 同一会话密码错误 5 次后会锁定约 10 分钟。

### 2. 管理概览

登录后默认进入「管理概览」，可以看到：

- 当前节点数量
- 是否已设置自定义背景
- 节点最后更新时间
- 最近 5 个节点
- 快捷入口

### 3. 新增 / 编辑节点

进入「节点管理」→「新增节点」：

- **节点名称**：显示在前台卡片上的文字，最多 100 字符。
- **跳转地址**：
  - 完整外链：`https://example.com`
  - HTTP 外链：`http://example.com`
  - 站内路径：`/about.php`
  - 其他协议（如 `javascript:`、`data:`）会被拒绝。
- 提交后自动追加到列表末尾。

编辑时只修改名称与地址，不会改变排序。

### 4. 调整节点排序

在「节点管理」表格中：

- 点击 `↑` 上移，`↓` 下移。
- 第一个节点的上移按钮、最后一个节点的下移按钮会自动禁用。
- 排序会立即影响前台：节点按顺序交替显示在根节点左右两侧。

### 5. 删除节点

点击「删除」后浏览器会弹出确认框。删除不可撤销，但不会影响其他节点。

### 6. 网站基础设置

进入「网站设置」：

**网站名称**

- 同步用于浏览器标题、前台中央根节点、后台品牌区域。

**前端背景图**

- 支持 JPG / PNG / GIF / WEBP，最大 5 MB。
- 选择文件后立即在左侧预览。
- 保存后前台使用该图作为背景，并自动叠加暗色遮罩以保证文字可读。
- 勾选「移除当前背景图」并保存，可恢复当前模板自带的背景。
- 替换背景后，旧文件会自动删除。

**自定义 HTML 代码**

- 内容会渲染在前台首页下方，默认 `custom_html` 为空，此时前台不输出该区域。
- 支持普通 HTML（公告、说明、备案信息、嵌入组件等）。
- **该内容按原始 HTML 输出，不会二次转义**，属于管理员主动信任的内容，请只粘贴可信代码。

**前台样式模板（3.0 新增）**

- 内置三套模板：**夜幕深蓝**（默认，深色底 + 青蓝光晕）、**清晨浅灰**（浅色底 + 深色文字）、**紫夜星云**（深紫底 + 紫粉光晕）。
- 点击模板卡片即可选中，选中卡片会高亮描边；保存后刷新前台立即生效。
- 已上传背景图时，背景图覆盖模板底色，模板仍控制卡片与文字配色。

### 7. 节点在线状态与延迟（2.0 新增）

前台每个节点卡片下方会实时显示状态徽章：

- **检测中**（黄色，呼吸闪烁）：正在探测目标地址。
- **在线 + 延迟毫秒数**（绿色）：目标地址可连通。
- **离线**（红色）：连接失败或超过 6 秒未响应。

实现方式与限制：

- 浏览器对每个节点发起一次 `HEAD` 请求，外链使用 `no-cors` 模式：只要能建立连接即视为在线，**无法读取对方的 HTTP 状态码**。
- HTTPS 站点探测 HTTP 节点会被浏览器按混合内容拦截，此类节点会显示为离线——这是浏览器的安全限制，不是程序故障。
- 部分目标站点屏蔽 HEAD 请求时会回退失败显示离线，可按需在 `assets/js/front.js` 中把 `method` 改为 `GET`。
- 延迟为浏览器侧发起请求到收到响应的耗时，仅供参考。

### 8. 访问统计与清除记录（2.0 新增）

进入后台「访问统计」：

- **汇总卡片**：引导页首页累计/今日访问次数、分支节点累计/今日点击次数、累计/今日独立 IP 数。
- **分支节点访问情况**：每个节点的累计访问次数、最近访问时间；点击「清除记录」可单独清空该节点的历史。
- **访问明细**：按时间倒序展示访问时间、访问目标（首页/节点名）、访问 IP、访问设备（悬停显示完整 User-Agent）、来源页面；可按「全部 / 引导页首页 / 分支节点」筛选，每页 25 条。
- **清除记录**：面板右上角可「清除首页记录」或「清空全部记录」，操作均有确认提示且不可恢复。

> 首页访问在每次打开前台首页时记录；节点点击在访客点击卡片、经 `go.php` 跳转时记录。

### 9. 数据导出与导入（3.0 新增）

进入后台「数据备份」：

**导出节点数据**

- 点击「导出全部节点（JSON）」，浏览器会下载一个 `mindmap-nodes-日期时间.json` 文件。
- 文件包含全部分支节点的名称、跳转地址与排序，以及导出时间等元信息；**不包含访问记录**。

**导入节点数据**

1. 点击「选择 JSON 备份文件」，选中由本系统导出的 `.json` 文件（最大 2MB，单次最多 500 个节点）。
2. 选择导入模式：
   - **追加导入**：保留现有节点，把文件中的节点按顺序追加到列表末尾；
   - **覆盖导入**：先删除现有全部节点，再写入文件中的节点，提交时会弹出二次确认，**不可恢复**。
3. 点击「开始导入」。

校验规则与提示：

- 文件必须是合法 JSON，且包含 `nodes` 数组；只读取每个节点的 `node_name` 与 `jump_url`，其他字段会被忽略。
- 节点名称 1 - 100 字符，跳转地址只允许 `http://`、`https://` 或 `/` 开头的站内路径。
- 任何一条数据不合法，整个文件都会被拒绝导入，并列出问题所在行（最多显示前 5 条）。
- 导入在数据库事务中执行，失败自动回滚，已有数据不会被破坏。
- 典型用途：站点迁移（旧站导出 → 新站导入）、定期备份节点配置。

### 10. 管理员设置

进入「管理员设置」：

- 修改用户名需要输入当前登录密码。
- 修改密码同样必须验证当前密码。
- 新密码与确认密码必须一致，长度 8 - 72。
- 新密码留空表示只修改用户名。
- 保存后当前登录状态保持，Session ID 会刷新。

### 11. 退出登录

点击侧栏底部的退出图标。退出请求使用 `POST + CSRF`，不会因为访问一个链接而被强制退出。

---

## 六、安全设计与注意事项

### 已实现的安全措施

1. **SQL 注入防护**：所有用户输入查询使用 PDO 预处理语句；表名与 DDL 固定，不拼接用户输入。
2. **XSS 防护**：站点名称、节点名称、URL、提示消息等全部通过 `e()` 转义；节点 URL 使用协议白名单。
3. **密码安全**：`password_hash(PASSWORD_DEFAULT)` + `password_verify()`，只保存哈希；支持算法升级时自动重哈希。
4. **CSRF 防护**：所有后台写操作（登录、退出、节点增删改排序、设置、账号修改）均为 `POST` 并校验一次性 Token。
5. **登录限流**：同一会话 10 分钟内最多 5 次失败。
6. **会话安全**：`HttpOnly`、`SameSite=Lax`、HTTPS 下 `Secure`；登录和改密后 `session_regenerate_id(true)`。
7. **上传安全**：
   - 校验 `is_uploaded_file()`；
   - 使用 `finfo` 检测真实 MIME；
   - 使用 `getimagesize()` 验证图像结构；
   - 随机重命名；
   - 仅允许 JPG / PNG / GIF / WEBP；
   - 明确排除 SVG，避免 SVG 内嵌脚本；
   - 上传目录通过 `.htaccess` 禁止执行脚本。
8. **安装安全**：安装完成后写入 `installed` 标记，重复访问安装页不会覆盖数据；配置目录在 Apache 下禁止直接访问。
9. **链接安全**：节点外链使用 `rel="noopener noreferrer"`，避免 `window.opener` 风险。
10. **数据库凭据**：源码中不存在任何数据库账号密码，全部由安装程序写入 `config/config.php`。
11. **跳转入口二次校验（2.0）**：`go.php` 在跳转前重新校验节点 ID 与 URL 白名单，不能直接当作开放重定向器跳往任意地址。
12. **访问记录安全（2.0）**：IP 只取自 `REMOTE_ADDR`，不信任可伪造的代理头；清除记录操作均为 POST + CSRF + 确认框。
13. **导入安全（3.0）**：备份文件校验 `is_uploaded_file()`、大小与扩展名；JSON 严格解析、节点逐条白名单校验，任一不合法整体拒绝；覆盖导入在事务中执行并有前端二次确认；导入仅接受节点字段，无法注入其他数据表内容。

### 必须注意

- **自定义 HTML 是按原始代码渲染的**。如果你的管理员账号被他人控制，攻击者可以插入脚本。请使用强密码、启用 HTTPS，并限制后台访问来源。
- **必须为后台启用 HTTPS**。本项目会自动识别 HTTPS 并为会话 Cookie 添加 `Secure`；如果反向代理终止 TLS，请正确传递 `X-Forwarded-Proto: https`。
- **Nginx 必须手动配置保护规则**，`.htaccess` 对 Nginx 无效，尤其是 `config/` 禁止访问和 `uploads/` 禁止脚本执行。
- **不要将 `config/config.php` 提交到 Git 或公开分享**。项目已在 `.gitignore` 中忽略该文件。
- **安装目录与上传目录不能设置为全局可写 777**，按本文建议使用 755 / 775 并指定属主。
- **忘记密码无法找回**，因为系统只保存不可逆哈希。可通过服务器命令行生成新哈希后更新数据库（见常见问题）。
- 后台默认只有一个管理员账号，没有注册和添加管理员功能；如多人使用，建议共用强密码并定期更换，或在此结构上自行扩展。

---

## 七、常见问题与排查

### 1. 访问首页一直跳到安装页

检查：

- `config/config.php` 是否存在；
- 文件内容是否包含 `'installed' => true`；
- Web 服务器用户是否有读取权限；
- 是否开启了 PHP OPcache 且缓存了旧文件（重启 PHP-FPM / Apache）。

### 2. 安装提示「config 目录不可写」

- Linux：`chmod 775 config` 并确认属主为 Web 用户。
- 宝塔 / 虚拟主机：在面板中修改目录权限。
- SELinux：必要时执行 `chcon -R -t httpd_sys_rw_content_t config uploads`。
- Windows：检查目录是否只读，以及 PHP 服务运行账户是否有写权限。

### 3. 安装提示「数据库连接或初始化失败」

逐项检查：

- 数据库主机与端口是否正确（非 3306 请写 `主机:端口`）；
- 数据库是否已提前创建；
- 账号密码是否正确；
- 账号是否拥有 `CREATE` 权限；
- MySQL 是否只监听 `127.0.0.1`，而程序填了远程主机；
- `pdo_mysql` 扩展是否启用（`php -m | grep pdo_mysql`）。

### 4. 提示「该数据库已存在管理员账号」

说明该库已安装过。请：

- 使用原有 `config/config.php` 恢复访问；或
- 备份后清空四张表 / 重建数据库，再删除 `config/config.php` 重新安装。

### 5. 后台上传背景图失败

- 确认 `uploads/backgrounds` 可写；
- 确认 PHP `file_uploads = On`；
- 确认上传文件不超过 5 MB 且 PHP 配置允许；
- 确认 `fileinfo` 扩展已启用；
- 确认不是把其他格式改后缀伪装成图片（会被图像结构校验拒绝）。

### 6. 背景图上传成功但前台不显示

- 刷新页面并强制刷新缓存；
- 检查 `uploads/backgrounds/` 中文件是否存在；
- 检查站点是否部署在子目录，资源路径是否可访问；
- 检查 Nginx / Apache 是否拦截了 `uploads/` 静态文件。

### 7. 后台登录被锁定

同一会话连续失败 5 次会锁定约 10 分钟。等待后重试，或清理该站点的浏览器 Cookie / 服务器 Session 后重新打开登录页。

### 8. 忘记管理员密码

在服务器上生成新哈希：

```bash
php -r 'echo password_hash("你的新密码", PASSWORD_DEFAULT), PHP_EOL;'
```

然后登录数据库执行：

```sql
UPDATE admins
SET password_hash = '粘贴生成的哈希', updated_at = NOW()
WHERE username = '你的管理员用户名';
```

### 9. 页面样式或脚本 404

- 确认 `assets/` 目录完整上传；
- 确认站点可通过 `http://域名/assets/css/front.css` 直接访问；
- 本程序不需要 URL 重写；若 404，通常是 DocumentRoot 指错或子目录部署路径不一致。

### 10. 出现 500 错误 / 白屏

- 查看 PHP 错误日志（`error_log`、Apache error log 或 PHP-FPM log）；
- 确认 PHP ≥ 8.0；
- 确认 `pdo_mysql`、`fileinfo` 已启用；
- 临时在 `php.ini` 打开 `display_errors` 定位，排查后务必关闭。

---

## 八、验证记录

本项目在交付前完成了以下验证：

- 全部 16 个 PHP 文件通过 `php -l` 语法检查（PHP 8.4.27）。
- `assets/js/front.js`、`assets/js/admin.js` 通过 `node --check`。
- 在 PHP 8.4.27 + MariaDB 11.4.13 环境完成端到端安装：自动建四张表、生成配置、写入管理员与站点配置。
- 验证未安装时首页自动 302 到安装向导，已安装时正常渲染。
- 验证未登录访问后台自动 302 到登录页。
- 验证登录错误提示、连续失败限流、登录成功会话重建。
- 验证节点新增、编辑、删除、上移 / 下移与前台左右分支渲染。
- 验证 `javascript:` 危险地址被拒绝，非法节点 ID 的 `go.php` 重定向回首页。
- 验证伪装图片被 MIME 与图像结构校验拦截，真实 PNG 上传成功。
- 验证背景替换、移除与旧文件清理；上传背景图后模板配色与遮罩正常叠加。
- 验证自定义 HTML 前台原样渲染。
- 验证站点名称 XSS 输入被转义。
- 验证缺少 CSRF Token 的 POST 返回 419。
- 验证管理员改密、旧密码失效、新密码登录成功、退出登录。
- 验证 2.0 结构升级（已有配置下自动补字段、自动建 access_logs 表）。
- 验证访问记录写入（首页与节点跳转）与后台统计展示；清除记录正常。
- 验证 3.0 导出文件结构合法且仅含节点数据；追加导入保留原节点并顺延排序，覆盖导入清空后重写。
- 验证导入严格校验：非法 JSON、缺少 nodes、危险跳转地址、超长名称、超数量上限均被整体拒绝。
- 验证前台样式模板：三套模板正常切换渲染，非法模板名回退默认，上传背景图时模板配色仍生效。
- 使用浏览器检查前台、登录页、后台概览、节点管理、网站设置、访问统计、数据备份页，无 JavaScript 控制台报错。

> 测试用的临时数据库与 `config/config.php` 已移除，交付源码保持未安装状态。

---

## 九、快速开始（最短路径）

```bash
# 1. 建库（MySQL 命令行）
mysql -u root -p -e "CREATE DATABASE mindmap_nav CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 上传源码到 Web 根目录，设置可写
chmod 775 config uploads/backgrounds

# 3. 访问站点，自动进入安装向导
# http://你的域名/
```

安装完成后：

- 前台：`http://你的域名/`
- 后台：`http://你的域名/admin/login.php`

完成。
