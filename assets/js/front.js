/**
 * 前台思维导图分支连线
 * ------------------------------------------------------------------
 * 根据中央根节点与两侧卡片的实时位置绘制 SVG 贝塞尔曲线。
 * 支持窗口缩放、字体加载完成、卡片入场动画结束后的重新定位。
 */
(function () {
    'use strict';

    var stage = document.getElementById('mindmapStage');
    var svg = document.getElementById('branchLines');
    var root = document.getElementById('mindmapRoot');

    if (!stage || !svg || !root) {
        return;
    }

    var cards = Array.prototype.slice.call(stage.querySelectorAll('.branch-card'));
    var mobileQuery = window.matchMedia('(max-width: 980px)');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var hasAnimatedLines = false;
    var resizeTimer = null;
    var drawTimer = null;

    /**
     * 清空 SVG 内容。
     */
    function clearSvg() {
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }
    }

    /**
     * 创建 SVG 元素。
     */
    function createSvgElement(tag, attributes) {
        var element = document.createElementNS('http://www.w3.org/2000/svg', tag);

        Object.keys(attributes).forEach(function (key) {
            element.setAttribute(key, attributes[key]);
        });

        return element;
    }

    /**
     * 绘制全部连线。
     */
    function drawBranches(animate) {
        clearSvg();

        if (mobileQuery.matches || cards.length === 0) {
            return;
        }

        var stageRect = stage.getBoundingClientRect();
        if (stageRect.width === 0 || stageRect.height === 0) {
            return;
        }

        svg.setAttribute('viewBox', '0 0 ' + stageRect.width + ' ' + stageRect.height);
        svg.setAttribute('preserveAspectRatio', 'none');

        var rootRect = root.getBoundingClientRect();
        var rootY = rootRect.top - stageRect.top + rootRect.height / 2;
        var shouldAnimate = animate && !hasAnimatedLines && !reducedMotion.matches;

        cards.forEach(function (card, index) {
            var column = card.closest('.branch-col');
            var isLeft = column && column.classList.contains('branch-left');
            var cardRect = card.getBoundingClientRect();

            var startX = isLeft
                ? rootRect.left - stageRect.left
                : rootRect.right - stageRect.left;
            var endX = isLeft
                ? cardRect.right - stageRect.left
                : cardRect.left - stageRect.left;
            var endY = cardRect.top - stageRect.top + cardRect.height / 2;
            var distance = Math.abs(endX - startX);
            var bend = Math.max(34, distance * 0.44);

            var control1X = startX + (isLeft ? -bend : bend);
            var control2X = endX + (isLeft ? bend : -bend);

            var pathData = 'M ' + startX.toFixed(2) + ' ' + rootY.toFixed(2)
                + ' C ' + control1X.toFixed(2) + ' ' + rootY.toFixed(2)
                + ', ' + control2X.toFixed(2) + ' ' + endY.toFixed(2)
                + ', ' + endX.toFixed(2) + ' ' + endY.toFixed(2);

            var accent = getComputedStyle(card).getPropertyValue('--accent').trim() || '#49e6b3';

            var glowPath = createSvgElement('path', {
                d: pathData,
                class: 'branch-glow',
                stroke: accent
            });
            glowPath.style.color = accent;

            var linePath = createSvgElement('path', {
                d: pathData,
                'data-branch-index': String(index),
                stroke: accent
            });
            linePath.style.color = accent;

            svg.appendChild(glowPath);
            svg.appendChild(linePath);

            if (shouldAnimate) {
                try {
                    var totalLength = linePath.getTotalLength();
                    linePath.style.strokeDasharray = String(totalLength);
                    linePath.style.strokeDashoffset = String(totalLength);
                    linePath.style.transition = 'stroke-dashoffset 0.85s cubic-bezier(0.2, 0.75, 0.2, 1) '
                        + (70 + index * 60) + 'ms';

                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(function () {
                            linePath.style.strokeDashoffset = '0';
                        });
                    });
                } catch (error) {
                    linePath.style.strokeDashoffset = '0';
                }
            }
        });

        if (shouldAnimate) {
            hasAnimatedLines = true;
        }
    }

    /**
     * 合并短时间内的重复绘制请求。
     */
    function scheduleDraw(animate) {
        window.clearTimeout(drawTimer);
        drawTimer = window.setTimeout(function () {
            drawBranches(Boolean(animate));
        }, 60);
    }

    // 初始绘制：先立即绘制，再在卡片动画和字体加载完成后校准
    scheduleDraw(true);
    window.setTimeout(function () {
        scheduleDraw(false);
    }, 1100);

    cards.forEach(function (card) {
        card.addEventListener('animationend', function () {
            scheduleDraw(false);
        });
    });

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () {
            scheduleDraw(false);
        });
    }

    window.addEventListener('load', function () {
        scheduleDraw(false);
    });

    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            hasAnimatedLines = true;
            drawBranches(false);
        }, 140);
    });

    if (typeof window.ResizeObserver !== 'undefined') {
        var observer = new ResizeObserver(function () {
            scheduleDraw(false);
        });
        observer.observe(stage);
        observer.observe(root);
        cards.forEach(function (card) {
            observer.observe(card);
        });
    }

    if (mobileQuery.addEventListener) {
        mobileQuery.addEventListener('change', function () {
            scheduleDraw(false);
        });
    } else if (mobileQuery.addListener) {
        mobileQuery.addListener(function () {
            scheduleDraw(false);
        });
    }
})();

