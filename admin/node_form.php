<?php
declare(strict_types=1);

/**
 * 新增 / 编辑节点
 * ------------------------------------------------------------------
 * - 节点名称与跳转地址均进行长度与协议校验
 * - URL 仅允许 http/https 或站内 / 开头路径，拒绝 javascript: 等危险协议
 * - 新增节点自动追加到排序末尾
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$nodeId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$isEdit = $nodeId > 0;
$errors = [];

$node = [
    'node_name' => '',
    'jump_url' => '',
];

if ($isEdit) {
    try {
        $stmt = db()->prepare('SELECT id, node_name, jump_url, sort_order FROM mindmap_nodes WHERE id = ? LIMIT 1');
        $stmt->execute([$nodeId]);
        $existingNode = $stmt->fetch();

        if (!$existingNode) {
            flash_set('error', '要编辑的节点不存在。');
            redirect(app_url('admin/nodes.php'));
        }

        $node = $existingNode;
    } catch (Throwable $e) {
        error_log('读取节点详情失败：' . $e->getMessage());
        flash_set('error', '读取节点失败，请稍后重试。');
        redirect(app_url('admin/nodes.php'));
    }
}

$pageTitle = $isEdit ? '编辑节点' : '新增节点';
$activePage = 'nodes';
$pageSubtitle = $isEdit ? '修改分支名称、跳转地址与展示内容' : '创建一个新的思维导图分支入口';

if (is_post()) {
    verify_csrf();

    $node['node_name'] = trim((string) ($_POST['node_name'] ?? ''));
    $node['jump_url'] = trim((string) ($_POST['jump_url'] ?? ''));

    if ($node['node_name'] === '' || text_length($node['node_name']) > 100) {
        $errors[] = '节点名称不能为空，且不能超过 100 个字符。';
    }
    if ($node['jump_url'] === '') {
        $errors[] = '请填写跳转地址。';
    } elseif (!is_valid_node_url($node['jump_url'])) {
        $errors[] = '跳转地址只允许 http://、https:// 开头的完整网址，或以 / 开头的站内路径。';
    }

    if (!$errors) {
        try {
            if ($isEdit) {
                $updateStmt = db()->prepare(
                    'UPDATE mindmap_nodes SET node_name = ?, jump_url = ?, updated_at = NOW() WHERE id = ?'
                );
                $updateStmt->execute([$node['node_name'], $node['jump_url'], $nodeId]);
                flash_set('success', '节点已更新。');
            } else {
                db()->beginTransaction();

                // 锁定当前排序最大的节点，计算新节点排序值
                $lastStmt = db()->query(
                    'SELECT sort_order FROM mindmap_nodes ORDER BY sort_order DESC, id DESC LIMIT 1 FOR UPDATE'
                );
                $lastSort = $lastStmt->fetchColumn();
                $nextSort = $lastSort === false ? 10 : ((int) $lastSort + 10);

                $insertStmt = db()->prepare(
                    'INSERT INTO mindmap_nodes (node_name, jump_url, sort_order) VALUES (?, ?, ?)'
                );
                $insertStmt->execute([$node['node_name'], $node['jump_url'], $nextSort]);

                db()->commit();
                flash_set('success', '节点已新增，并自动排在列表末尾。');
            }

            redirect(app_url('admin/nodes.php'));
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('保存节点失败：' . $e->getMessage());
            $errors[] = '节点保存失败，请稍后重试。';
        }
    }
}

$previewUrl = is_valid_node_url((string) $node['jump_url']) ? (string) $node['jump_url'] : '#';
$previewLabel = node_url_label((string) $node['jump_url']);

require __DIR__ . '/partials/header.php';
?>

<?php if ($errors): ?>
    <div class="form-alert" role="alert">
        <strong>节点未保存</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-page-layout">
    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="panel-kicker"><?= $isEdit ? 'EDIT BRANCH' : 'NEW BRANCH' ?></span>
                <h2><?= $isEdit ? '修改节点信息' : '创建新的入口' ?></h2>
            </div>
            <?php if ($isEdit): ?>
                <span class="soft-badge">ID #<?= e($nodeId) ?></span>
            <?php endif; ?>
        </div>

        <form method="post" action="" class="admin-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($nodeId) ?>">

            <div class="form-row">
                <div class="form-field">
                    <label for="node_name">节点名称</label>
                    <input
                        type="text"
                        id="node_name"
                        name="node_name"
                        value="<?= e($node['node_name']) ?>"
                        placeholder="例如：产品中心"
                        maxlength="100"
                        required
                        data-preview-name
                    >
                    <small>建议使用简短清晰的名词，最多 100 个字符。</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="jump_url">跳转地址</label>
                    <input
                        type="text"
                        id="jump_url"
                        name="jump_url"
                        value="<?= e($node['jump_url']) ?>"
                        placeholder="https://example.com 或 /about.php"
                        maxlength="500"
                        required
                        data-preview-url
                    >
                    <small>支持完整 http/https 地址，也支持 / 开头的站内相对地址。</small>
                    <?php if (is_valid_node_url((string) $node['jump_url'])): ?>
                        <a class="inline-preview-link" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener noreferrer">
                            测试打开该地址
                            <svg viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                <path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? '保存节点修改' : '创建节点' ?></button>
                <a class="btn btn-secondary" href="<?= e(app_url('admin/nodes.php')) ?>">返回节点列表</a>
            </div>
        </form>
    </section>

    <aside class="form-page-side">
        <section class="preview-card-wrap">
            <span class="panel-kicker">CARD PREVIEW</span>
            <h3>前台分支预览</h3>
            <div class="branch-preview">
                <span class="preview-rail"><span>01</span><i></i></span>
                <span class="preview-copy">
                    <strong data-preview-name-output><?= e($node['node_name'] !== '' ? $node['node_name'] : '节点名称') ?></strong>
                    <small data-preview-url-output><?= e($previewLabel) ?></small>
                </span>
                <span class="preview-arrow" aria-hidden="true">→</span>
            </div>
            <p>节点会按照管理列表中的排序，交替显示在根节点的左右两侧。</p>
        </section>

        <section class="tips-card">
            <span class="panel-kicker">URL RULES</span>
            <h3>地址填写规则</h3>
            <ul>
                <li><code>https://example.com</code> — 外部完整链接</li>
                <li><code>http://...</code> — 也支持 HTTP</li>
                <li><code>/about.php</code> — 站内绝对路径</li>
                <li><code>javascript:</code> 等危险协议会被拒绝</li>
            </ul>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
