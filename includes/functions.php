<?php
declare(strict_types=1);

/**
 * 公共函数库
 * ------------------------------------------------------------------
 * 本文件只包含与具体页面无关的基础函数：
 * - 会话、URL、转义、提示消息、CSRF 防护
 * - 配置读取、安装状态判断
 * - 节点 URL 与背景图路径安全校验
 * - 管理员登录状态判断
 *
 * 该文件既会被前台/后台加载，也会被安装程序加载，
 * 因此不能在这里直接连接数据库或输出页面内容。
 */

if (!defined('APP_ROOT')) {
    // 项目根目录（includes 的上一级）
    define('APP_ROOT', dirname(__DIR__));
}

// 自定义会话名称，避免与同一服务器上的其他 PHP 程序冲突
const APP_SESSION_NAME = 'mindmap_nav_session';

// 当前程序版本号
const APP_VERSION = '3.0';

/**
 * 输出经过 HTML 转义的内容，防止 XSS。
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 判断当前请求是否为 POST。
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * 启动安全的会话。
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    // 拒绝未初始化的会话 ID，并限制 Cookie 传递方式
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name(APP_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * 计算应用所在的基础路径，兼容部署在域名根目录或子目录的情况。
 * 例如：
 * - 根目录部署：/admin/login.php -> ''
 * - 子目录部署：/nav/admin/login.php -> '/nav'
 */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($scriptName));
    $dir = rtrim($dir, '/');

    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    // 当前脚本位于 admin/ 或 install/ 时，向上一级得到应用根路径
    if (preg_match('~/(admin|install)$~', $dir)) {
        $parent = str_replace('\\', '/', dirname($dir));
        $dir = ($parent === '.' || $parent === '/') ? '' : rtrim($parent, '/');
    }

    return $base = $dir;
}

/**
 * 生成站内绝对路径，自动兼容子目录部署。
 */
function app_url(string $path = ''): string
{
    $base = app_base_path();

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

/**
 * 跳转并结束请求。
 */
function redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * 发送常用的安全响应头。
 * 注意：因为后台允许管理员填写自定义 HTML，不能强行启用严格 CSP。
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

/**
 * 写入一条一次性提示消息。
 *
 * @param string $type success|error|info|warning
 */
function flash_set(string $type, string $message): void
{
    $allowed = ['success', 'error', 'info', 'warning'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    if (!isset($_SESSION['_flashes']) || !is_array($_SESSION['_flashes'])) {
        $_SESSION['_flashes'] = [];
    }

    $_SESSION['_flashes'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * 读取并清空全部提示消息。
 *
 * @return array<int, array{type: string, message: string}>
 */
function flash_get_all(): array
{
    $messages = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);

    return is_array($messages) ? array_values($messages) : [];
}

/**
 * 获取 CSRF Token，不存在则生成。
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * 生成表单中的 CSRF 隐藏字段。
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * 校验 POST 请求里的 CSRF Token，失败时直接终止请求。
 */
function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    $sessionToken = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($token) || $token === '' || !is_string($sessionToken) || $sessionToken === '') {
        http_response_code(419);
        header('Content-Type: text/html; charset=UTF-8');
        exit('页面安全令牌已失效，请返回上一页刷新后重试。');
    }

    if (!hash_equals($sessionToken, $token)) {
        http_response_code(419);
        header('Content-Type: text/html; charset=UTF-8');
        exit('页面安全令牌校验失败，请返回上一页刷新后重试。');
    }
}

/**
 * 读取配置文件，不存在时返回空数组。
 */
function load_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configFile = APP_ROOT . '/config/config.php';
    if (!is_file($configFile)) {
        return $config = [];
    }

    $loaded = require $configFile;

    return $config = is_array($loaded) ? $loaded : [];
}

/**
 * 判断程序是否已经完成安装。
 */
function is_installed(): bool
{
    $config = load_config();

    if (empty($config['installed']) || !isset($config['database']) || !is_array($config['database'])) {
        return false;
    }

    $db = $config['database'];

    return !empty($db['host'])
        && !empty($db['name'])
        && array_key_exists('user', $db);
}

/**
 * 获取安全的节点 URL，非法值统一返回 #。
 * 只允许 http/https 绝对地址，或站内 / 开头的相对路径。
 */
function safe_node_url(string $url): string
{
    return is_valid_node_url($url) ? $url : '#';
}

/**
 * 校验思维导图节点的跳转地址。
 */
function is_valid_node_url(string $url): bool
{
    $url = trim($url);

    if ($url === '' || strlen($url) > 500) {
        return false;
    }

    // 禁止控制字符、换行和未编码的空格
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        return false;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    // 允许以单个 / 开头的站内路径，禁止 //example.com 形式
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return true;
    }

    return false;
}

/**
 * 判断链接是否为外部链接。
 */
function is_external_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

/**
 * 生成节点卡片中展示的链接来源文案。
 */
function node_url_label(string $url): string
{
    if (!is_valid_node_url($url)) {
        return '链接待修复';
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return preg_replace('/^www\./i', '', $host) ?: $host;
    }

    return '站内链接';
}

/**
 * 规范化背景图相对路径，杜绝路径穿越和任意文件引用。
 */
function normalize_background_path(string $path): string
{
    $path = ltrim(trim($path), '/');

    if (preg_match('~^uploads/backgrounds/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)$~i', $path)) {
        return $path;
    }

    return '';
}

/**
 * 删除项目自身上传目录中的旧背景图。
 */
