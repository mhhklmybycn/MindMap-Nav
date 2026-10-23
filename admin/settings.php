<?php
declare(strict_types=1);

/**
 * 网站基础设置
 * ------------------------------------------------------------------
 * - 修改网站名称
 * - 上传 / 移除首页背景图（仅允许 jpg、png、gif、webp）
 * - 编辑并保存自定义 HTML（由管理员主动填写，前台原样渲染）
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = '网站设置';
$activePage = 'settings';
$pageSubtitle = '设置站点名称、首页背景与自定义内容';

$settings = get_site_settings();
$formSiteName = (string) $settings['site_name'];
$formCustomHtml = (string) $settings['custom_html'];
$formFrontTheme = normalize_front_theme((string) ($settings['front_theme'] ?? 'dark'));
$currentBackground = normalize_background_path((string) $settings['background_image']);
$errors = [];
$uploadedAbsolutePath = '';

// 前台样式模板清单：key 与 assets/css/themes/ 下的文件名对应，dark 为内置默认
$frontThemes = [
    'dark' => [
        'name' => '夜幕深蓝（默认）',
        'desc' => '深色底配青蓝光晕，默认的科技感导航风格',
        'swatch' => 'background: linear-gradient(135deg, #0b2334, #06111f 55%, #050d18);',
    ],
    'light' => [
        'name' => '清晨浅灰',
        'desc' => '浅色底配深色文字，明亮简洁，适合正式场合',
        'swatch' => 'background: linear-gradient(135deg, #ffffff, #f4f6f8 55%, #e2e8ee);',
    ],
    'violet' => [
        'name' => '紫夜星云',
        'desc' => '深紫底配紫粉光晕，氛围感更强的暗色风格',
        'swatch' => 'background: linear-gradient(135deg, #2a1445, #150a24 55%, #0d0616);',
    ],
];

/**
 * 将 PHP 上传错误码转换为可读提示。
 */
function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片超过服务器允许的上传大小。',
        UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重新尝试。',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时上传目录，请联系主机管理员。',
        UPLOAD_ERR_CANT_WRITE => '图片写入磁盘失败，请检查目录权限。',
        UPLOAD_ERR_EXTENSION => '上传被服务器扩展阻止。',
        default => '图片上传失败，请重新选择文件。',
    };
}

if (is_post()) {
    verify_csrf();

    $formSiteName = trim((string) ($_POST['site_name'] ?? ''));
    $formCustomHtml = (string) ($_POST['custom_html'] ?? '');
    // 前台样式模板：白名单校验，非法值回退默认模板
    $formFrontTheme = normalize_front_theme((string) ($_POST['front_theme'] ?? 'dark'));
    $removeBackground = isset($_POST['remove_background']);
    $newBackground = $currentBackground;

    if ($formSiteName === '' || text_length($formSiteName) > 100) {
        $errors[] = '网站名称不能为空，且不能超过 100 个字符。';
    }
    if (strlen($formCustomHtml) > 200000) {
        $errors[] = '自定义 HTML 内容不能超过 200 KB。';
    }

    $file = $_FILES['background'] ?? null;
    $hasNewUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasNewUpload && !$errors) {
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = upload_error_message($uploadError);
        } elseif (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $errors[] = '上传文件来源校验失败。';
        } elseif ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = '背景图大小必须在 5 MB 以内。';
        } else {
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file((string) $file['tmp_name']);
            $imageInfo = @getimagesize((string) $file['tmp_name']);

            if (!isset($allowedMimes[$mime]) || $imageInfo === false) {
                $errors[] = '只允许上传 JPG、PNG、GIF 或 WEBP 格式的真实图片。';
            } else {
                $uploadDir = APP_ROOT . '/uploads/backgrounds';
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
                    $errors[] = '无法创建背景图目录，请检查 uploads 目录权限。';
                } elseif (!is_writable($uploadDir)) {
                    $errors[] = '背景图目录不可写，请设置 uploads/backgrounds 目录权限。';
                } else {
                    $fileName = 'bg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6))
                        . '.' . $allowedMimes[$mime];
                    $target = $uploadDir . '/' . $fileName;

                    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                        $errors[] = '背景图保存失败，请检查目录权限。';
                    } else {
                        @chmod($target, 0644);
                        $newBackground = 'uploads/backgrounds/' . $fileName;
                        $uploadedAbsolutePath = $target;
                    }
                }
            }
        }
    } elseif ($removeBackground) {
        $newBackground = '';
    }

    if (!$errors) {
        try {
            $updateStmt = db()->prepare(
                'UPDATE site_settings
                 SET site_name = ?, background_image = ?, custom_html = ?,
                     front_theme = ?, updated_at = NOW()
                 WHERE id = 1'
            );
            $updateStmt->execute([$formSiteName, $newBackground, $formCustomHtml, $formFrontTheme]);

            // 单行配置被意外删除时自动补回
            if ($updateStmt->rowCount() === 0) {
                $existsStmt = db()->query('SELECT COUNT(*) FROM site_settings WHERE id = 1');
                if ((int) $existsStmt->fetchColumn() === 0) {
                    $insertStmt = db()->prepare(
                        'INSERT INTO site_settings (id, site_name, background_image, custom_html, front_theme)
                         VALUES (1, ?, ?, ?, ?)'
                    );
                    $insertStmt->execute([$formSiteName, $newBackground, $formCustomHtml, $formFrontTheme]);
                }
            }

            // 新背景保存成功后，清理旧文件
            if ($currentBackground !== '' && $currentBackground !== $newBackground) {
                delete_background_file($currentBackground);
            }

            flash_set('success', '网站设置已保存。');
            redirect(app_url('admin/settings.php'));
        } catch (Throwable $e) {
            if ($uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath)) {
                @unlink($uploadedAbsolutePath);
            }
            error_log('保存网站设置失败：' . $e->getMessage());
            $errors[] = '网站设置保存失败，请稍后重试。';
        }
    }
}

