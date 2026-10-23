<?php
declare(strict_types=1);

/**
 * 后台登录
 * ------------------------------------------------------------------
 * - 已登录自动进入后台
 * - 用户名/密码错误时只返回统一提示，避免账号枚举
 * - 同一会话 10 分钟内最多尝试 5 次
 * - 登录成功后刷新 Session ID，防止会话固定攻击
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (is_admin_logged_in()) {
    redirect(app_url('admin/index.php'));
}

$errors = [];
$username = '';
$siteSettings = get_site_settings();
$siteName = trim($siteSettings['site_name']) !== '' ? $siteSettings['site_name'] : '思维导图导航';

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $errors[] = '你已安全退出登录。';
}

$maxAttempts = 5;
$lockSeconds = 600;

if (is_post()) {
    verify_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // 清理过期的失败次数
    $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'first' => time()];
    if (!is_array($attempts) || !isset($attempts['first']) || (time() - (int) $attempts['first']) > $lockSeconds) {
        $attempts = ['count' => 0, 'first' => time()];
    }

    if ((int) ($attempts['count'] ?? 0) >= $maxAttempts) {
        $waitMinutes = max(1, (int) ceil(($lockSeconds - (time() - (int) $attempts['first'])) / 60));
        $errors[] = '密码错误次数过多，请约 ' . $waitMinutes . ' 分钟后再试。';
    } elseif ($username === '' || $password === '') {
        $errors[] = '请输入管理员用户名和密码。';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('后台登录查询失败：' . $e->getMessage());
            $admin = false;
        }

        $passwordValid = false;
        if ($admin) {
            $passwordValid = password_verify($password, (string) $admin['password_hash']);
        } else {
            // 账号不存在时也执行一次哈希校验，降低响应时间差异
            password_verify($password, '$2y$10$7EqJtq98hPqEX7fNZaFWoO5a5iQ5Qc5M3Y5jGQqT5z4gJ5J5J5J5i');
        }

        if ($admin && $passwordValid) {
            // 如 PHP 默认算法升级，自动重新计算密码哈希
            if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
                try {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($newHash !== false) {
                        $update = db()->prepare('UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?');
                        $update->execute([$newHash, (int) $admin['id']]);
                    }
                } catch (Throwable $e) {
                    error_log('密码哈希升级失败：' . $e->getMessage());
                }
            }

            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = (string) $admin['username'];
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            unset($_SESSION['login_attempts']);

            flash_set('success', '欢迎回来，' . (string) $admin['username'] . '。');
            redirect(app_url('admin/index.php'));
        }

        $attempts['count'] = (int) ($attempts['count'] ?? 0) + 1;
        if ((int) $attempts['count'] === 1) {
            $attempts['first'] = time();
        }
        $_SESSION['login_attempts'] = $attempts;

        $remaining = max(0, $maxAttempts - (int) $attempts['count']);
        $errors[] = '用户名或密码错误。' . ($remaining > 0 ? '还可尝试 ' . $remaining . ' 次。' : '请稍后再试。');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>管理员登录 - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/admin.css')) ?>">
</head>
<body class="login-body">
<div class="login-background" aria-hidden="true"></div>

<main class="login-shell">
    <section class="login-visual">
        <a class="login-brand" href="<?= e(app_url('/')) ?>">
            <span class="login-brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>MINDMAP <b>NAV</b></span>
        </a>
        <div class="login-copy">
            <span class="login-kicker">CONTROL THE BRANCHES</span>
            <h1>让你的每个入口<br>都清晰可见。</h1>
            <p>在这里维护站点名称、背景与自定义内容，也能随时调整思维导图节点的顺序和跳转地址。</p>
        </div>
        <div class="login-map" aria-hidden="true">
            <span class="map-root"><?= e($siteName) ?></span>
            <span class="map-line map-line-1"></span>
            <span class="map-line map-line-2"></span>
            <span class="map-line map-line-3"></span>
            <span class="map-node map-node-1">节点 01</span>
            <span class="map-node map-node-2">节点 02</span>
            <span class="map-node map-node-3">节点 03</span>
        </div>
        <a class="login-back" href="<?= e(app_url('/')) ?>">
            <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
                <path d="M11 4 6 9l5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            返回网站首页
        </a>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-card-head">
                <span class="login-kicker">SECURE ACCESS</span>
                <h2>登录管理后台</h2>
                <p>请输入安装时创建的管理员账号。</p>
            </div>

            <?php if ($errors): ?>
                <div class="login-alert<?= (isset($_GET['logout']) && $_GET['logout'] === '1') ? ' is-info' : '' ?>" role="alert">
                    <span class="alert-dot" aria-hidden="true"></span>
                    <span><?= e(implode(' ', $errors)) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="login-form">
                <?= csrf_field() ?>
                <div class="form-field">
                    <label for="username">管理员用户名</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/>
                                <path d="M4.2 16c.8-2.7 3-4.1 5.8-4.1s5 1.4 5.8 4.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value="<?= e($username) ?>"
                            placeholder="请输入管理员用户名"
                            autocomplete="username"
                            maxlength="30"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-field">
                    <label for="password">登录密码</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none">
                                <rect x="4" y="8.5" width="12" height="8" rx="2" stroke="currentColor" stroke-width="1.4"/>
                                <path d="M7 8.5V6a3 3 0 0 1 6 0v2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="请输入登录密码"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" data-password-toggle aria-label="显示或隐藏密码">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M2.5 10s2.7-5 7.5-5 7.5 5 7.5 5-2.7 5-7.5 5-7.5-5-7.5-5Z" stroke="currentColor" stroke-width="1.3"/>
                                <circle cx="10" cy="10" r="2.2" stroke="currentColor" stroke-width="1.3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>进入管理后台</span>
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M4 10h11M10.5 5.5 15 10l-4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </form>

            <div class="login-foot">
                <span>PHP <?= e(PHP_VERSION) ?></span>
                <span>SESSION PROTECTED</span>
            </div>
        </div>
    </section>
</main>

<script src="<?= e(app_url('assets/js/admin.js')) ?>" defer></script>
</body>
</html>
