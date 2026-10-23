<?php
declare(strict_types=1);

/**
 * 前台/后台公共启动文件
 * ------------------------------------------------------------------
 * 负责：
 * 1. 加载公共函数
 * 2. 初始化时区、错误日志、安全会话与响应头
 * 3. 检查安装状态，未安装自动进入安装向导
 * 4. 提供全局数据库连接函数 db()
 */

require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 生产环境不在页面输出错误详情，统一写入错误日志

send_security_headers();
start_secure_session();

// 未安装时，任何前台/后台页面都会自动引导至安装程序
if (!is_installed()) {
    redirect(app_url('install/'));
}

// 已安装的旧版本自动补齐新版数据结构（新装由安装程序一次建全）
ensure_schema_latest();

/**
 * 历史版本数据结构升级：
 * - 新增 access_logs 访问记录表（2.0 引入）
 * - site_settings 增加 front_theme 前台样式模板字段
 *
 * 使用 CREATE TABLE IF NOT EXISTS 与列存在性检查，重复执行无副作用。
 * 升级失败只记录日志，不打断页面访问。
 * 注意：2.0 引入、后已下线的 front_bg_color / admin_bg_color 两列在旧库中保留但不使用。
 */
function ensure_schema_latest(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();

        // 访问记录表：记录引导页首页访问与分支节点点击
        $pdo->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // 站点配置表：补充前台样式模板字段
        $columns = $pdo->query('SHOW COLUMNS FROM site_settings')->fetchAll(PDO::FETCH_COLUMN);
        $columns = is_array($columns) ? array_map('strtolower', array_map('strval', $columns)) : [];

        if (!in_array('front_theme', $columns, true)) {
            $pdo->exec(
                "ALTER TABLE `site_settings`
                 ADD COLUMN `front_theme` VARCHAR(20) NOT NULL DEFAULT 'dark' AFTER `background_image`"
            );
        }
    } catch (Throwable $e) {
        error_log('数据结构升级失败：' . $e->getMessage());
    }
}

/**
 * 获取全局唯一的 PDO 数据库连接。
 * 所有数据库参数均来自安装程序生成的 config/config.php。
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = load_config();
    $database = $config['database'] ?? [];

    $host = (string) ($database['host'] ?? '');
    $port = (int) ($database['port'] ?? 3306);
    $name = (string) ($database['name'] ?? '');
    $user = (string) ($database['user'] ?? '');
    $password = (string) ($database['password'] ?? '');
    $charset = (string) ($database['charset'] ?? 'utf8mb4');

    if ($host === '' || $name === '') {
        http_response_code(500);
        exit('数据库配置不完整，请重新运行安装程序。');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port > 0 ? $port : 3306,
        $name,
        $charset
    );

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $e) {
        error_log('数据库连接失败：' . $e->getMessage());
        http_response_code(500);
        exit('数据库连接失败，请检查数据库服务状态或 config/config.php 中的连接参数。');
    }

    return $pdo;
}
