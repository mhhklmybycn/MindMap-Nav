<?php
declare(strict_types=1);

/**
 * 数据备份（3.0 新增）
 * ------------------------------------------------------------------
 * - 导出：把全部分支网站节点打包为 JSON 文件下载（仅节点数据，不含访问记录）
 * - 导入：上传 JSON 备份文件，支持「追加导入」与「覆盖导入」两种模式
 * - 严格校验：任何一条节点不合法，整个文件拒绝导入，不会产生半截数据
 * - 覆盖导入在事务中执行，失败自动回滚，已有数据不会被破坏
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

// 导入限制：文件不超过 2MB，单次最多导入 500 个节点
const IMPORT_MAX_FILE_BYTES = 2097152;
const IMPORT_MAX_NODES = 500;

$pageTitle = '数据备份';
$activePage = 'transfer';
$pageSubtitle = '导出或导入分支网站节点数据（不包含访问记录）';

// ------------------------------------------------------------------
// 导出：GET ?do=export，直接输出 JSON 文件下载
// ------------------------------------------------------------------
if (($_GET['do'] ?? '') === 'export') {
    try {
        $exportRows = db()->query(
            'SELECT node_name, jump_url, sort_order
             FROM mindmap_nodes
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('导出节点数据失败：' . $e->getMessage());
        flash_set('error', '读取节点数据失败，请稍后重试。');
        redirect(app_url('admin/transfer.php'));
    }

    // 组装导出结构：meta 信息 + 节点数组，字段保持最小必要集
    $payload = [
        'app' => 'mindmap-nav',
        'type' => 'nodes',
        'version' => APP_VERSION,
        'exported_at' => date('Y-m-d H:i:s'),
        'count' => count($exportRows),
        'nodes' => [],
    ];
    foreach ($exportRows as $exportRow) {
        $payload['nodes'][] = [
            'node_name' => (string) $exportRow['node_name'],
            'jump_url' => (string) $exportRow['jump_url'],
            'sort_order' => (int) $exportRow['sort_order'],
        ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log('导出节点数据失败：JSON 编码错误');
        flash_set('error', '导出失败，请稍后重试。');
        redirect(app_url('admin/transfer.php'));
    }

    $filename = 'mindmap-nodes-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

/**
 * 解析并严格校验导入文件内容。
 * 返回 ['errors' => [...], 'nodes' => [...]]；errors 非空时 nodes 为空数组。
 */
function validate_import_payload(string $json): array
{
    // JSON 语法必须合法，且整体必须是对象
    try {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['errors' => ['文件不是合法的 JSON，请使用本系统导出的备份文件。'], 'nodes' => []];
    }

    // 顶层必须是对象（关联数组）；列表结构会在下面的 nodes 检查中被拒绝
    if (!is_array($data)) {
        return ['errors' => ['文件结构不正确：顶层应为包含 nodes 字段的对象。'], 'nodes' => []];
    }

    $rawNodes = $data['nodes'] ?? null;
    if (!is_array($rawNodes)) {
        return ['errors' => ['文件结构不正确：缺少 nodes 数组。'], 'nodes' => []];
    }

    if (count($rawNodes) === 0) {
        return ['errors' => ['文件中没有可导入的节点数据。'], 'nodes' => []];
    }

    if (count($rawNodes) > IMPORT_MAX_NODES) {
        return ['errors' => ['节点数量超过单次上限 ' . IMPORT_MAX_NODES . ' 个，请拆分文件后再导入。'], 'nodes' => []];
    }

    // 逐条校验：全部合法才允许导入，任何一条失败即整体拒绝
    $errors = [];
    $validNodes = [];
    foreach ($rawNodes as $index => $item) {
        $line = $index + 1;

        if (!is_array($item)) {
            $errors[] = '第 ' . $line . ' 条数据格式不正确。';
            continue;
        }

        $name = trim((string) ($item['node_name'] ?? ''));
        $url = trim((string) ($item['jump_url'] ?? ''));

        if ($name === '' || text_length($name) > 100) {
            $errors[] = '第 ' . $line . ' 条：节点名称不能为空，且不能超过 100 个字符。';
            continue;
        }
        if (!is_valid_node_url($url)) {
            $errors[] = '第 ' . $line . ' 条「' . $name . '」的跳转地址不合法，只允许 http/https 或 / 开头的站内路径。';
            continue;
        }

        $validNodes[] = ['node_name' => $name, 'jump_url' => $url];
    }

    if ($errors) {
        // 最多展示 5 条明细，避免错误清单刷屏
        if (count($errors) > 5) {
            $errors = array_merge(array_slice($errors, 0, 5), ['……共 ' . count($errors) . ' 条数据存在问题，整个文件未导入。']);
        }
        return ['errors' => $errors, 'nodes' => []];
    }

    return ['errors' => [], 'nodes' => $validNodes];
}