function delete_background_file(string $path): void
{
    $safePath = normalize_background_path($path);
    if ($safePath === '') {
        return;
    }

    $fullPath = APP_ROOT . '/' . $safePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/**
 * 按字符数计算文本长度；优先使用 mbstring。
 */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * 校验前台样式模板名称是否在白名单内，非法值回退到默认模板。
 * 白名单与 assets/css/themes/ 目录下的模板文件一一对应（dark 为内置默认，无单独文件）。
 */
function normalize_front_theme(string $theme): string
{
    $theme = strtolower(trim($theme));

    return in_array($theme, ['dark', 'light', 'violet'], true) ? $theme : 'dark';
}

/**
 * 获取访客真实 IP。
 * 只信任 REMOTE_ADDR，不读取可伪造的 X-Forwarded-For 等头；
 * 部署在反向代理之后时，可在 Web 服务器层把真实 IP 写回 REMOTE_ADDR。
 */
function get_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/**
 * 根据 User-Agent 粗略识别访问设备。
 * 返回格式：设备类型 · 操作系统 · 浏览器，例如「手机 · Android · Chrome」。
 */
function parse_device(string $userAgent): string
{
    $ua = trim($userAgent);
    if ($ua === '') {
        return '未知设备';
    }

    // 设备类型
    $device = '电脑';
    if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua)
        || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) {
        $device = '平板';
    } elseif (preg_match('/Mobile|iPhone|iPod|Windows Phone|HarmonyOS|BlackBerry|IEMobile/i', $ua)) {
        $device = '手机';
    }

    // 操作系统
    $os = '其他系统';
    $osRules = [
        '/Windows Phone/i' => 'Windows Phone',
        '/Windows NT/i' => 'Windows',
        '/iPhone|iPad|iPod/i' => 'iOS',
        '/Mac OS X/i' => 'macOS',
        '/HarmonyOS/i' => 'HarmonyOS',
        '/Android/i' => 'Android',
        '/Linux/i' => 'Linux',
    ];
    foreach ($osRules as $pattern => $name) {
        if (preg_match($pattern, $ua)) {
            $os = $name;
            break;
        }
    }

    // 浏览器（注意判断顺序：微信/Edge/Opera 的 UA 中也包含 Chrome 字样）
    $browser = '其他浏览器';
    $browserRules = [
        '/MicroMessenger/i' => '微信',
        '/Edg(e|A|iOS)?\//i' => 'Edge',
        '/OPR\/|Opera/i' => 'Opera',
        '/Firefox/i' => 'Firefox',
        '/Chrome\/|CriOS/i' => 'Chrome',
        '/Safari/i' => 'Safari',
    ];
    foreach ($browserRules as $pattern => $name) {
        if (preg_match($pattern, $ua)) {
            $browser = $name;
            break;
        }
    }

    return $device . ' · ' . $os . ' · ' . $browser;
}

/**
 * 写入一条访问记录。
 * 记录失败只写错误日志，绝不影响前台正常访问。
 *
 * @param string $targetType home=引导页首页，node=分支节点
 */
function log_access(string $targetType, int $nodeId = 0, string $nodeName = '', string $jumpUrl = ''): void
{
    if (!in_array($targetType, ['home', 'node'], true)) {
        return;
    }

    try {
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);

        $stmt = db()->prepare(
            'INSERT INTO access_logs
                (target_type, node_id, node_name, jump_url, ip, user_agent, device, referer, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $targetType,
            max(0, $nodeId),
            substr($nodeName, 0, 100),
            substr($jumpUrl, 0, 500),
            get_client_ip(),
            $userAgent,
            parse_device($userAgent),
            $referer,
        ]);
    } catch (Throwable $e) {
        error_log('访问记录写入失败：' . $e->getMessage());
    }
}

/**
 * 获取当前登录管理员信息；未登录或账号已删除时返回 null。
 */
function admin_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['admin_id'])) {
        return null;
    }

    $adminId = (int) $_SESSION['admin_id'];
    if ($adminId <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT id, username, created_at, updated_at FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('读取管理员信息失败：' . $e->getMessage());
        return null;
    }

    if (!$user) {
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
        return null;
    }

    return $user;
}

/**
 * 是否已登录后台。
 */
function is_admin_logged_in(): bool
{
    return admin_user() !== null;
}

/**
 * 后台页面统一鉴权，未登录自动跳转登录页。
 */
function require_admin(): void
{
    if (!is_admin_logged_in()) {
        flash_set('error', '请先登录后再访问后台。');
        redirect(app_url('admin/login.php'));
    }
}

/**
 * 安全退出登录。
 */
function logout_admin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}

/**
 * 读取网站配置（单行表），数据库尚未建立时返回默认值。
 *
 * @return array{site_name: string, background_image: string, custom_html: string, front_theme: string}
 */
function get_site_settings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $defaults = [
        'site_name' => '思维导图导航',
        'background_image' => '',
        'custom_html' => '',
        'front_theme' => 'dark',
    ];

    try {
        $stmt = db()->query(
            'SELECT site_name, background_image, custom_html, front_theme
             FROM site_settings WHERE id = 1 LIMIT 1'
        );
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('读取网站配置失败：' . $e->getMessage());
        return $settings = $defaults;
    }

    if (!$row) {
        return $settings = $defaults;
    }

    return $settings = [
        'site_name' => (string) ($row['site_name'] ?? $defaults['site_name']),
        'background_image' => (string) ($row['background_image'] ?? ''),
        'custom_html' => (string) ($row['custom_html'] ?? ''),
        'front_theme' => normalize_front_theme((string) ($row['front_theme'] ?? 'dark')),
    ];
}

/**
 * 格式化数据库时间。
 */
function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date('Y-m-d H:i', $timestamp);
}

/**
 * 格式化数据库时间（精确到秒），用于访问记录等场景。
 */
function format_datetime_full(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date('Y-m-d H:i:s', $timestamp);
}
