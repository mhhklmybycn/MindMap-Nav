<?php
declare(strict_types=1);

/**
 * 访问统计
 * ------------------------------------------------------------------
 * - 汇总引导页首页与分支节点的访问次数、独立 IP
 * - 每个节点的点击次数与最近访问时间
 * - 最近访问明细：时间、类型、节点、IP、设备、来源
 * - 支持清除：全部记录 / 仅首页记录 / 仅节点记录 / 指定节点记录
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = '访问统计';
$activePage = 'stats';
$pageSubtitle = '查看引导页首页与各分支节点的访问情况';

// ------------------------------------------------------------------
// 清除记录操作（POST + CSRF + 前端确认）
// ------------------------------------------------------------------
if (is_post()) {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'clear_all':
                db()->exec('DELETE FROM access_logs');
                flash_set('success', '已清空全部访问记录。');
                break;

            case 'clear_home':
                db()->exec("DELETE FROM access_logs WHERE target_type = 'home'");
                flash_set('success', '已清除引导页首页的访问记录。');
                break;

            case 'clear_node':
                $clearNodeId = (int) ($_POST['node_id'] ?? 0);
                if ($clearNodeId <= 0) {
                    flash_set('error', '缺少要清除的节点参数。');
                    break;
                }
                $clearStmt = db()->prepare("DELETE FROM access_logs WHERE target_type = 'node' AND node_id = ?");
                $clearStmt->execute([$clearNodeId]);
                flash_set('success', '已清除该节点的访问记录。');
                break;

            default:
                flash_set('error', '未知的清除操作。');
                break;
        }
    } catch (Throwable $e) {
        error_log('清除访问记录失败：' . $e->getMessage());
        flash_set('error', '清除访问记录失败，请稍后重试。');
    }

    redirect(app_url('admin/stats.php'));
}

// ------------------------------------------------------------------
// 汇总数据
// ------------------------------------------------------------------
$summary = [
    'home_total' => 0,
    'home_today' => 0,
    'node_total' => 0,
    'node_today' => 0,
    'ip_total' => 0,
    'ip_today' => 0,
];
$nodeAgg = [];
$logs = [];
$logsTotal = 0;

// 明细筛选与分页
$filter = (string) ($_GET['type'] ?? 'all');
if (!in_array($filter, ['all', 'home', 'node'], true)) {
    $filter = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 25;

try {
    $pdo = db();

    $summary['home_total'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE target_type = 'home'")->fetchColumn();
    $summary['home_today'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE target_type = 'home' AND created_at >= CURDATE()")->fetchColumn();
    $summary['node_total'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE target_type = 'node'")->fetchColumn();
    $summary['node_today'] = (int) $pdo->query("SELECT COUNT(*) FROM access_logs WHERE target_type = 'node' AND created_at >= CURDATE()")->fetchColumn();
    $summary['ip_total'] = (int) $pdo->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE ip <> ''")->fetchColumn();
    $summary['ip_today'] = (int) $pdo->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE ip <> '' AND created_at >= CURDATE()")->fetchColumn();

    // 各分支节点点击聚合（最新名称通过子查询获取，避免 GROUP_CONCAT 截断）
    $nodeAgg = $pdo->query(
        "SELECT a.node_id,
                COUNT(*) AS total,
                MAX(a.created_at) AS last_at,
                (SELECT al.node_name
                 FROM access_logs al
                 WHERE al.target_type = 'node' AND al.node_id = a.node_id
                 ORDER BY al.created_at DESC, al.id DESC
                 LIMIT 1) AS latest_name
         FROM access_logs a
         WHERE a.target_type = 'node'
         GROUP BY a.node_id
         ORDER BY total DESC, last_at DESC"
    )->fetchAll();

    // 明细总数与当页数据
    if ($filter === 'all') {
        $logsTotal = (int) $pdo->query('SELECT COUNT(*) FROM access_logs')->fetchColumn();
    } else {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM access_logs WHERE target_type = ?');
        $countStmt->execute([$filter]);
        $logsTotal = (int) $countStmt->fetchColumn();
    }

    $totalPages = max(1, (int) ceil($logsTotal / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    if ($filter === 'all') {
        $logStmt = $pdo->prepare(
            'SELECT id, target_type, node_id, node_name, jump_url, ip, device, user_agent, referer, created_at
             FROM access_logs
             ORDER BY id DESC
             LIMIT ? OFFSET ?'
        );
        $logStmt->bindValue(1, $pageSize, PDO::PARAM_INT);
        $logStmt->bindValue(2, $offset, PDO::PARAM_INT);
        $logStmt->execute();
    } else {
        $logStmt = $pdo->prepare(
            'SELECT id, target_type, node_id, node_name, jump_url, ip, device, user_agent, referer, created_at
             FROM access_logs
             WHERE target_type = ?
             ORDER BY id DESC
             LIMIT ? OFFSET ?'
        );
        $logStmt->bindValue(1, $filter);
        $logStmt->bindValue(2, $pageSize, PDO::PARAM_INT);
        $logStmt->bindValue(3, $offset, PDO::PARAM_INT);
        $logStmt->execute();
    }
    $logs = $logStmt->fetchAll();
} catch (Throwable $e) {
    error_log('读取访问统计失败：' . $e->getMessage());
    $totalPages = 1;
}

/**
 * 构造保留筛选条件的分页链接。
 */