// ------------------------------------------------------------------
// 导入：POST 上传 JSON 文件
// ------------------------------------------------------------------
$errors = [];

if (is_post()) {
    verify_csrf();

    // 导入模式：append=追加到现有节点之后，overwrite=清空后重写
    $mode = (string) ($_POST['mode'] ?? 'append');
    $mode = $mode === 'overwrite' ? 'overwrite' : 'append';

    // 第一步：校验上传本身
    $file = $_FILES['import_file'] ?? null;
    $tmpPath = '';
    if (!is_array($file)) {
        $errors[] = '请选择要导入的 JSON 备份文件。';
    } else {
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            $errors[] = '请选择要导入的 JSON 备份文件。';
        } elseif ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = '文件大小超过服务器允许的上传限制。';
        } elseif ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = '文件上传失败，请重试。';
        } else {
            $tmpPath = (string) $file['tmp_name'];
            if (!is_uploaded_file($tmpPath)) {
                $errors[] = '非法的上传来源。';
            }
            if ((int) $file['size'] > IMPORT_MAX_FILE_BYTES) {
                $errors[] = '文件大小超过 2MB 限制。';
            }
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($extension !== 'json') {
                $errors[] = '仅支持 .json 格式的备份文件。';
            }
        }
    }

    // 第二步：解析并校验内容
    $importNodes = [];
    if (!$errors) {
        $json = file_get_contents($tmpPath);
        if ($json === false || trim($json) === '') {
            $errors[] = '文件内容为空或读取失败。';
        } else {
            $result = validate_import_payload($json);
            if ($result['errors']) {
                $errors = array_merge($errors, $result['errors']);
            } else {
                $importNodes = $result['nodes'];
            }
        }
    }

    // 第三步：事务写入；排序号按 10、20、30… 重新分配，保持文件中的先后顺序
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $sort = 10;
            if ($mode === 'overwrite') {
                $pdo->exec('DELETE FROM mindmap_nodes');
            } else {
                // 追加模式：锁定当前排序最大的节点，从其后继续编号
                $lastStmt = $pdo->query(
                    'SELECT sort_order FROM mindmap_nodes ORDER BY sort_order DESC, id DESC LIMIT 1 FOR UPDATE'
                );
                $lastSort = $lastStmt->fetchColumn();
                $sort = $lastSort === false ? 10 : ((int) $lastSort + 10);
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO mindmap_nodes (node_name, jump_url, sort_order) VALUES (?, ?, ?)'
            );
            foreach ($importNodes as $importNode) {
                $insertStmt->execute([$importNode['node_name'], $importNode['jump_url'], $sort]);
                $sort += 10;
            }

            $pdo->commit();

            flash_set(
                'success',
                sprintf(
                    '导入完成：已%s %d 个节点。',
                    $mode === 'overwrite' ? '覆盖写入' : '追加',
                    count($importNodes)
                )
            );
            redirect(app_url('admin/transfer.php'));
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('导入节点数据失败：' . $e->getMessage());
            $errors[] = '导入失败，请稍后重试。已有数据未被修改。';
        }
    }
}

// 当前节点数，用于导出面板提示
$currentNodeCount = 0;
try {
    $currentNodeCount = (int) db()->query('SELECT COUNT(*) FROM mindmap_nodes')->fetchColumn();
} catch (Throwable $e) {
    error_log('读取节点数量失败：' . $e->getMessage());
}

