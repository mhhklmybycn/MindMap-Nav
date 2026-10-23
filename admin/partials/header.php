<?php
declare(strict_types=1);

/**
 * 后台公共头部
 * ------------------------------------------------------------------
 * 使用前需先加载 includes/bootstrap.php 并执行 require_admin()。
 *
 * 可用变量：
 * - $pageTitle   页面标题
 * - $activePage  当前导航：dashboard / nodes / stats / transfer / settings / account
 * - $pageSubtitle 可选副标题
 */

if (!defined('APP_ROOT')) {
    exit('非法访问');
}

$adminUser = admin_user();
$siteSettings = get_site_settings();
$flashMessages = flash_get_all();

$pageTitle = $pageTitle ?? '管理后台';
$activePage = $activePage ?? 'dashboard';
$pageSubtitle = $pageSubtitle ?? '管理站点内容与访问入口';
$currentSiteName = trim($siteSettings['site_name']) !== '' ? $siteSettings['site_name'] : '思维导图导航';
$adminName = (string) ($adminUser['username'] ?? '管理员');
$adminInitial = function_exists('mb_substr')
    ? mb_substr($adminName, 0, 1, 'UTF-8')
    : substr($adminName, 0, 1);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($pageTitle) ?> - <?= e($currentSiteName) ?>后台</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/admin.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="后台导航">
        <div class="sidebar-brand">
            <span class="brand-symbol" aria-hidden="true"><i></i><i></i><i></i></span>
            <div class="brand-copy">
                <strong title="<?= e($currentSiteName) ?>"><?= e($currentSiteName) ?></strong>
                <span>CONTENT STUDIO</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <span class="nav-label">工作台</span>
            <a href="<?= e(app_url('admin/index.php')) ?>" class="nav-item<?= $activePage === 'dashboard' ? ' is-active' : '' ?>"<?= $activePage === 'dashboard' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <rect x="3" y="3" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="11.5" y="3" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="3" y="11.5" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1.4" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                </span>
                <span>管理概览</span>
                <span class="nav-code">01</span>
            </a>
            <a href="<?= e(app_url('admin/nodes.php')) ?>" class="nav-item<?= $activePage === 'nodes' ? ' is-active' : '' ?>"<?= $activePage === 'nodes' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <circle cx="5" cy="10" r="2" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="15" cy="5" r="2" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="15" cy="15" r="2" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M7 9.2 13 5.8M7 10.8l6 3.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    </svg>
                </span>
                <span>节点管理</span>
                <span class="nav-code">02</span>
            </a>
            <a href="<?= e(app_url('admin/stats.php')) ?>" class="nav-item<?= $activePage === 'stats' ? ' is-active' : '' ?>"<?= $activePage === 'stats' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M3.5 16.5v-5M8.5 16.5V8M13.5 16.5v-3.5M18 16.5V4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M3 3.5h14.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" opacity="0.5"/>
                    </svg>
                </span>
                <span>访问统计</span>
                <span class="nav-code">03</span>
            </a>
            <a href="<?= e(app_url('admin/transfer.php')) ?>" class="nav-item<?= $activePage === 'transfer' ? ' is-active' : '' ?>"<?= $activePage === 'transfer' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M10 3v7M7 6.5 10 3.5l3 3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 17v-7M7 13.5l3 3 3-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>
                    </svg>
                </span>
                <span>数据备份</span>
                <span class="nav-code">04</span>
            </a>

            <span class="nav-label nav-label-space">设置</span>
            <a href="<?= e(app_url('admin/settings.php')) ?>" class="nav-item<?= $activePage === 'settings' ? ' is-active' : '' ?>"<?= $activePage === 'settings' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M10 3.2v1.4M10 15.4v1.4M3.2 10h1.4M15.4 10h1.4M5.2 5.2l1 1M13.8 13.8l1 1M14.8 5.2l-1 1M6.2 13.8l-1 1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    </svg>
                </span>
                <span>网站设置</span>
                <span class="nav-code">05</span>
            </a>
            <a href="<?= e(app_url('admin/account.php')) ?>" class="nav-item<?= $activePage === 'account' ? ' is-active' : '' ?>"<?= $activePage === 'account' ? ' aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M4.2 16c.8-2.7 3-4.1 5.8-4.1s5 1.4 5.8 4.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </span>
                <span>管理员设置</span>
                <span class="nav-code">06</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a class="view-site-link" href="<?= e(app_url('/')) ?>" target="_blank" rel="noopener noreferrer">
                <span>预览前台页面</span>
                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 12 12 4M6 4h6v6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <div class="sidebar-user">
                <span class="user-avatar"><?= e($adminInitial) ?></span>
                <div class="sidebar-user-copy">
                    <strong><?= e($adminName) ?></strong>
                    <span>已登录</span>
                </div>
                <form action="<?= e(app_url('admin/logout.php')) ?>" method="post" class="logout-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="logout-button" title="退出登录" aria-label="退出登录">
                        <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
                            <path d="M7 3H4.5A1.5 1.5 0 0 0 3 4.5v9A1.5 1.5 0 0 0 4.5 15H7M11 6l3 3-3 3M14 9H7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="menu-toggle" id="menuToggle" aria-label="打开导航菜单" aria-expanded="false" aria-controls="adminSidebar">
                    <span></span><span></span><span></span>
                </button>
                <div class="page-heading">
                    <span class="page-eyebrow">ADMIN WORKSPACE / <?= e(strtoupper($activePage)) ?></span>
                    <h1><?= e($pageTitle) ?></h1>
                    <p><?= e($pageSubtitle) ?></p>
                </div>
            </div>
            <div class="topbar-right">
                <span class="topbar-date" aria-hidden="true"><?= e(date('Y.m.d')) ?></span>
                <span class="topbar-avatar" title="<?= e($adminName) ?>"><?= e($adminInitial) ?></span>
            </div>
        </header>

        <main class="admin-content" id="mainContent">
            <?php if ($flashMessages): ?>
                <div class="flash-stack" aria-live="polite">
                    <?php foreach ($flashMessages as $flash): ?>
                        <div class="flash-message flash-<?= e($flash['type']) ?>" data-flash>
                            <span class="flash-mark" aria-hidden="true"></span>
                            <span class="flash-text"><?= e($flash['message']) ?></span>
                            <button type="button" class="flash-close" data-dismiss-flash aria-label="关闭提示">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
