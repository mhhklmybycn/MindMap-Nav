<?php
declare(strict_types=1);

/**
 * 自动安装向导
 * ------------------------------------------------------------------
 * 访问条件：
 * - 未安装时展示安装表单
 * - 已安装时只展示完成状态，不再允许重复安装
 *
 * 安装流程：
 * 1. 校验表单与环境
 * 2. 测试数据库连通性
 * 3. 自动创建数据表并写入初始数据
 * 4. 原子生成 config/config.php（含数据库配置与 installed 标记）
 * 5. 展示安装成功与后台登录入口
 */

require_once dirname(__DIR__) . '/includes/functions.php';

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '0');

send_security_headers();
start_secure_session();

$configDir = APP_ROOT . '/config';
$configFile = $configDir . '/config.php';
$uploadDir = APP_ROOT . '/uploads/backgrounds';

// 确保运行所需目录存在
if (!is_dir($configDir)) {
    @mkdir($configDir, 0755, true);
}
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$installed = is_installed();
$installSuccess = false;
$errors = [];

$form = [
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'admin_username' => 'admin',
    'site_name' => '思维导图导航',
];

// 环境检测
$phpOk = PHP_VERSION_ID >= 80000;
$pdoMysqlOk = extension_loaded('pdo_mysql');
$fileinfoOk = extension_loaded('fileinfo');
$configWritable = is_dir($configDir) && is_writable($configDir);
$uploadWritable = is_dir($uploadDir) && is_writable($uploadDir);

/**
 * 将用户填写的 host 拆分为主机与端口。
 *
 * @return array{0: string, 1: int}
 */