require __DIR__ . '/partials/header.php';
?>

<?php if ($errors): ?>
    <div class="form-alert" role="alert">
        <strong>导入未完成</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-page-layout">
    <div class="form-page-main">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <span class="panel-kicker">EXPORT</span>
                    <h2>导出节点数据</h2>
                </div>
                <span class="soft-badge">3.0</span>
            </div>

            <div class="transfer-export">
                <p class="transfer-desc">
                    将当前 <strong><?= e($currentNodeCount) ?></strong> 个分支网站节点打包为 JSON 文件下载，
                    可用于备份或迁移到其他站点。导出的文件<strong>只包含节点名称、跳转地址与排序</strong>，
                    不包含任何访问记录。
                </p>
                <div class="form-actions">
                    <a class="btn btn-primary" href="<?= e(app_url('admin/transfer.php?do=export')) ?>">
                        <span>导出全部节点（JSON）</span>
                        <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
                            <path d="M9 3v9M5.5 8.5 9 12l3.5-3.5M4 15h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <span class="panel-kicker">IMPORT</span>
                    <h2>导入节点数据</h2>
                </div>
                <span class="soft-badge">3.0</span>
            </div>

            <form method="post" action="" enctype="multipart/form-data" class="admin-form" data-import-form>
                <?= csrf_field() ?>

                <div class="form-row">
                    <div class="form-field">
                        <label>备份文件</label>
                        <label class="file-drop" for="import_file">
                            <span class="file-drop-icon" aria-hidden="true">↑</span>
                            <strong data-file-name-output>选择 JSON 备份文件</strong>
                            <span>由本系统导出，最大 2MB，单次最多 500 个节点</span>
                            <input
                                type="file"
                                id="import_file"
                                name="import_file"
                                accept=".json,application/json"
                                data-file-label
                            >
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>导入模式</label>
                        <div class="mode-cards">
                            <label class="mode-card">
                                <input type="radio" name="mode" value="append" checked>
                                <span class="mode-card-body">
                                    <strong>追加导入</strong>
                                    <small>保留现有节点，把文件中的节点按顺序追加到列表末尾</small>
                                </span>
                            </label>
                            <label class="mode-card mode-card-danger">
                                <input type="radio" name="mode" value="overwrite">
                                <span class="mode-card-body">
                                    <strong>覆盖导入</strong>
                                    <small>先删除现有全部节点，再写入文件中的节点（不可恢复）</small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">开始导入</button>
                    <a class="btn btn-secondary" href="<?= e(app_url('admin/nodes.php')) ?>">返回节点列表</a>
                </div>
            </form>
        </section>
    </div>

    <aside class="form-page-side">
        <section class="tips-card">
            <span class="panel-kicker">FILE FORMAT</span>
            <h3>备份文件格式</h3>
            <p>导出文件为 UTF-8 编码的 JSON，结构如下：</p>
            <pre class="code-block">{
  "app": "mindmap-nav",
  "type": "nodes",
  "version": "<?= e(APP_VERSION) ?>",
  "exported_at": "导出时间",
  "count": 2,
  "nodes": [
    {
      "node_name": "产品中心",
      "jump_url": "https://example.com",
      "sort_order": 10
    }
  ]
}</pre>
            <p class="field-tip">导入时只读取 <code>nodes</code> 数组中的节点名称与跳转地址，其他字段会被忽略。</p>
        </section>

        <section class="tips-card">
            <span class="panel-kicker">NOTES</span>
            <h3>注意事项</h3>
            <ul>
                <li>导出与导入<strong>只包含分支网站节点</strong>，访问记录不会被导出或导入。</li>
                <li>任何一条节点数据不合法，整个文件都会被拒绝，不会产生半截数据。</li>
                <li>覆盖导入会先清空现有节点，提交前有二次确认，请谨慎操作。</li>
                <li>导入的节点会按文件中的顺序重新编号（10、20、30…）。</li>
            </ul>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
