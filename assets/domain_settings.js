/**
 * Makes YForm fieldsets collapsible on this addon's editing page.
 *
 * Scoped to .domain-settings-form so no other YForm form in the backend is touched.
 * The open/closed state is remembered per group, because an editor who closed
 * a group does not want it back open after every save.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'domain_settings.collapsed';

    function readClosed() {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY)) || {};
        } catch (e) {
            return {};
        }
    }

    function writeClosed(closed) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(closed));
        } catch (e) {
            // Private mode or a full quota - collapsing still works, it just
            // does not survive the next page load.
        }
    }

    function init() {
        var closed = readClosed();

        document.querySelectorAll('.domain-settings-form fieldset').forEach(function (fieldset) {
            var legend = fieldset.querySelector(':scope > legend');
            if (!legend) {
                return;
            }

            var key = legend.textContent.trim();

            legend.setAttribute('role', 'button');
            legend.setAttribute('tabindex', '0');

            function apply(isClosed) {
                fieldset.classList.toggle('domain-settings-collapsed', isClosed);
                legend.setAttribute('aria-expanded', isClosed ? 'false' : 'true');
            }

            apply(!!closed[key]);

            function toggle() {
                var isClosed = !fieldset.classList.contains('domain-settings-collapsed');
                apply(isClosed);
                closed[key] = isClosed;
                writeClosed(closed);
            }

            legend.addEventListener('click', toggle);
            legend.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/**
 * Guards unsaved changes.
 *
 * Two layers, because they cover different exits:
 *
 * 1. Clicking a language tab is caught before the browser navigates, so we can
 *    show our own dialog with our own wording - including "save and switch",
 *    which posts the form with the target language attached.
 * 2. beforeunload catches everything else (back button, closing the tab, any
 *    other link). Its wording is fixed: browsers have ignored custom text
 *    since ~2016, so there is nothing to phrase there.
 */
(function () {
    'use strict';

    function init() {
        var form = document.querySelector('.domain-settings-form');
        if (!form) {
            return;
        }

        var dialog = document.getElementById('domain-settings-unsaved-dialog');

        function snapshot() {
            return new URLSearchParams(new FormData(form)).toString();
        }

        var initial = snapshot();
        var submitting = false;

        function isDirty() {
            return !submitting && snapshot() !== initial;
        }

        form.addEventListener('submit', function () {
            submitting = true;
        });

        // --- layer 1: our own dialog on the language tabs -----------------
        if (dialog && window.jQuery) {
            var pendingClang = null;

            document.querySelectorAll('.domain-settings-context a[data-clang-id]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    if (!isDirty()) {
                        return;
                    }
                    event.preventDefault();
                    pendingClang = {
                        id: link.getAttribute('data-clang-id'),
                        href: link.getAttribute('href')
                    };
                    window.jQuery(dialog).modal('show');
                });
            });

            dialog.querySelector('[data-domain-settings-action="save"]').addEventListener('click', function () {
                if (!pendingClang) {
                    return;
                }
                // Tell the page where to go after saving. Only the language id
                // travels, never a URL - the target is built server-side.
                var goto = document.createElement('input');
                goto.type = 'hidden';
                goto.name = 'domain_settings_goto_clang';
                goto.value = pendingClang.id;
                form.appendChild(goto);
                submitting = true;
                form.submit();
            });

            dialog.querySelector('[data-domain-settings-action="discard"]').addEventListener('click', function () {
                if (!pendingClang) {
                    return;
                }
                submitting = true;
                window.location.href = pendingClang.href;
            });
        }

        // --- layer 2: the browser's own net -------------------------------
        window.addEventListener('beforeunload', function (event) {
            if (!isDirty()) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
            return '';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
