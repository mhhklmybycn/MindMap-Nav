<?php
declare(strict_types=1);

/**
 * 退出登录
 * 仅接受 POST + CSRF，避免通过 GET 链接被跨站触发退出。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect(app_url('admin/index.php'));
}

verify_csrf();
logout_admin();

redirect(app_url('admin/login.php?logout=1'));
