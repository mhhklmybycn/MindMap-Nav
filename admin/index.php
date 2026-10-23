<?php
declare(strict_types=1);

/**
 * 后台管理概览
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = '管理概览';
$activePage = 'dashboard';
$pageSubtitle = '查看站点状态，快速进入常用管理功能';

$siteSettings = get_site_settings();
$siteName = trim($siteSettings['site_name']) !== '' ? $siteSettings['site_name'] : '思维导图导航';
$backgroundPath = normalize_background_path($siteSettings['background_image']);

$nodeCount = 0;
$adminCount = 0;
$recentNodes = [];
$lastNodeUpdate = null;

try {
    $nodeCount = (int) db()->query('SELECT COUNT(*) FROM mindmap_nodes')->fetchColumn();
    $adminCount = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $recentNodes = db()->query(
        'SELECT id, node_name, jump_url, sort_order, updated_at
         FROM mindmap_nodes
         ORDER BY sort_order ASC, id ASC
         LIMIT 5'
    )->fetchAll();
    $lastNodeUpdate = db()->query('SELECT MAX(updated_at) FROM mindmap_nodes')->fetchColumn();
} catch (Throwable $e) {
    error_log('后台概览数据读取失败：' . $e->getMessage());
}

require __DIR__ . '/partials/header.php';
?>

<section class="dashboard-hero">
    <div class="hero-copy">
        <span class="hero-kicker">WELCOME BACK</span>
        <h2>把导航整理成一张清晰的地图。</h2>
        <p>当前站点共有 <strong><?= e($nodeCount) ?></strong> 个访问入口。你可以随时调整节点顺序、名称与链接，也可以上传新的首页背景。</p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="<?= e(app_url('admin/node_form.php')) ?>">
                <span>新增导图节点</span>
                <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
                    <path d="M9 4v10M4 9h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </a>
            <a class="btn btn-secondary" href="<?= e(app_url('/')) ?>" target="_blank" rel="noopener noreferrer">预览网站</a>
        </div>
    </div>
    <div class="hero-diagram" aria-hidden="true">
        <span class="hero-root"><?= e($siteName) ?></span>
        <span class="hero-connector hero-connector-a"></span>
        <span class="hero-connector hero-connector-b"></span>
        <span class="hero-connector hero-connector-c"></span>
        <span class="hero-chip hero-chip-a">01</span>
        <span class="hero-chip hero-chip-b">02</span>
        <span class="hero-chip hero-chip-c">03</span>
    </div>
</section>

<section class="stat-grid" aria-label="站点统计">
    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">导图节点</span>
            <span class="stat-icon stat-icon-green" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="5" cy="10" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="15" cy="5" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="15" cy="15" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M7 9.2 13 5.8M7 10.8l6 3.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($nodeCount) ?></strong>
        <span class="stat-foot">当前展示的入口数量</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">站点背景</span>
            <span class="stat-icon stat-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <rect x="3" y="4" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="7.2" cy="8" r="1.2" stroke="currentColor" stroke-width="1.2"/>
                    <path d="m4.5 14 4-4 2.5 2.3 2-1.8 2.5 3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value stat-value-small"><?= $backgroundPath !== '' ? '已设置' : '默认' ?></strong>
        <span class="stat-foot"><?= $backgroundPath !== '' ? '使用自定义背景图' : '使用内置渐变背景' ?></span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">最近更新</span>
            <span class="stat-icon stat-icon-orange" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M10 6v4.5l3 1.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value stat-value-small"><?= e(format_datetime($lastNodeUpdate ?: null)) ?></strong>
        <span class="stat-foot">节点数据最后修改时间</span>
    </article>
</section>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="panel-kicker">RECENT NODES</span>
                <h2>节点概览</h2>
            </div>
            <a class="text-link" href="<?= e(app_url('admin/nodes.php')) ?>">全部节点</a>
        </div>

        <?php if ($recentNodes): ?>
            <div class="compact-list">
                <?php foreach ($recentNodes as $index => $node): ?>
                    <div class="compact-item">
                        <span class="compact-index"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                        <div class="compact-copy">
                            <strong><?= e($node['node_name']) ?></strong>
                            <span><?= e((string) $node['jump_url']) ?></span>
                        </div>
                        <a class="icon-link" href="<?= e(app_url('admin/node_form.php?id=' . (int) $node['id'])) ?>" aria-label="编辑 <?= e($node['node_name']) ?>">
                            编辑
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-panel">
                <span class="empty-mark" aria-hidden="true">+</span>
                <h3>还没有导图节点</h3>
                <p>新增第一个节点后，前台首页就会出现分支入口。</p>
                <a class="btn btn-primary btn-small" href="<?= e(app_url('admin/node_form.php')) ?>">新增节点</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="panel-kicker">QUICK ACTIONS</span>
                <h2>快捷操作</h2>
            </div>
        </div>

        <div class="quick-list">
            <a class="quick-item" href="<?= e(app_url('admin/settings.php')) ?>">
                <span class="quick-num">01</span>
                <span class="quick-copy">
                    <strong>网站基础设置</strong>
                    <small>站点名称 / 背景图 / 自定义 HTML</small>
                </span>
                <span class="quick-arrow" aria-hidden="true">→</span>
            </a>
            <a class="quick-item" href="<?= e(app_url('admin/nodes.php')) ?>">
                <span class="quick-num">02</span>
                <span class="quick-copy">
                    <strong>调整节点排序</strong>
                    <small>上移、下移，改变前台展示顺序</small>
                </span>
                <span class="quick-arrow" aria-hidden="true">→</span>
            </a>
            <a class="quick-item" href="<?= e(app_url('admin/account.php')) ?>">
                <span class="quick-num">03</span>
                <span class="quick-copy">
                    <strong>管理员账号</strong>
                    <small>修改用户名与加密登录密码</small>
                </span>
                <span class="quick-arrow" aria-hidden="true">→</span>
            </a>
            <a class="quick-item" href="<?= e(app_url('admin/stats.php')) ?>">
                <span class="quick-num">04</span>
                <span class="quick-copy">
                    <strong>访问统计</strong>
                    <small>首页与节点访问次数、IP、设备与清除记录</small>
                </span>
                <span class="quick-arrow" aria-hidden="true">→</span>
            </a>
            <a class="quick-item" href="<?= e(app_url('admin/transfer.php')) ?>">
                <span class="quick-num">05</span>
                <span class="quick-copy">
                    <strong>数据备份</strong>
                    <small>导出 / 导入分支节点 JSON（不含访问记录）</small>
                </span>
                <span class="quick-arrow" aria-hidden="true">→</span>
            </a>
        </div>

        <div class="security-note">
            <span class="security-dot" aria-hidden="true"></span>
            <p>当前后台有 <strong><?= e($adminCount) ?></strong> 个管理员账号，所有密码均以不可逆哈希形式存储。</p>
        </div>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
