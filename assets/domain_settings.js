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

        // --- layer 1: our own dialog on every way off the page -------------
        // Not just the language switch: the tabs, "edit fields", help and
        // settings all lead away from an unsaved form, and the browser's own
        // dialog cannot offer to save first.
        if (dialog && window.jQuery) {
            var pendingHref = null;

            function leavesThePage(link, event) {
                // A new tab or window is not leaving this page.
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button) {
                    return false;
                }

                if (link.target && '_self' !== link.target) {
                    return false;
                }

                // Anything that only acts on this page: dropdown toggles,
                // modal triggers, in-page anchors, javascript: links.
                if (link.hasAttribute('data-toggle') || link.hasAttribute('data-dismiss')) {
                    return false;
                }

                var href = link.getAttribute('href');
                if (!href || '#' === href.charAt(0) || 0 === href.toLowerCase().indexOf('javascript:')) {
                    return false;
                }

                // The dialog's own buttons.
                return !dialog.contains(link);
            }

            document.addEventListener('click', function (event) {
                var link = event.target.closest ? event.target.closest('a[href]') : null;

                if (!link || !leavesThePage(link, event) || !isDirty()) {
                    return;
                }

                event.preventDefault();
                pendingHref = {
                    // As written in the markup: that is what the server
                    // accepts, and it cannot carry another host.
                    param: link.getAttribute('href'),
                    // Resolved, for going there without saving.
                    url: link.href
                };
                window.jQuery(dialog).modal('show');
            });

            // The domain switch is a select, not a link, so it needs its own
            // way in - but it ends in the same dialog.
            document.querySelectorAll('[data-domain-settings-switch]').forEach(function (select) {
                select.addEventListener('change', function () {
                    var switchForm = select.form;
                    if (!switchForm) {
                        return;
                    }

                    // Built from the form itself, so it carries page, section
                    // and language along with the new domain - and stays a
                    // relative link, which is what the server accepts.
                    var target = 'index.php?' + new URLSearchParams(new FormData(switchForm)).toString();

                    if (!isDirty()) {
                        submitting = true;
                        window.location.href = target;
                        return;
                    }

                    pendingHref = {param: target, url: target};
                    window.jQuery(dialog).modal('show');
                });
            });

            dialog.querySelector('[data-domain-settings-action="save"]').addEventListener('click', function () {
                if (!pendingHref) {
                    return;
                }

                // Where to go after saving. The value is a URL now rather than
                // a language id, so the server checks it before following it -
                // see pages/values.php.
                var goto = document.createElement('input');
                goto.type = 'hidden';
                goto.name = 'domain_settings_goto';
                goto.value = pendingHref.param;
                form.appendChild(goto);
                submitting = true;
                form.submit();
            });

            // Cancelling leaves the page as it was, so the select must not
            // keep showing a domain that is not being edited.
            window.jQuery(dialog).on('hidden.bs.modal', function () {
                if (!pendingHref) {
                    return;
                }
                document.querySelectorAll('[data-domain-settings-switch]').forEach(function (select) {
                    if (!select.form) {
                        return;
                    }
                    select.form.reset();
                    // The dressed-up select keeps its own label, so it has to
                    // be told that the value underneath went back.
                    if (window.jQuery && window.jQuery(select).selectpicker) {
                        window.jQuery(select).selectpicker('refresh');
                    }
                });
            });

            dialog.querySelector('[data-domain-settings-action="discard"]').addEventListener('click', function () {
                if (!pendingHref) {
                    return;
                }
                submitting = true;
                window.location.href = pendingHref.url;
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
