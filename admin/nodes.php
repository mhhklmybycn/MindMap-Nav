<?php
declare(strict_types=1);

/**
 * 思维导图节点管理
 * ------------------------------------------------------------------
 * - 节点列表
 * - 删除节点
 * - 上移 / 下移排序（事务内重新编号，避免排序值冲突）
 * 新增与编辑在 node_form.php 中完成。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = '节点管理';
$activePage = 'nodes';
$pageSubtitle = '维护前台思维导图的分支名称、跳转地址与展示顺序';

if (is_post()) {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $nodeId = (int) ($_POST['id'] ?? 0);

    if ($nodeId <= 0) {
        flash_set('error', '节点参数无效。');
        redirect(app_url('admin/nodes.php'));
    }

    if ($action === 'delete') {
        try {
            $stmt = db()->prepare('DELETE FROM mindmap_nodes WHERE id = ?');
            $stmt->execute([$nodeId]);

            if ($stmt->rowCount() > 0) {
                flash_set('success', '节点已删除。');
            } else {
                flash_set('error', '节点不存在或已被删除。');
            }
        } catch (Throwable $e) {
            error_log('删除节点失败：' . $e->getMessage());
            flash_set('error', '删除节点失败，请稍后重试。');
        }

        redirect(app_url('admin/nodes.php'));
    }

    if ($action === 'move') {
        $direction = (string) ($_POST['direction'] ?? '');

        if (!in_array($direction, ['up', 'down'], true)) {
            flash_set('error', '排序方向无效。');
            redirect(app_url('admin/nodes.php'));
        }

        try {
            db()->beginTransaction();

            $stmt = db()->query('SELECT id FROM mindmap_nodes ORDER BY sort_order ASC, id ASC FOR UPDATE');
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $currentIndex = array_search($nodeId, $ids, true);
            if ($currentIndex === false) {
                throw new RuntimeException('要排序的节点不存在。');
            }

            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
            if ($targetIndex < 0 || $targetIndex >= count($ids)) {
                db()->rollBack();
                flash_set('info', $direction === 'up' ? '该节点已经在最前面。' : '该节点已经在最后面。');
                redirect(app_url('admin/nodes.php'));
            }

            // 交换数组位置后整体重排，顺带修复历史重复排序值
            $temporary = $ids[$currentIndex];
            $ids[$currentIndex] = $ids[$targetIndex];
            $ids[$targetIndex] = $temporary;

            $updateStmt = db()->prepare('UPDATE mindmap_nodes SET sort_order = ? WHERE id = ?');
            foreach ($ids as $position => $id) {
                $updateStmt->execute([($position + 1) * 10, $id]);
            }

            db()->commit();
            flash_set('success', '节点顺序已更新。');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('调整节点排序失败：' . $e->getMessage());
            flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : '排序保存失败，请稍后重试。');
        }

        redirect(app_url('admin/nodes.php'));
    }

    flash_set('error', '未知操作。');
    redirect(app_url('admin/nodes.php'));
}

try {
    $nodes = db()->query(
        'SELECT id, node_name, jump_url, sort_order, created_at, updated_at
         FROM mindmap_nodes
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('读取节点列表失败：' . $e->getMessage());
    $nodes = [];
}

require __DIR__ . '/partials/header.php';
?>

<div class="page-toolbar">
    <div class="toolbar-copy">
        <span class="toolbar-count"><?= e(count($nodes)) ?> 个节点</span>
        <p>排序越靠前的节点，会在前台首页优先显示。可用上移 / 下移调整。</p>
    </div>
    <a class="btn btn-primary" href="<?= e(app_url('admin/node_form.php')) ?>">
        <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
            <path d="M9 4v10M4 9h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        新增节点
    </a>
</div>

<section class="panel node-list-panel">
    <div class="panel-head">
        <div>
            <span class="panel-kicker">BRANCH LIST</span>
            <h2>全部导图节点</h2>
        </div>
        <a class="text-link" href="<?= e(app_url('/')) ?>" target="_blank" rel="noopener noreferrer">查看前台效果</a>
    </div>

    <?php if ($nodes): ?>
        <div class="table-wrap">
            <table class="node-table">
                <thead>
                    <tr>
                        <th scope="col">顺序</th>
                        <th scope="col">节点名称</th>
                        <th scope="col">跳转地址</th>
                        <th scope="col">最后更新</th>
                        <th scope="col" class="actions-col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nodes as $index => $node): ?>
                        <?php
                        $nodeUrl = safe_node_url((string) $node['jump_url']);
                        $isFirst = $index === 0;
                        $isLast = $index === count($nodes) - 1;
                        ?>
                        <tr>
                            <td>
                                <span class="order-badge"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                            </td>
                            <td>
                                <div class="node-name-cell">
                                    <strong><?= e($node['node_name']) ?></strong>
                                    <span>ID #<?= e((int) $node['id']) ?> · 排序值 <?= e((int) $node['sort_order']) ?></span>
                                </div>
                            </td>
                            <td>
                                <a class="url-cell" href="<?= e($nodeUrl) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($node['jump_url']) ?>">
                                    <span><?= e((string) $node['jump_url']) ?></span>
                                    <svg viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                        <path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </td>
                            <td><span class="time-cell"><?= e(format_datetime($node['updated_at'] ?? null)) ?></span></td>
                            <td>
                                <div class="row-actions">
                                    <form method="post" action="" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="id" value="<?= e((int) $node['id']) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="mini-icon-btn" title="上移" aria-label="上移 <?= e($node['node_name']) ?>"<?= $isFirst ? ' disabled' : '' ?>>↑</button>
                                    </form>
                                    <form method="post" action="" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="id" value="<?= e((int) $node['id']) ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="mini-icon-btn" title="下移" aria-label="下移 <?= e($node['node_name']) ?>"<?= $isLast ? ' disabled' : '' ?>>↓</button>
                                    </form>
                                    <a class="mini-btn" href="<?= e(app_url('admin/node_form.php?id=' . (int) $node['id'])) ?>">编辑</a>
                                    <form
                                        method="post"
                                        action=""
                                        class="inline-form"
                                        data-confirm="确定删除节点「<?= e($node['node_name']) ?>」吗？此操作不可撤销。"
                                    >
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e((int) $node['id']) ?>">
                                        <button type="submit" class="mini-btn mini-btn-danger">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-panel empty-panel-large">
            <span class="empty-mark" aria-hidden="true">+</span>
            <h3>还没有导图节点</h3>
            <p>新增节点后，它会以分支卡片的形式显示在前台首页。</p>
            <a class="btn btn-primary btn-small" href="<?= e(app_url('admin/node_form.php')) ?>">创建第一个节点</a>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