/**
 * 节点在线状态与延迟检测
 * ------------------------------------------------------------------
 * 对每个节点卡片上 data-check-url 指向的地址发起一次轻量探测：
 * - 站外地址使用 fetch + no-cors，只要能建立连接即视为在线（响应不可读）
 * - 超时（默认 6 秒）或网络错误视为离线
 * - 延迟为从发起到收到响应的耗时，仅供参考
 *
 * 注意：HTTPS 页面探测 HTTP 地址会被浏览器按混合内容拦截，
 * 此类节点会显示为离线，这是浏览器的安全限制。
 */
(function () {
    'use strict';

    var cards = document.querySelectorAll('.branch-card[data-check-url]');
    if (!cards.length) {
        return;
    }

    var TIMEOUT_MS = 6000;

    /**
     * 更新卡片上的状态徽章。
     */
    function setStatus(card, state, latency) {
        var badge = card.querySelector('[data-node-status]');
        if (!badge) {
            return;
        }

        var text = badge.querySelector('.status-text');
        var latencyEl = badge.querySelector('.status-latency');

        badge.classList.remove('is-checking', 'is-online', 'is-offline');
        badge.classList.add(state === 'online' ? 'is-online' : (state === 'offline' ? 'is-offline' : 'is-checking'));

        if (text) {
            text.textContent = state === 'online' ? '在线' : (state === 'offline' ? '离线' : '检测中');
        }
        if (latencyEl) {
            latencyEl.textContent = state === 'online' && typeof latency === 'number'
                ? Math.max(1, Math.round(latency)) + ' ms'
                : '';
        }
    }

    /**
     * 探测单个地址，返回 Promise（不 reject，统一 resolve 为结果对象）。
     */
    function probe(url) {
        return new Promise(function (resolve) {
            var controller = typeof window.AbortController !== 'undefined' ? new AbortController() : null;
            var startedAt = window.performance && performance.now ? performance.now() : Date.now();
            var settled = false;

            function done(state) {
                if (settled) {
                    return;
                }
                settled = true;
                var endedAt = window.performance && performance.now ? performance.now() : Date.now();
                resolve({ state: state, latency: endedAt - startedAt });
            }

            var timer = window.setTimeout(function () {
                if (controller) {
                    try {
                        controller.abort();
                    } catch (error) {
                        /* 忽略中止异常 */
                    }
                }
                done('offline');
            }, TIMEOUT_MS);

            var options = {
                method: 'HEAD',
                mode: 'no-cors',
                cache: 'no-store',
                credentials: 'omit',
                redirect: 'follow'
            };
            if (controller) {
                options.signal = controller.signal;
            }

            window.fetch(url, options).then(function () {
                window.clearTimeout(timer);
                done('online');
            }).catch(function () {
                window.clearTimeout(timer);
                done('offline');
            });
        });
    }

    // 浏览器不支持 fetch 时，全部标记为未检测，避免一直停留在「检测中」
    if (typeof window.fetch !== 'function') {
        cards.forEach(function (card) {
            var badge = card.querySelector('[data-node-status]');
            var text = badge ? badge.querySelector('.status-text') : null;
            if (badge) {
                badge.classList.remove('is-checking');
                badge.classList.add('is-offline');
            }
            if (text) {
                text.textContent = '未检测';
            }
        });
        return;
    }

    // 逐个错开探测，避免一次性并发请求过多
    cards.forEach(function (card, index) {
        var url = card.getAttribute('data-check-url') || '';

        if (url === '' || url === '#') {
            window.setTimeout(function () {
                setStatus(card, 'offline');
            }, 0);
            return;
        }

        window.setTimeout(function () {
            probe(url).then(function (result) {
                setStatus(card, result.state, result.latency);
            });
        }, 260 + index * 180);
    });
})();