function stats_page_url(string $filter, int $page): string
{
    $query = http_build_query(['type' => $filter, 'page' => $page]);
    return app_url('admin/stats.php') . ($query !== '' ? '?' . $query : '');
}

require __DIR__ . '/partials/header.php';
?>

<section class="stat-grid stat-grid-6" aria-label="访问汇总">
    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">首页访问</span>
            <span class="stat-icon stat-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <path d="M3.5 9.5 10 3.8l6.5 5.7M5.2 8.3v7.2h9.6V8.3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['home_total']) ?></strong>
        <span class="stat-foot">引导页首页累计访问次数</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">今日首页</span>
            <span class="stat-icon stat-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M10 6v4.5l3 1.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['home_today']) ?></strong>
        <span class="stat-foot">今天引导页首页访问次数</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">节点点击</span>
            <span class="stat-icon stat-icon-green" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="5" cy="10" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="15" cy="5" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="15" cy="15" r="2" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M7 9.2 13 5.8M7 10.8l6 3.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['node_total']) ?></strong>
        <span class="stat-foot">全部分支节点累计点击次数</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">今日节点</span>
            <span class="stat-icon stat-icon-green" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <path d="M4 15.5 8 11l3 2.5 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['node_today']) ?></strong>
        <span class="stat-foot">今天分支节点点击次数</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">独立 IP</span>
            <span class="stat-icon stat-icon-orange" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M4.2 16c.8-2.7 3-4.1 5.8-4.1s5 1.4 5.8 4.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['ip_total']) ?></strong>
        <span class="stat-foot">累计去重访客 IP 数</span>
    </article>

    <article class="stat-card">
        <div class="stat-head">
            <span class="stat-label">今日独立 IP</span>
            <span class="stat-icon stat-icon-orange" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M4.2 16c.8-2.7 3-4.1 5.8-4.1s5 1.4 5.8 4.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
            </span>
        </div>
        <strong class="stat-value"><?= e($summary['ip_today']) ?></strong>
        <span class="stat-foot">今天去重访客 IP 数</span>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="panel-kicker">NODE CLICKS</span>
            <h2>分支节点访问情况</h2>
        </div>
        <?php if ($nodeAgg): ?>
            <span class="soft-badge"><?= count($nodeAgg) ?> 个节点</span>
        <?php endif; ?>
    </div>

    <?php if ($nodeAgg): ?>
        <div class="table-wrap">
            <table class="node-table stats-table">
                <thead>
                    <tr>
                        <th>节点</th>
                        <th>访问次数</th>
                        <th>最近访问时间</th>
                        <th class="actions-col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nodeAgg as $index => $row): ?>
                        <tr>
                            <td>
                                <span class="order-badge"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                                <span class="node-name-cell"><strong><?= e((string) $row['latest_name'] !== '' ? $row['latest_name'] : '节点 #' . (int) $row['node_id']) ?></strong></span>
                            </td>
                            <td><strong><?= e((int) $row['total']) ?></strong> 次</td>
                            <td><span class="time-cell"><?= e(format_datetime_full((string) ($row['last_at'] ?? ''))) ?></span></td>
                            <td>
                                <form method="post" action="" data-confirm="确定清除该节点的全部访问记录吗？此操作不可恢复。">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="clear_node">
                                    <input type="hidden" name="node_id" value="<?= (int) $row['node_id'] ?>">
                                    <button type="submit" class="mini-btn mini-btn-danger">清除记录</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-panel">
            <span class="empty-mark" aria-hidden="true">∅</span>
            <h3>暂无节点访问记录</h3>
            <p>访客在前台点击分支节点后，这里会显示每个节点的访问次数与最近访问时间。</p>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="panel-kicker">ACCESS LOGS</span>
            <h2>访问明细</h2>
        </div>
        <div class="panel-actions">
            <form method="post" action="" data-confirm="确定清除引导页首页的全部访问记录吗？">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_home">
                <button type="submit" class="btn btn-secondary btn-small">清除首页记录</button>
            </form>
            <form method="post" action="" data-confirm="确定清空全部访问记录吗？包含首页与所有节点，此操作不可恢复。">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="btn btn-secondary btn-small btn-danger-outline">清空全部记录</button>
            </form>
        </div>
    </div>

    <div class="filter-tabs" role="tablist">
        <a class="filter-tab<?= $filter === 'all' ? ' is-active' : '' ?>" href="<?= e(stats_page_url('all', 1)) ?>">全部</a>
        <a class="filter-tab<?= $filter === 'home' ? ' is-active' : '' ?>" href="<?= e(stats_page_url('home', 1)) ?>">引导页首页</a>
        <a class="filter-tab<?= $filter === 'node' ? ' is-active' : '' ?>" href="<?= e(stats_page_url('node', 1)) ?>">分支节点</a>
    </div>

    <?php if ($logs): ?>
        <div class="table-wrap">
            <table class="node-table stats-table">
                <thead>
                    <tr>
                        <th>访问时间</th>
                        <th>访问目标</th>
                        <th>访问 IP</th>
                        <th>访问设备</th>
                        <th>来源页面</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php $isHome = ($log['target_type'] ?? '') === 'home'; ?>
                        <tr>
                            <td><span class="time-cell"><?= e(format_datetime_full((string) ($log['created_at'] ?? ''))) ?></span></td>
                            <td>
                                <?php if ($isHome): ?>
                                    <span class="log-target log-target-home">引导页首页</span>
                                <?php else: ?>
                                    <span class="log-target log-target-node" title="<?= e((string) ($log['jump_url'] ?? '')) ?>">
                                        <?= e((string) ($log['node_name'] ?? '') !== '' ? $log['node_name'] : '节点 #' . (int) $log['node_id']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><span class="ip-cell"><?= e((string) ($log['ip'] ?? '') !== '' ? $log['ip'] : '未知') ?></span></td>
                            <td><span title="<?= e((string) ($log['user_agent'] ?? '')) ?>"><?= e((string) ($log['device'] ?? '') !== '' ? $log['device'] : '未知设备') ?></span></td>
                            <td>
                                <?php $referer = trim((string) ($log['referer'] ?? '')); ?>
                                <?php if ($referer !== ''): ?>
                                    <span class="referer-cell" title="<?= e($referer) ?>"><?= e($referer) ?></span>
                                <?php else: ?>
                                    <span class="muted-cell">直接访问</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="访问记录分页">
                <?php if ($page > 1): ?>
                    <a class="page-link" href="<?= e(stats_page_url($filter, $page - 1)) ?>">上一页</a>
                <?php endif; ?>
                <span class="page-info">第 <?= e($page) ?> / <?= e($totalPages) ?> 页 · 共 <?= e($logsTotal) ?> 条</span>
                <?php if ($page < $totalPages): ?>
                    <a class="page-link" href="<?= e(stats_page_url($filter, $page + 1)) ?>">下一页</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-panel">
            <span class="empty-mark" aria-hidden="true">∅</span>
            <h3>暂无访问记录</h3>
            <p>引导页首页被访问或分支节点被点击后，这里会显示详细的访问记录。</p>
        </div>
    <?php endif; ?>
</section>

<section class="tips-card tips-card-warn">
    <span class="panel-kicker">PRIVACY</span>
    <h3>关于访问记录</h3>
    <ul>
        <li>IP 取自连接的 REMOTE_ADDR，不使用可伪造的 X-Forwarded-For 头。</li>
        <li>设备信息由 User-Agent 粗略识别，仅供趋势参考。</li>
        <li>访问记录可能涉及访客个人信息，请按当地法规妥善管理与定期清理。</li>
    </ul>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
