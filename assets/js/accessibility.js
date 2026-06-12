/**
 * DentalCare.PH — Accessibility JS
 * Handles: aria-expanded, focus trapping in modals/drawers,
 *           live-region announcements, keyboard shortcuts
 */
(function () {
    'use strict';

    /* ── 1. Live region announcer ────────────────────────────
       Call announce('Your message') anywhere to push text to
       screen readers without moving visual focus.            */
    window.announce = function (msg, priority) {
        var region = document.getElementById('a11y-live-region');
        if (!region) return;
        region.setAttribute('aria-live', priority || 'polite');
        // Clear first so repeated same msg is re-announced
        region.textContent = '';
        setTimeout(function () { region.textContent = msg; }, 50);
    };

    /* ── 2. Notification bell — aria-expanded ─────────────── */
    document.addEventListener('DOMContentLoaded', function () {

        /* Bell button */
        var bell = document.getElementById('notifBell');
        if (bell) {
            bell.setAttribute('aria-haspopup', 'true');
            bell.setAttribute('aria-expanded', 'false');
            bell.setAttribute('aria-controls', 'notifPanel');
            var origToggle = window.toggleNotifPanel;
            window.toggleNotifPanel = function (e) {
                origToggle && origToggle(e);
                var panel = document.getElementById('notifPanel');
                var isOpen = panel && panel.style.display !== 'none';
                bell.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            };
        }

        /* Sidebar toggle button */
        var sidebarToggle = document.getElementById('sidebar-toggle');
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-label', 'Toggle navigation sidebar');
            sidebarToggle.setAttribute('aria-controls', 'sidebar');
            sidebarToggle.setAttribute('aria-expanded', 'true');
            sidebarToggle.addEventListener('click', function () {
                var sidebar   = document.getElementById('sidebar');
                var collapsed = sidebar && sidebar.classList.contains('collapsed');
                sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        }

        /* Theme toggle */
        var themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) {
            themeToggle.setAttribute('aria-label', 'Toggle dark mode');
            themeToggle.setAttribute('aria-pressed',
                document.documentElement.getAttribute('data-theme') === 'dark' ? 'true' : 'false');
            var origToggleTheme = window.toggleTheme;
            window.toggleTheme = function () {
                origToggleTheme && origToggleTheme();
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                announce(isDark ? 'Dark mode enabled' : 'Light mode enabled');
            };
        }

        /* ── 3. Drawer focus trap ─────────────────────────── */
        var walkinDrawer = document.getElementById('walkinDrawer');
        if (walkinDrawer) {
            walkinDrawer.setAttribute('role', 'dialog');
            walkinDrawer.setAttribute('aria-modal', 'true');
            walkinDrawer.setAttribute('aria-label', 'New Appointment');

            /* Trap Tab key inside open drawer */
            walkinDrawer.addEventListener('keydown', function (e) {
                if (!walkinDrawer.classList.contains('open')) return;
                if (e.key !== 'Tab') return;
                var focusable = walkinDrawer.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                var first = focusable[0];
                var last  = focusable[focusable.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === first) { e.preventDefault(); last.focus(); }
                } else {
                    if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
                }
            });

            /* Close drawer on Escape */
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && walkinDrawer.classList.contains('open')) {
                    if (typeof closeWalkinDrawer === 'function') closeWalkinDrawer();
                    var openBtn = document.querySelector('[onclick*="openWalkinDrawer"]');
                    if (openBtn) openBtn.focus();
                }
            });

            /* Move focus into drawer when opened */
            var origOpen  = window.openWalkinDrawer;
            var origClose = window.closeWalkinDrawer;
            if (origOpen) {
                window.openWalkinDrawer = function () {
                    origOpen();
                    setTimeout(function () {
                        var first = walkinDrawer.querySelector(
                            'button:not([disabled]), input:not([disabled]), select:not([disabled])'
                        );
                        if (first) first.focus();
                    }, 100);
                };
            }
            if (origClose) {
                window.closeWalkinDrawer = function () {
                    origClose();
                    announce('Appointment drawer closed');
                };
            }
        }

        /* ── 4. Bootstrap modals — Escape already handled,
               but add aria-label to any modal without one   */
        document.querySelectorAll('.modal').forEach(function (modal) {
            if (!modal.getAttribute('aria-label') && !modal.getAttribute('aria-labelledby')) {
                var heading = modal.querySelector('.modal-title');
                if (heading) {
                    if (!heading.id) heading.id = 'modal-title-' + Math.random().toString(36).slice(2, 7);
                    modal.setAttribute('aria-labelledby', heading.id);
                }
            }
        });

        /* ── 5. Announce toast messages ──────────────────── */
        var walkinToast = document.getElementById('walkinToast');
        if (walkinToast) {
            var toastObserver = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.type === 'attributes' && m.attributeName === 'style') {
                        if (walkinToast.style.display !== 'none') {
                            var title = document.getElementById('walkinToastTitle');
                            var msg   = document.getElementById('walkinToastMsg');
                            if (title) announce((title.textContent || '') + '. ' + (msg ? msg.textContent : ''), 'assertive');
                        }
                    }
                });
            });
            toastObserver.observe(walkinToast, { attributes: true });
        }

        /* ── 6. Icon-only buttons — ensure aria-label ─────── */
        document.querySelectorAll('button, a').forEach(function (el) {
            var text = el.textContent.trim();
            var hasIcon = el.querySelector('.bi');
            var hasLabel = el.getAttribute('aria-label') || el.getAttribute('title');
            /* If the element is icon-only and has no label, use the title or icon class */
            if (hasIcon && !text && !hasLabel) {
                var icon = el.querySelector('[class*="bi-"]');
                if (icon) {
                    var name = (icon.className.match(/bi-([a-z0-9-]+)/) || [])[1] || 'button';
                    el.setAttribute('aria-label', name.replace(/-/g, ' '));
                }
            }
            /* Give title to element that has aria-label but no title (tooltip) */
            if (el.getAttribute('aria-label') && !el.getAttribute('title')) {
                el.setAttribute('title', el.getAttribute('aria-label'));
            }
        });

        /* ── 7. Table sort / nav: announce page changes ──── */
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (btn) {
            if (!btn.getAttribute('aria-label')) btn.setAttribute('aria-label', 'Close dialog');
        });

    }); // end DOMContentLoaded

    /* ── 8. Keyboard shortcut: / to focus search ─────────── */
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            var searchInput = document.querySelector('input[name="search"], input[type="search"]');
            if (searchInput) { e.preventDefault(); searchInput.focus(); announce('Search box focused'); }
        }
        /* Ctrl+B or Cmd+B: toggle sidebar */
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            var toggle = document.getElementById('sidebar-toggle');
            if (toggle) { e.preventDefault(); toggle.click(); }
        }
    });

})();
