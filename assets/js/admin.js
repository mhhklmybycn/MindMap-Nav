/**
 * 后台交互脚本
 * ------------------------------------------------------------------
 * 负责移动端侧栏、删除确认、密码显隐、背景图预览和节点实时预览。
 * 不依赖任何第三方库。
 */
(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var menuToggle = document.getElementById('menuToggle');

    /**
     * 打开/关闭移动端侧栏。
     */
    function setSidebarOpen(open) {
        if (!sidebar || !overlay || !menuToggle) {
            return;
        }

        body.classList.toggle('sidebar-open', open);
        sidebar.classList.toggle('is-open', open);
        overlay.hidden = !open;
        menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        menuToggle.setAttribute('aria-label', open ? '关闭导航菜单' : '打开导航菜单');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function () {
            setSidebarOpen(!body.classList.contains('sidebar-open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            setSidebarOpen(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setSidebarOpen(false);
        }
    });

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 900px)').matches) {
                    setSidebarOpen(false);
                }
            });
        });
    }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) {
            setSidebarOpen(false);
        }
    });

    // 删除等危险操作的确认提示
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || '确定执行此操作吗？';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    // 密码显示 / 隐藏
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var container = button.closest('.input-wrap, .input-with-action');
            var input = container ? container.querySelector('input') : null;

            if (!input) {
                return;
            }

            var willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            button.setAttribute('aria-label', willShow ? '隐藏密码' : '显示密码');

            if (button.textContent.trim() !== '') {
                button.textContent = willShow ? '隐藏' : '显示';
            }
        });
    });

    // 上传图片即时预览
    document.querySelectorAll('input[data-image-preview]').forEach(function (input) {
        input.addEventListener('change', function () {
            var targetSelector = input.getAttribute('data-image-preview');
            var target = targetSelector ? document.querySelector(targetSelector) : null;
            var file = input.files && input.files[0] ? input.files[0] : null;

            if (!target || !file) {
                return;
            }

            if (!file.type || file.type.indexOf('image/') !== 0) {
                return;
            }

            var reader = new FileReader();
            reader.addEventListener('load', function () {
                target.style.backgroundImage = 'url("' + reader.result + '")';
                target.classList.remove('is-empty');
                target.innerHTML = '';
            });
            reader.readAsDataURL(file);
        });
    });

    // 节点表单实时预览
    var previewNameInput = document.querySelector('[data-preview-name]');
    var previewUrlInput = document.querySelector('[data-preview-url]');
    var previewNameOutput = document.querySelector('[data-preview-name-output]');
    var previewUrlOutput = document.querySelector('[data-preview-url-output]');

    function getUrlLabel(rawValue) {
        var value = (rawValue || '').trim();

        if (value === '') {
            return '跳转地址';
        }

        if (/^https?:\/\//i.test(value)) {
            try {
                var parsed = new URL(value);
                return parsed.hostname.replace(/^www\./i, '') || '外部链接';
            } catch (error) {
                return '链接待修复';
            }
        }

        if (value.charAt(0) === '/' && value.charAt(1) !== '/') {
            return '站内链接';
        }

        return '链接待修复';
    }

    if (previewNameInput && previewNameOutput) {
        previewNameInput.addEventListener('input', function () {
            var value = previewNameInput.value.trim();
            previewNameOutput.textContent = value === '' ? '节点名称' : value;
        });
    }

    if (previewUrlInput && previewUrlOutput) {
        previewUrlInput.addEventListener('input', function () {
            previewUrlOutput.textContent = getUrlLabel(previewUrlInput.value);
        });
    }

    // 提示消息：支持手动关闭，成功/信息类 7 秒后自动收起
    document.querySelectorAll('[data-flash]').forEach(function (message) {
        var closeButton = message.querySelector('[data-dismiss-flash]');

        function dismiss() {
            message.classList.add('is-leaving');
            window.setTimeout(function () {
                if (message.parentNode) {
                    message.parentNode.removeChild(message);
                }
            }, 220);
        }

        if (closeButton) {
            closeButton.addEventListener('click', dismiss);
        }

        if (!message.classList.contains('flash-error')) {
            window.setTimeout(dismiss, 7000);
        }
    });

    // 自定义文本域字符数提示（可选增强）
    var codeEditor = document.querySelector('.code-editor');
    if (codeEditor) {
        var counter = document.createElement('small');
        counter.className = 'code-counter';
        counter.style.cssText = 'display:block;text-align:right;margin-top:6px;color:#8a9bab;';
        codeEditor.insertAdjacentElement('afterend', counter);

        var updateCounter = function () {
            counter.textContent = codeEditor.value.length + ' / 200000 字符';
        };
        codeEditor.addEventListener('input', updateCounter);
        updateCounter();
    }

    // 前台样式模板：单选切换时同步高亮选中卡片
    document.querySelectorAll('.theme-card input[name="front_theme"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.theme-card').forEach(function (card) {
                card.classList.remove('is-selected');
            });
            var current = radio.closest('.theme-card');
            if (current) {
                current.classList.add('is-selected');
            }
        });
    });

    // ------------------------------------------------------------------
    // 数据备份页（3.0）：选择文件后把文件名显示在放置区
    // ------------------------------------------------------------------
    document.querySelectorAll('input[type="file"][data-file-label]').forEach(function (input) {
        input.addEventListener('change', function () {
            var wrap = input.closest('.file-drop');
            var output = wrap ? wrap.querySelector('[data-file-name-output]') : null;
            if (!output) {
                return;
            }
            if (!output.dataset.initialText) {
                output.dataset.initialText = output.textContent;
            }
            var file = input.files && input.files[0] ? input.files[0] : null;
            output.textContent = file ? file.name : output.dataset.initialText;
        });
    });

    // 数据备份页（3.0）：导入前确认文件已选择，覆盖模式弹窗二次确认
    var importForm = document.querySelector('form[data-import-form]');
    if (importForm) {
        importForm.addEventListener('submit', function (event) {
            var fileInput = importForm.querySelector('input[type="file"]');
            if (fileInput && (!fileInput.files || fileInput.files.length === 0)) {
                event.preventDefault();
                window.alert('请先选择要导入的 JSON 备份文件。');
                return;
            }
            var mode = importForm.querySelector('input[name="mode"]:checked');
            if (mode && mode.value === 'overwrite') {
                if (!window.confirm('覆盖导入将删除当前全部节点，且不可恢复！\n\n确定要继续吗？')) {
                    event.preventDefault();
                }
            }
        });
    }
})();
