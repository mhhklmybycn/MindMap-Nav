<?php
declare(strict_types=1);

/**
 * 前台首页
 * ------------------------------------------------------------------
 * - 自动检查安装状态（未安装由 bootstrap.php 跳转到 install/）
 * - 读取网站名称、背景图、自定义 HTML
 * - 读取思维导图节点并按排序号输出
 * - 左右分栏展示节点，由 assets/js/front.js 绘制分支连线
 */

require_once __DIR__ . '/includes/bootstrap.php';

$settings = get_site_settings();
$siteName = trim($settings['site_name']) !== '' ? $settings['site_name'] : '思维导图导航';

$backgroundPath = normalize_background_path($settings['background_image']);
if ($backgroundPath !== '' && !is_file(APP_ROOT . '/' . $backgroundPath)) {
    $backgroundPath = '';
}
$backgroundUrl = $backgroundPath !== '' ? app_url($backgroundPath) : '';

$customHtml = trim($settings['custom_html']) !== '' ? $settings['custom_html'] : '';

// 前台样式模板（白名单校验，非法值回退默认模板）
$frontTheme = normalize_front_theme((string) ($settings['front_theme'] ?? 'dark'));

// 记录一次引导页首页访问（写入失败不影响页面渲染）
log_access('home');

try {
    $stmt = db()->query(
        'SELECT id, node_name, jump_url, sort_order
         FROM mindmap_nodes
         ORDER BY sort_order ASC, id ASC'
    );
    $nodes = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('读取思维导图节点失败：' . $e->getMessage());
    $nodes = [];
}

// 左右分支交替分布，保证视觉平衡
$leftNodes = [];
$rightNodes = [];
foreach ($nodes as $index => $node) {
    if ($index % 2 === 0) {
        $leftNodes[] = ['node' => $node, 'index' => $index];
    } else {
        $rightNodes[] = ['node' => $node, 'index' => $index];
    }
}

