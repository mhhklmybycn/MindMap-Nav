<?php
declare(strict_types=1);

/**
 * 节点跳转统计入口
 * ------------------------------------------------------------------
 * 前台节点卡片不再直接链接目标地址，而是先访问本文件：
 * 1. 校验节点 ID 与跳转地址的合法性
 * 2. 写入一条节点访问记录（时间 / IP / 设备 / UA / 来源）
 * 3. 302 跳转到目标地址
 *
 * 即使访客浏览器禁用了 JavaScript，点击统计也不会丢失。
 */

require_once __DIR__ . '/includes/bootstrap.php';

$nodeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ID 非法时直接回首页，不输出任何可操作内容
if ($nodeId <= 0) {
    redirect(app_url('/'));
}

$node = null;

try {
    $stmt = db()->prepare('SELECT id, node_name, jump_url FROM mindmap_nodes WHERE id = ? LIMIT 1');
    $stmt->execute([$nodeId]);
    $row = $stmt->fetch();
    $node = is_array($row) ? $row : null;
} catch (Throwable $e) {
    error_log('读取节点跳转信息失败：' . $e->getMessage());
}

// 节点不存在或地址非法（含 javascript: 等危险协议）时回首页
if (!$node || !is_valid_node_url((string) $node['jump_url'])) {
    redirect(app_url('/'));
}

$jumpUrl = (string) $node['jump_url'];

// 记录本次点击；记录失败不影响正常跳转
log_access('node', (int) $node['id'], (string) $node['node_name'], $jumpUrl);

header('Location: ' . $jumpUrl, true, 302);
exit;