function parse_db_host(string $hostInput): array
{
    $host = trim($hostInput);
    $port = 3306;

    if (preg_match('/^([^:]+):(\d{1,5})$/', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }

    return [$host, $port];
}

/**
 * 原子写入配置文件，避免写入一半导致程序异常。
 */
function write_config_file(string $target, array $config): void
{
    $content = "<?php\n"
        . "/**\n"
        . " * 本文件由安装程序自动生成，请勿手动修改。\n"
        . " * 包含数据库连接信息、安装标记与安全密钥。\n"
        . " */\n"
        . "return " . var_export($config, true) . ";\n";

    $tempFile = tempnam(dirname($target), '.install-config-');
    if ($tempFile === false) {
        throw new RuntimeException('无法创建临时配置文件，请检查 config 目录权限。');
    }

    $written = file_put_contents($tempFile, $content, LOCK_EX);
    if ($written === false) {
        @unlink($tempFile);
        throw new RuntimeException('配置文件写入失败，请检查 config 目录权限。');
    }

    @chmod($tempFile, 0640);

    // Windows 下 rename 不能直接覆盖已存在的文件，先移除旧的无效配置
    if (is_file($target) && !@unlink($target)) {
        @unlink($tempFile);
        throw new RuntimeException('无法替换旧的配置文件，请检查 config 目录权限。');
    }

    if (!@rename($tempFile, $target)) {
        @unlink($tempFile);
        throw new RuntimeException('配置文件无法保存到 config/config.php，请检查目录权限。');
    }

    @chmod($target, 0640);
}

if (!$installed && is_post()) {
    verify_csrf();

    $form['db_host'] = trim((string) ($_POST['db_host'] ?? ''));
    $form['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
    $form['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
    $form['admin_username'] = trim((string) ($_POST['admin_username'] ?? ''));
    $form['site_name'] = trim((string) ($_POST['site_name'] ?? ''));

    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    if (!$phpOk) {
        $errors[] = '当前 PHP 版本低于 8.0，无法继续安装。';
    }
    if (!$pdoMysqlOk) {
        $errors[] = '缺少 PDO MySQL 扩展，请先安装或启用 pdo_mysql。';
    }
    if (!$fileinfoOk) {
        $errors[] = '缺少 fileinfo 扩展，后台图片上传功能将无法安全校验文件类型。';
    }
    if (!$configWritable) {
        $errors[] = 'config 目录不可写，请为该目录设置写权限。';
    }
    if (!$uploadWritable) {
        $errors[] = 'uploads/backgrounds 目录不可写，请为该目录设置写权限。';
    }

    if ($form['db_host'] === '') {
        $errors[] = '请填写数据库主机。';
    }
    if ($form['db_name'] === '') {
        $errors[] = '请填写数据库名称。';
    }
    if ($form['db_user'] === '') {
        $errors[] = '请填写数据库账号。';
    }

    [$dbHost, $dbPort] = parse_db_host($form['db_host']);

    if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', $dbHost)) {
        $errors[] = '数据库主机格式不正确，只支持域名、IP 或 localhost，可附加端口。';
    }
    if ($dbPort < 1 || $dbPort > 65535) {
        $errors[] = '数据库端口必须在 1 - 65535 之间。';
    }
    if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $form['db_name'])) {
        $errors[] = '数据库名称只允许字母、数字、下划线、短横线和美元符号。';
    }
    if (text_length($form['db_user']) > 128) {
        $errors[] = '数据库账号长度不能超过 128 个字符。';
    }
    if (!preg_match('/^[\p{L}\p{N}_-]{3,30}$/u', $form['admin_username'])) {
        $errors[] = '管理员用户名需为 3 - 30 位中文、字母、数字、下划线或短横线。';
    }
    if (text_length($adminPassword) < 8 || text_length($adminPassword) > 72) {
        $errors[] = '管理员密码长度需为 8 - 72 个字符。';
    }
    if ($form['site_name'] === '' || text_length($form['site_name']) > 100) {
        $errors[] = '网站名称不能为空，且不能超过 100 个字符。';
    }

    if (!$errors) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbHost,
            $dbPort,
            $form['db_name']
        );

        $configWritten = false;

        try {
            // 第一步：测试数据库连通性
            $pdo = new PDO($dsn, $form['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // 第二步：自动创建数据表
            $schema = [
                "CREATE TABLE IF NOT EXISTS `admins` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `username` VARCHAR(50) NOT NULL,
                    `password_hash` VARCHAR(255) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_admin_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `mindmap_nodes` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `node_name` VARCHAR(100) NOT NULL,
                    `jump_url` VARCHAR(500) NOT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_sort_order` (`sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `site_settings` (
                    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `site_name` VARCHAR(100) NOT NULL,
                    `background_image` VARCHAR(255) NOT NULL DEFAULT '',
                    `front_theme` VARCHAR(20) NOT NULL DEFAULT 'dark',
                    `custom_html` MEDIUMTEXT NOT NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `access_logs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `target_type` ENUM('home','node') NOT NULL DEFAULT 'home',
                    `node_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `node_name` VARCHAR(100) NOT NULL DEFAULT '',
                    `jump_url` VARCHAR(500) NOT NULL DEFAULT '',
                    `ip` VARCHAR(45) NOT NULL DEFAULT '',
                    `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
                    `device` VARCHAR(80) NOT NULL DEFAULT '',
                    `referer` VARCHAR(500) NOT NULL DEFAULT '',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_target` (`target_type`, `node_id`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];

            foreach ($schema as $sql) {
                $pdo->exec($sql);
            }

            // 第三步：写入管理员与网站配置
            $pdo->beginTransaction();

            $existingAdmin = $pdo->query('SELECT id FROM admins LIMIT 1 FOR UPDATE')->fetchColumn();
            if ($existingAdmin !== false) {
                throw new RuntimeException('该数据库已存在管理员账号，程序可能已经安装过，请勿重复安装。');
            }

            $now = date('Y-m-d H:i:s');
            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('管理员密码加密失败，请确认 PHP 加密组件正常。');
            }

            $adminStmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)'
            );
            $adminStmt->execute([$form['admin_username'], $passwordHash, $now, $now]);

            $settingsStmt = $pdo->prepare(
                'INSERT INTO site_settings (id, site_name, background_image, custom_html)
                 VALUES (1, ?, "", "")
                 ON DUPLICATE KEY UPDATE site_name = ?'
            );
            $settingsStmt->execute([$form['site_name'], $form['site_name']]);

            // 第四步：生成配置文件（含安装标记）
            $newConfig = [
                'installed' => true,
                'installed_at' => $now,
                'database' => [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $form['db_name'],
                    'user' => $form['db_user'],
                    'password' => $dbPassword,
                    'charset' => 'utf8mb4',
                ],
            ];

            write_config_file($configFile, $newConfig);
            $configWritten = true;

            $pdo->commit();

            // 安装成功后刷新 CSRF Token
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            $installSuccess = true;
            $installed = true;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($configWritten && is_file($configFile)) {
                @unlink($configFile);
            }

            $errors[] = '数据库连接或初始化失败：' . $e->getMessage();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($configWritten && is_file($configFile)) {
                @unlink($configFile);
            }

            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>安装向导 - 思维导图导航系统</title>
    <link rel="stylesheet" href="<?= e(app_url('install/install.css')) ?>">
</head>
<body class="install-body">
<div class="install-aurora" aria-hidden="true"></div>

<main class="install-wrap">
    <header class="install-hero">
        <div class="install-brand">
            <span class="install-brand-mark" aria-hidden="true">
                <span></span><span></span><span></span>
            </span>
            <span>MINDMAP NAV</span>
        </div>
        <h1><?= $installSuccess ? '安装完成' : '站点安装向导' ?></h1>
        <p>原生 PHP 8 · MySQL · 无需 Composer / npm</p>
    </header>

    <?php if ($installed && !$installSuccess): ?>
        <section class="install-card install-state-card">
            <span class="state-badge state-badge-ok">SYSTEM READY</span>
            <h2>程序已经安装</h2>
            <p>检测到有效的安装配置文件。为保护站点数据，安装程序已停止重复安装。</p>
            <div class="install-actions">
                <a class="btn btn-primary" href="<?= e(app_url('admin/login.php')) ?>">进入后台登录</a>
                <a class="btn btn-ghost" href="<?= e(app_url('/')) ?>">访问网站首页</a>
            </div>
            <p class="install-tip">如需重新安装，请先备份并删除 <code>config/config.php</code>，再清理对应数据库表。</p>
        </section>
    <?php elseif ($installSuccess): ?>
        <section class="install-card install-state-card install-success-card">
            <span class="state-badge state-badge-ok">INSTALL COMPLETE</span>
            <h2>站点已成功安装</h2>
            <p>数据表已创建，管理员账号与网站配置已写入，安装标记已保存。</p>
            <dl class="success-meta">
                <div>
                    <dt>网站名称</dt>
                    <dd><?= e($form['site_name']) ?></dd>
                </div>
                <div>
                    <dt>管理员账号</dt>
                    <dd><?= e($form['admin_username']) ?></dd>
                </div>
                <div>
                    <dt>下一步</dt>
                    <dd>登录后台添加思维导图节点</dd>
                </div>
            </dl>
            <div class="install-actions">
                <a class="btn btn-primary" href="<?= e(app_url('admin/login.php')) ?>">登录管理后台</a>
                <a class="btn btn-ghost" href="<?= e(app_url('/')) ?>">查看网站首页</a>
            </div>
        </section>
    <?php else: ?>
        <div class="install-layout">
            <section class="install-card">
                <div class="card-heading">
                    <div>
                        <span class="section-kicker">STEP 01 — CONFIGURATION</span>
                        <h2>填写连接与账号信息</h2>
                    </div>
                    <span class="step-count">01 / 01</span>
                </div>

                <?php if ($errors): ?>
                    <div class="install-alert install-alert-error" role="alert">
                        <strong>安装未完成</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif (!$phpOk || !$pdoMysqlOk || !$fileinfoOk || !$configWritable || !$uploadWritable): ?>
                    <div class="install-alert install-alert-error" role="alert">
                        <strong>环境检测未通过</strong>
                        <ul>
                            <li>请先根据右侧「环境检测」修正标红项，然后刷新本页面再继续安装。</li>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="" autocomplete="off" novalidate>
                    <?= csrf_field() ?>

                    <fieldset class="form-section">
                        <legend>
                            <span class="legend-index">01</span>
                            数据库配置
                        </legend>
                        <div class="form-grid">
                            <div class="field field-wide">
                                <label for="db_host">数据库主机</label>
                                <input
                                    type="text"
                                    id="db_host"
                                    name="db_host"
                                    value="<?= e($form['db_host']) ?>"
                                    placeholder="localhost 或 127.0.0.1"
                                    required
                                >
                                <small>如端口非 3306，可填写 localhost:3307</small>
                            </div>
                            <div class="field">
                                <label for="db_name">数据库名称</label>
                                <input
                                    type="text"
                                    id="db_name"
                                    name="db_name"
                                    value="<?= e($form['db_name']) ?>"
                                    placeholder="例如 mindmap_nav"
                                    required
                                >
                                <small>数据库需提前创建，程序只自动建表</small>
                            </div>
                            <div class="field">
                                <label for="db_user">数据库账号</label>
                                <input
                                    type="text"
                                    id="db_user"
                                    name="db_user"
                                    value="<?= e($form['db_user']) ?>"
                                    placeholder="数据库用户名"
                                    required
                                >
                            </div>
                            <div class="field field-wide">
                                <label for="db_password">数据库密码</label>
                                <input
                                    type="password"
                                    id="db_password"
                                    name="db_password"
                                    value=""
                                    placeholder="请输入数据库密码，可为空"
                                    autocomplete="new-password"
                                >
                                <small>密码只会写入受保护的配置文件，不会存入数据表</small>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="form-section">
                        <legend>
                            <span class="legend-index">02</span>
                            站点账号
                        </legend>
                        <div class="form-grid">
                            <div class="field">
                                <label for="admin_username">管理员用户名</label>
                                <input
                                    type="text"
                                    id="admin_username"
                                    name="admin_username"
                                    value="<?= e($form['admin_username']) ?>"
                                    placeholder="3 - 30 位字符"
                                    required
                                >
                            </div>
                            <div class="field">
                                <label for="admin_password">管理员密码</label>
                                <input
                                    type="password"
                                    id="admin_password"
                                    name="admin_password"
                                    value=""
                                    placeholder="至少 8 个字符"
                                    minlength="8"
                                    maxlength="72"
                                    autocomplete="new-password"
                                    required
                                >
                                
                            </div>
                            <div class="field field-wide">
                                <label for="site_name">网站名称</label>
                                <input
                                    type="text"
                                    id="site_name"
                                    name="site_name"
                                    value="<?= e($form['site_name']) ?>"
                                    placeholder="显示在首页与后台的名称"
                                    maxlength="100"
                                    required
                                >
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary btn-block" <?= (!$phpOk || !$pdoMysqlOk || !$fileinfoOk || !$configWritable || !$uploadWritable) ? 'disabled' : '' ?>>
                        开始安装并创建数据表
                    </button>
                </form>
            </section>

            <aside class="install-side">
                <section class="side-card">
                    <span class="section-kicker">ENVIRONMENT</span>
                    <h2>环境检测</h2>
                    <ul class="check-list">
                        <li class="<?= $phpOk ? 'is-ok' : 'is-bad' ?>">
                            <span class="check-icon"></span>
                            <span>PHP 8.0+</span>
                            <em><?= e(PHP_VERSION) ?></em>
                        </li>
                        <li class="<?= $pdoMysqlOk ? 'is-ok' : 'is-bad' ?>">
                            <span class="check-icon"></span>
                            <span>PDO MySQL</span>
                            <em><?= $pdoMysqlOk ? '已启用' : '未启用' ?></em>
                        </li>
                        <li class="<?= $fileinfoOk ? 'is-ok' : 'is-bad' ?>">
                            <span class="check-icon"></span>
                            <span>Fileinfo</span>
                            <em><?= $fileinfoOk ? '已启用' : '未启用' ?></em>
                        </li>
                        <li class="<?= $configWritable ? 'is-ok' : 'is-bad' ?>">
                            <span class="check-icon"></span>
                            <span>config 可写</span>
                            <em><?= $configWritable ? '是' : '否' ?></em>
                        </li>
                        <li class="<?= $uploadWritable ? 'is-ok' : 'is-bad' ?>">
                            <span class="check-icon"></span>
                            <span>图片目录可写</span>
                            <em><?= $uploadWritable ? '是' : '否' ?></em>
                        </li>
                    </ul>
                </section>

                <section class="side-card side-note">
                    <span class="section-kicker">BEFORE START</span>
                    <h2>安装前请确认</h2>
                    <ol>
                        <li>MySQL 数据库已创建，账号拥有建表与读写权限。</li>
                        <li>Web 服务器可执行 PHP，且版本不低于 8.0。</li>
                        <li>请使用 HTTPS 部署后台，避免登录信息被窃听。</li>
                        <li>安装完成后建议备份 <code>config/config.php</code>。</li>
                    </ol>
                </section>
            </aside>
        </div>
    <?php endif; ?>

    <footer class="install-footer">
        <span>MindMap Nav Installer</span>
        <span><?= e(date('Y')) ?> · 数据由你掌控</span>
    </footer>
</main>
</body>
</html>
