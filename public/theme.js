// theme.js — Theme switcher for CMDB (system / light / dark)
(function () {
    'use strict';

    const STORAGE_KEY = 'cmdb-theme';
    const THEMES = ['system', 'light', 'dark'];

    // Icons for each theme mode
    const ICONS = {
        system: '\u23FB',  // ⏻ power symbol for system/auto
        light:  '\u2600',  // ☀️ sun
        dark:   '\u{1F319}' // 🌙 moon
    };

    function getStoredTheme() {
        var stored = localStorage.getItem(STORAGE_KEY);
        if (stored && THEMES.indexOf(stored) !== -1) {
            return stored;
        }
        return 'system';
    }

    function setStoredTheme(theme) {
        localStorage.setItem(STORAGE_KEY, theme);
    }

    function applyTheme(theme) {
        if (theme === 'system') {
            document.documentElement.removeAttribute('data-theme');
        } else {
            document.documentElement.setAttribute('data-theme', theme);
        }
    }

    function updateToggleUI(theme) {
        var icon = document.getElementById('themeIcon');
        var btn = document.getElementById('themeToggleBtn');
        if (icon) {
            icon.textContent = ICONS[theme] || ICONS.system;
        }
        if (btn) {
            btn.setAttribute('data-theme-mode', theme);
        }
    }

    function cycleTheme() {
        var current = getStoredTheme();
        var idx = THEMES.indexOf(current);
        var next = THEMES[(idx + 1) % THEMES.length];
        setStoredTheme(next);
        applyTheme(next);
        updateToggleUI(next);
    }

    // Expose to global scope for onclick handler
    window.cycleTheme = cycleTheme;

    // Initialize on page load
    var theme = getStoredTheme();
    applyTheme(theme);
    updateToggleUI(theme);

    // Listen for OS-level theme changes when in 'system' mode
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (getStoredTheme() === 'system') {
                // No explicit action needed — CSS media query handles it
            }
        });
    }
})();