// 每个分支节点使用不同强调色，颜色只用于样式，不参与业务
$palette = ['#49e6b3', '#68b9ff', '#ffbd70', '#ff82b7', '#b18cff', '#f4df72'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($siteName) ?> - 思维导图式导航入口">
    <title><?= e($siteName) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/front.css')) ?>">
    <?php // 非默认模板时追加加载对应的模板样式文件（模板名已做白名单校验） ?>
    <?php if ($frontTheme !== 'dark'): ?>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/themes/' . $frontTheme . '.css')) ?>">
    <?php endif; ?>
</head>
<body class="front-body<?= $backgroundUrl !== '' ? ' has-background' : '' ?>">
<div class="site-background" aria-hidden="true"<?= $backgroundUrl !== '' ? ' style="background-image:url(\'' . e($backgroundUrl) . '\')"' : '' ?>></div>
<div class="site-overlay" aria-hidden="true"></div>
<div class="site-grain" aria-hidden="true"></div>

<div class="site-shell">
    <header class="site-topbar">
        <a class="topbar-brand" href="<?= e(app_url('/')) ?>" aria-label="返回首页">
            <span class="topbar-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="topbar-name">MINDMAP <b>NAV</b></span>
        </a>
        <div class="topbar-right">
            <span class="online-pill"><span aria-hidden="true"></span>ONLINE</span>
            <a class="admin-entry" href="<?= e(app_url('admin/login.php')) ?>">管理后台</a>
        </div>
    </header>

    <main class="mindmap-stage<?= !$nodes ? ' is-empty' : '' ?>" id="mindmapStage">
        <svg class="branch-lines" id="branchLines" aria-hidden="true"></svg>

        <?php if ($nodes): ?>
            <div class="branch-col branch-left" aria-label="左侧导航分支">
                <?php foreach ($leftNodes as $item): ?>
                    <?php
                    $node = $item['node'];
                    $nodeIndex = $item['index'];
                    $accent = $palette[$nodeIndex % count($palette)];
                    $url = safe_node_url((string) $node['jump_url']);
                    ?>
                    <a
                        class="branch-card"
                        href="<?= e(app_url('go.php?id=' . (int) $node['id'])) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="--accent: <?= e($accent) ?>; --delay: <?= 80 + $nodeIndex * 70 ?>ms;"
                        aria-label="打开：<?= e($node['node_name']) ?>"
                        data-check-url="<?= e($url) ?>"
                    >
                        <span class="node-rail" aria-hidden="true">
                            <span class="node-index"><?= e(str_pad((string) ($nodeIndex + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                            <span class="node-dot"></span>
                        </span>
                        <span class="node-copy">
                            <span class="node-name"><?= e($node['node_name']) ?></span>
                            <span class="node-meta">
                                <span class="node-url"><?= e(node_url_label((string) $node['jump_url'])) ?></span>
                                <span class="node-sep" aria-hidden="true"></span>
                                <span><?= is_external_url((string) $node['jump_url']) ? '外部链接' : '站内页面' ?></span>
                            </span>
                            <span class="node-status is-checking" data-node-status>
                                <span class="status-dot" aria-hidden="true"></span>
                                <span class="status-text">检测中</span>
                                <span class="status-latency" aria-hidden="true"></span>
                            </span>
                        </span>
                        <span class="node-arrow" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M4 10h11M10.5 5.5 15 10l-4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="mindmap-root" id="mindmapRoot">
                <span class="root-kicker">EXPLORE THE MAP</span>
                <h1><?= e($siteName) ?></h1>
                <p class="root-hint">选择一个分支，开启你的目的地</p>
                <div class="root-info">
                    <span><?= count($nodes) ?> 个入口</span>
                    <span aria-hidden="true">/</span>
                    <span>点击即跳转</span>
                </div>
                <span class="root-port root-port-left" aria-hidden="true"></span>
                <span class="root-port root-port-right" aria-hidden="true"></span>
            </div>

            <div class="branch-col branch-right" aria-label="右侧导航分支">
                <?php foreach ($rightNodes as $item): ?>
                    <?php
                    $node = $item['node'];
                    $nodeIndex = $item['index'];
                    $accent = $palette[$nodeIndex % count($palette)];
                    $url = safe_node_url((string) $node['jump_url']);
                    ?>
                    <a
                        class="branch-card"
                        href="<?= e(app_url('go.php?id=' . (int) $node['id'])) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="--accent: <?= e($accent) ?>; --delay: <?= 80 + $nodeIndex * 70 ?>ms;"
                        aria-label="打开：<?= e($node['node_name']) ?>"
                        data-check-url="<?= e($url) ?>"
                    >
                        <span class="node-rail" aria-hidden="true">
                            <span class="node-index"><?= e(str_pad((string) ($nodeIndex + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                            <span class="node-dot"></span>
                        </span>
                        <span class="node-copy">
                            <span class="node-name"><?= e($node['node_name']) ?></span>
                            <span class="node-meta">
                                <span class="node-url"><?= e(node_url_label((string) $node['jump_url'])) ?></span>
                                <span class="node-sep" aria-hidden="true"></span>
                                <span><?= is_external_url((string) $node['jump_url']) ? '外部链接' : '站内页面' ?></span>
                            </span>
                            <span class="node-status is-checking" data-node-status>
                                <span class="status-dot" aria-hidden="true"></span>
                                <span class="status-text">检测中</span>
                                <span class="status-latency" aria-hidden="true"></span>
                            </span>
                        </span>
                        <span class="node-arrow" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M4 10h11M10.5 5.5 15 10l-4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="mindmap-root mindmap-root-empty" id="mindmapRoot">
                <span class="root-kicker">EMPTY MAP</span>
                <h1><?= e($siteName) ?></h1>
                <p class="root-hint">还没有添加导图节点</p>
                <div class="empty-map-note">
                    <span>登录后台后，在「节点管理」中添加你的第一个入口。</span>
                    <a href="<?= e(app_url('admin/login.php')) ?>">前往后台</a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($customHtml !== ''): ?>
        <section class="custom-html-section" aria-label="站点自定义内容">
            <div class="custom-html-head">
                <span class="section-label">EXTRA / CONTENT</span>
                <span class="custom-html-line" aria-hidden="true"></span>
            </div>
            <div class="custom-html-content">
                <?= $settings['custom_html'] ?>
            </div>
        </section>
    <?php endif; ?>

    <footer class="site-footer">
        <span><?= e($siteName) ?></span>
        <span class="footer-center">DESIGNED AS A MIND MAP</span>
        <span>&copy; <?= e(date('Y')) ?></span>
    </footer>
</div>

<script src="<?= e(app_url('assets/js/front.js')) ?>" defer></script>
</body>
</html>
