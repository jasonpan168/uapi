<?php
/**
 * Unified language switcher dropdown.
 *
 * Usage: <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
 *
 * Self-contained: no Bootstrap/Tailwind dependency, no external CSS/JS.
 * Inline <style> and <script> are emitted only once per page (guarded via
 * a $GLOBALS flag) so multiple includes on the same page are safe.
 *
 * Customize per-page positioning with `.uapi-lang-switcher` on the parent
 * container or by overriding the CSS variables on `.uapi-lang-switcher`.
 */

if (!class_exists('I18n')) {
    // Hard requirement; bail silently rather than crash.
    return;
}

$__uapi_ls_current = I18n::getLang();
$__uapi_ls_query = is_array($_GET ?? null) ? $_GET : [];
unset($__uapi_ls_query['lang']);
$__uapi_ls_qs = !empty($__uapi_ls_query) ? '&amp;' . htmlspecialchars(http_build_query($__uapi_ls_query)) : '';

$__uapi_ls_langs = [
    'en'    => ['label' => 'English',  'hreflang' => 'en'],
    'zh-tw' => ['label' => '繁體中文', 'hreflang' => 'zh-TW'],
    'zh-cn' => ['label' => '简体中文', 'hreflang' => 'zh-CN'],
    'ja'    => ['label' => '日本語',   'hreflang' => 'ja'],
];

$__uapi_ls_cur = $__uapi_ls_langs[$__uapi_ls_current] ?? $__uapi_ls_langs['en'];

if (empty($GLOBALS['__uapi_ls_assets_emitted'])):
    $GLOBALS['__uapi_ls_assets_emitted'] = true;
?>
<style>
.uapi-lang-switcher {
    position: relative;
    display: inline-block;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 14px;
    line-height: 1;
    color: #1f2937;
}
.uapi-lang-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid rgba(15, 23, 42, 0.12);
    border-radius: 8px;
    cursor: pointer;
    color: inherit;
    font: inherit;
    transition: border-color .15s ease, background .15s ease;
    white-space: nowrap;
}
.uapi-lang-toggle:hover,
.uapi-lang-switcher.open .uapi-lang-toggle {
    border-color: rgba(15, 23, 42, 0.28);
    background: #fff;
}
.uapi-lang-toggle:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
}
.uapi-lang-toggle .uapi-lang-globe {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}
.uapi-lang-toggle .uapi-lang-caret {
    width: 10px;
    height: 10px;
    flex-shrink: 0;
    transition: transform .15s ease;
}
.uapi-lang-switcher.open .uapi-lang-toggle .uapi-lang-caret {
    transform: rotate(180deg);
}
.uapi-lang-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 160px;
    margin: 0;
    padding: 4px 0;
    background: #fff;
    border: 1px solid rgba(15, 23, 42, 0.10);
    border-radius: 10px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.10), 0 2px 6px rgba(15, 23, 42, 0.04);
    list-style: none;
    z-index: 1000;
    opacity: 0;
    transform: translateY(-4px);
    pointer-events: none;
    transition: opacity .12s ease, transform .12s ease;
}
.uapi-lang-switcher.open .uapi-lang-menu {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}
.uapi-lang-menu li { margin: 0; padding: 0; }
.uapi-lang-menu a {
    display: block;
    padding: 9px 14px;
    color: #1f2937;
    text-decoration: none;
    font-size: 14px;
    transition: background .12s ease;
}
.uapi-lang-menu a:hover { background: #f3f4f6; }
.uapi-lang-menu li.is-current a {
    background: #eff4ff;
    color: #2563EB;
    font-weight: 600;
}
@media (prefers-color-scheme: dark) {
    .uapi-lang-switcher { color: #e5e7eb; }
    .uapi-lang-toggle {
        background: rgba(15, 23, 42, 0.6);
        border-color: rgba(255, 255, 255, 0.12);
    }
    .uapi-lang-toggle:hover,
    .uapi-lang-switcher.open .uapi-lang-toggle {
        background: rgba(15, 23, 42, 0.85);
        border-color: rgba(255, 255, 255, 0.28);
    }
    .uapi-lang-menu {
        background: #0f172a;
        border-color: rgba(255, 255, 255, 0.12);
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
    }
    .uapi-lang-menu a { color: #e5e7eb; }
    .uapi-lang-menu a:hover { background: rgba(255, 255, 255, 0.06); }
    .uapi-lang-menu li.is-current a {
        background: rgba(37, 99, 235, 0.2);
        color: #93c5fd;
    }
}
</style>
<script>
(function () {
    function init() {
        document.querySelectorAll('.uapi-lang-switcher').forEach(function (el) {
            if (el.dataset.uapiLsBound === '1') return;
            el.dataset.uapiLsBound = '1';
            var btn = el.querySelector('.uapi-lang-toggle');
            if (!btn) return;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.uapi-lang-switcher.open').forEach(function (other) {
                    if (other !== el) {
                        other.classList.remove('open');
                        var ob = other.querySelector('.uapi-lang-toggle');
                        if (ob) ob.setAttribute('aria-expanded', 'false');
                    }
                });
                var opened = el.classList.toggle('open');
                btn.setAttribute('aria-expanded', opened ? 'true' : 'false');
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    el.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                    btn.focus();
                }
            });
        });
    }
    document.addEventListener('click', function () {
        document.querySelectorAll('.uapi-lang-switcher.open').forEach(function (el) {
            el.classList.remove('open');
            var b = el.querySelector('.uapi-lang-toggle');
            if (b) b.setAttribute('aria-expanded', 'false');
        });
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php endif; ?>
<div class="uapi-lang-switcher" data-lang="<?php echo htmlspecialchars($__uapi_ls_current); ?>">
    <button type="button" class="uapi-lang-toggle" aria-haspopup="listbox" aria-expanded="false" aria-label="<?php echo htmlspecialchars(I18n::t('merchant.topbar.language')); ?>">
        <svg class="uapi-lang-globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>
        </svg>
        <span class="uapi-lang-current"><?php echo htmlspecialchars($__uapi_ls_cur['label']); ?></span>
        <svg class="uapi-lang-caret" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M2 4l4 4 4-4"/>
        </svg>
    </button>
    <ul class="uapi-lang-menu" role="listbox">
        <?php foreach ($__uapi_ls_langs as $__uapi_ls_code => $__uapi_ls_info): ?>
            <li class="<?php echo $__uapi_ls_code === $__uapi_ls_current ? 'is-current' : ''; ?>" role="option" aria-selected="<?php echo $__uapi_ls_code === $__uapi_ls_current ? 'true' : 'false'; ?>">
                <a href="?lang=<?php echo htmlspecialchars($__uapi_ls_code) . $__uapi_ls_qs; ?>" hreflang="<?php echo htmlspecialchars($__uapi_ls_info['hreflang']); ?>"><?php echo htmlspecialchars($__uapi_ls_info['label']); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php
unset($__uapi_ls_current, $__uapi_ls_query, $__uapi_ls_qs, $__uapi_ls_langs, $__uapi_ls_cur, $__uapi_ls_code, $__uapi_ls_info);
?>
