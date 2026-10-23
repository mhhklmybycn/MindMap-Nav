<?php
declare(strict_types=1);

/**
 * 管理员设置
 * ------------------------------------------------------------------
 * 修改用户名与密码前必须验证当前密码。
 * 新密码使用 password_hash() 加密，永不保存明文。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = '管理员设置';
$activePage = 'account';
$pageSubtitle = '维护后台登录账号与安全凭据';

$currentAdmin = admin_user();
$adminId = (int) ($currentAdmin['id'] ?? 0);
$formUsername = (string) ($currentAdmin['username'] ?? '');
$errors = [];

if (is_post()) {
    verify_csrf();

    $formUsername = trim((string) ($_POST['username'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[\p{L}\p{N}_-]{3,30}$/u', $formUsername)) {
        $errors[] = '用户名需为 3 - 30 位中文、字母、数字、下划线或短横线。';
    }
    if ($currentPassword === '') {
        $errors[] = '请输入当前登录密码以确认身份。';
    }
    if ($newPassword !== '' && (text_length($newPassword) < 8 || text_length($newPassword) > 72)) {
        $errors[] = '新密码长度需为 8 - 72 个字符。';
    }
    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $errors[] = '两次输入的新密码不一致。';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE id = ? LIMIT 1');
            $stmt->execute([$adminId]);
            $account = $stmt->fetch();

            if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
                $errors[] = '当前密码不正确，未做任何修改。';
            } else {
                $duplicateStmt = db()->prepare('SELECT id FROM admins WHERE username = ? AND id <> ? LIMIT 1');
                $duplicateStmt->execute([$formUsername, $adminId]);
                if ($duplicateStmt->fetch()) {
                    $errors[] = '该用户名已被使用，请更换一个。';
                }
            }

            if (!$errors) {
                db()->beginTransaction();

                if ($newPassword !== '') {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    if ($newHash === false) {
                        throw new RuntimeException('新密码加密失败。');
                    }

                    $updateStmt = db()->prepare(
                        'UPDATE admins SET username = ?, password_hash = ?, updated_at = NOW() WHERE id = ?'
                    );
                    $updateStmt->execute([$formUsername, $newHash, $adminId]);
                } else {
                    $updateStmt = db()->prepare(
                        'UPDATE admins SET username = ?, updated_at = NOW() WHERE id = ?'
                    );
                    $updateStmt->execute([$formUsername, $adminId]);
                }

                db()->commit();

                $_SESSION['admin_username'] = $formUsername;
                session_regenerate_id(true);

                flash_set('success', '管理员账号信息已更新。');
                redirect(app_url('admin/account.php'));
            }
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('修改管理员账号失败：' . $e->getMessage());
            $errors[] = '账号更新失败，请稍后重试。';
        }
    }
}

require __DIR__ . '/partials/header.php';
?>

<?php if ($errors): ?>
    <div class="form-alert" role="alert">
        <strong>无法保存修改</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="settings-layout">
    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="panel-kicker">ADMIN ACCOUNT</span>
                <h2>登录账号</h2>
            </div>
            <span class="soft-badge">SECURED</span>
        </div>

        <form method="post" action="" class="admin-form" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-row">
                <div class="form-field">
                    <label for="username">管理员用户名</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?= e($formUsername) ?>"
                        maxlength="30"
                        autocomplete="username"
                        required
                    >
                    <small>支持中文、字母、数字、下划线和短横线，3 - 30 位。</small>
                </div>
            </div>

            <div class="form-divider">
                <span>身份确认</span>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="current_password">当前登录密码</label>
                    <div class="input-with-action">
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            placeholder="修改任何信息前需验证当前密码"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="input-action" data-password-toggle aria-label="显示或隐藏密码">显示</button>
                    </div>
                    <small>用于确认操作者身份，防止会话被他人利用。</small>
                </div>
            </div>

            <div class="form-divider">
                <span>修改密码（可选）</span>
            </div>

            <div class="form-row form-row-two">
                <div class="form-field">
                    <label for="new_password">新密码</label>
                    <div class="input-with-action">
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            placeholder="留空则不修改"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="72"
                        >
                        <button type="button" class="input-action" data-password-toggle aria-label="显示或隐藏密码">显示</button>
                    </div>
                    <small>长度 8 - 72 个字符，建议包含字母、数字和符号。</small>
                </div>
                <div class="form-field">
                    <label for="confirm_password">确认新密码</label>
                    <div class="input-with-action">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="再次输入新密码"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="72"
                        >
                        <button type="button" class="input-action" data-password-toggle aria-label="显示或隐藏密码">显示</button>
                    </div>
                    <small>仅在新密码不为空时填写。</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存账号设置</button>
                <span class="form-hint">保存后当前登录状态保持不变。</span>
            </div>
        </form>
    </section>

    <aside class="settings-side">
        <section class="profile-card">
            <span class="profile-avatar"><?= e(function_exists('mb_substr') ? mb_substr((string) ($currentAdmin['username'] ?? 'A'), 0, 1, 'UTF-8') : substr((string) ($currentAdmin['username'] ?? 'A'), 0, 1)) ?></span>
            <h3><?= e($currentAdmin['username'] ?? '管理员') ?></h3>
            <span class="profile-role">SUPER ADMIN</span>
            <dl class="profile-meta">
                <div>
                    <dt>账号创建</dt>
                    <dd><?= e(format_datetime($currentAdmin['created_at'] ?? null)) ?></dd>
                </div>
                <div>
                    <dt>最后更新</dt>
                    <dd><?= e(format_datetime($currentAdmin['updated_at'] ?? null)) ?></dd>
                </div>
            </dl>
        </section>

        <section class="security-card">
            <span class="panel-kicker">SECURITY NOTES</span>
            <h3>安全建议</h3>
            <ul>
                <li>使用独立且足够长的密码，不要与其他网站共用。</li>
                <li>定期更换密码，尤其在多人接触过账号之后。</li>
                <li>后台建议启用 HTTPS，避免登录凭据在传输中泄露。</li>
                <li>系统只保存密码哈希，无法找回原密码。</li>
            </ul>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