$backgroundForPreview = $currentBackground !== '' && is_file(APP_ROOT . '/' . $currentBackground)
    ? app_url($currentBackground)
    : '';

require __DIR__ . '/partials/header.php';
?>

<?php if ($errors): ?>
    <div class="form-alert" role="alert">
        <strong>设置未保存</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="" enctype="multipart/form-data" class="admin-form settings-form" autocomplete="off">
    <?= csrf_field() ?>

    <div class="settings-layout">
        <div class="settings-main">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="panel-kicker">BASIC IDENTITY</span>
                        <h2>站点信息</h2>
                    </div>
                    <span class="soft-badge">GENERAL</span>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="site_name">网站名称</label>
                        <input
                            type="text"
                            id="site_name"
                            name="site_name"
                            value="<?= e($formSiteName) ?>"
                            maxlength="100"
                            required
                        >
                        <small>用于浏览器标题、前台中央根节点和后台品牌区。</small>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="panel-kicker">HOMEPAGE BACKDROP</span>
                        <h2>前端背景图</h2>
                    </div>
                </div>

                <div class="upload-layout">
                    <div
                        class="background-preview<?= $backgroundForPreview === '' ? ' is-empty' : '' ?>"
                        id="backgroundPreview"
                        <?= $backgroundForPreview !== '' ? 'style="background-image:url(\'' . e($backgroundForPreview) . '\')"' : '' ?>
                    >
                        <?php if ($backgroundForPreview === ''): ?>
                            <span>默认渐变背景</span>
                            <small>选择图片后在此预览</small>
                        <?php endif; ?>
                    </div>

                    <div class="upload-copy">
                        <label class="file-drop" for="background">
                            <span class="file-drop-icon" aria-hidden="true">+</span>
                            <strong>选择背景图片</strong>
                            <span>JPG / PNG / GIF / WEBP，最大 5 MB</span>
                            <input
                                type="file"
                                id="background"
                                name="background"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                data-image-preview="#backgroundPreview"
                            >
                        </label>
                        <p class="upload-current">
                            <?= $currentBackground !== '' ? '当前背景：' . e(basename($currentBackground)) : '当前未设置自定义背景图。' ?>
                        </p>
                        <?php if ($currentBackground !== ''): ?>
                            <label class="checkbox-line">
                                <input type="checkbox" name="remove_background" value="1">
                                <span>移除当前背景图，恢复默认背景</span>
                            </label>
                        <?php endif; ?>
                        <p class="field-tip">系统会校验文件真实 MIME 类型与图像结构，伪装成图片的文件无法上传。</p>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="panel-kicker">FRONT THEME</span>
                        <h2>前台样式模板</h2>
                    </div>
                    <span class="soft-badge">3.0</span>
                </div>

                <div class="theme-cards">
                    <?php foreach ($frontThemes as $themeKey => $themeMeta): ?>
                        <label class="theme-card<?= $formFrontTheme === $themeKey ? ' is-selected' : '' ?>">
                            <input
                                type="radio"
                                name="front_theme"
                                value="<?= e($themeKey) ?>"
                                <?= $formFrontTheme === $themeKey ? 'checked' : '' ?>
                            >
                            <span class="theme-swatch" style="<?= e($themeMeta['swatch']) ?>" aria-hidden="true"></span>
                            <span class="theme-card-body">
                                <strong><?= e($themeMeta['name']) ?></strong>
                                <small><?= e($themeMeta['desc']) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-tip">模板保存后即时生效；已上传背景图时，背景图会覆盖模板底色，模板仍控制卡片与文字配色。</p>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="panel-kicker">CUSTOM HTML</span>
                        <h2>自定义 HTML 代码</h2>
                    </div>
                    <span class="soft-badge soft-badge-warn">RAW OUTPUT</span>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="custom_html">展示在前台首页底部的自定义内容</label>
                        <textarea
                            id="custom_html"
                            name="custom_html"
                            rows="12"
                            class="code-editor"
                            placeholder="可以填写公告、说明、备案信息或自定义组件...&#10;默认留空则不输出任何内容。"
                        ><?= e($formCustomHtml) ?></textarea>
                        <small>该内容会按原始 HTML 渲染，不会再次转义。只允许可信管理员编辑，请勿粘贴不明来源脚本。</small>
                    </div>
                </div>
            </section>
        </div>

        <aside class="settings-side">
            <section class="tips-card">
                <span class="panel-kicker">LIVE PREVIEW</span>
                <h3>设置立即生效</h3>
                <p>保存后刷新前台首页即可看到新的站点名称、背景图和自定义内容。</p>
                <a class="btn btn-secondary btn-block" href="<?= e(app_url('/')) ?>" target="_blank" rel="noopener noreferrer">
                    打开前台首页
                </a>
            </section>

            <section class="tips-card tips-card-warn">
                <span class="panel-kicker">SAFETY</span>
                <h3>上传安全限制</h3>
                <ul>
                    <li>仅允许图片 MIME 类型。</li>
                    <li>文件会随机重命名，避免覆盖。</li>
                    <li>上传目录禁止执行 PHP 等脚本。</li>
                    <li>SVG 不在允许列表中，防止脚本注入。</li>
                </ul>
            </section>
        </aside>
    </div>

    <div class="sticky-actions">
        <span>修改将在保存后立即应用到前台。</span>
        <button type="submit" class="btn btn-primary">保存全部设置</button>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
