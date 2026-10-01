/**
 * Lettie theme and density (docs/FIORI_DESIGN.md §3 and §6).
 *
 * Loaded in <head>, before the page is painted and before the UI5 bundle: it writes
 * on <html> what the bundle's prelude reads (data-lt-theme), and the UI5 density class.
 *
 * - resolveTheme / resolveDensity / nextPreference are pure (tested with node:test).
 * - In the browser only, LT.theme.apply() runs at load and follows the system
 *   preferences (contrast, pointer); LT.theme.toggle() switches light ↔ dark.
 *
 *   <html data-lt-theme="sap_horizon" data-lt-density="compact" class="ui5-content-density-compact">
 *   <html data-lt-theme-lock="sap_horizon">   fixed theme (printable documents)
 */
(function (root, factory) {
    'use strict';
    var theme = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = theme;
    } else {
        root.LT = root.LT || {};
        root.LT.theme = theme;
        theme.apply(root);
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var STORAGE_KEY = 'lt.theme';
    var COMPACT_CLASS = 'ui5-content-density-compact';
    var THEMES = {
        light: 'sap_horizon',
        dark: 'sap_horizon_dark',
        contrastLight: 'sap_horizon_hcw',
        contrastDark: 'sap_horizon_hcb'
    };

    /**
     * @param {?string} preference 'light' | 'dark' | null (no choice: sap_horizon)
     * @param {{moreContrast?: boolean, systemDark?: boolean}} system
     * @returns {string} UI5 theme name
     */
    function resolveTheme(preference, system) {
        system = system || {};
        var dark = preference === 'dark' || (preference !== 'light' && system.moreContrast === true && system.systemDark === true);
        if (system.moreContrast === true) {
            return dark ? THEMES.contrastDark : THEMES.contrastLight;
        }
        return dark ? THEMES.dark : THEMES.light;
    }

    /**
     * Compact with a precise pointer (mouse, trackpad), cozy on touch screens.
     * @param {boolean} finePointer
     * @returns {'compact'|'cozy'}
     */
    function resolveDensity(finePointer) {
        return finePointer ? 'compact' : 'cozy';
    }

    /** Light ↔ dark, from the theme currently shown. */
    function nextPreference(currentTheme) {
        return currentTheme === THEMES.dark || currentTheme === THEMES.contrastDark ? 'light' : 'dark';
    }

    /* ------------------------------------------------------- browser only */

    var win = null;

    function media(query) {
        return win.matchMedia ? win.matchMedia(query) : { matches: false, addEventListener: function () {} };
    }

    function readPreference() {
        try {
            var value = win.localStorage.getItem(STORAGE_KEY);
            return value === 'light' || value === 'dark' ? value : null;
        } catch (e) {
            return null;
        }
    }

    function writePreference(value) {
        try {
            win.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // private window or blocked storage: the choice lasts for this page only
        }
    }

    function render(preference) {
        var html = win.document.documentElement;
        var name = html.getAttribute('data-lt-theme-lock') || resolveTheme(preference, {
            moreContrast: media('(prefers-contrast: more)').matches || media('(forced-colors: active)').matches,
            systemDark: media('(prefers-color-scheme: dark)').matches
        });
        var density = resolveDensity(media('(pointer: fine)').matches);

        html.setAttribute('data-lt-theme', name);
        html.setAttribute('data-lt-density', density);
        html.classList.toggle(COMPACT_CLASS, density === 'compact');
        if (win.LT_UI5) {
            win.LT_UI5.setTheme(name);
        }
        if (win.document.dispatchEvent && typeof win.CustomEvent === 'function') {
            win.document.dispatchEvent(new win.CustomEvent('lt:theme-change', { detail: { theme: name, density: density } }));
        }
        return name;
    }

    function apply(windowObject) {
        win = windowObject;
        var rerender = function () {
            render(readPreference());
        };
        ['(prefers-contrast: more)', '(forced-colors: active)', '(prefers-color-scheme: dark)', '(pointer: fine)']
            .forEach(function (query) {
                media(query).addEventListener('change', rerender);
            });
        return render(readPreference());
    }

    function current() {
        return win ? win.document.documentElement.getAttribute('data-lt-theme') : THEMES.light;
    }

    function toggle() {
        var preference = nextPreference(current());
        writePreference(preference);
        return render(preference);
    }

    return {
        THEMES: THEMES,
        resolveTheme: resolveTheme,
        resolveDensity: resolveDensity,
        nextPreference: nextPreference,
        apply: apply,
        current: current,
        toggle: toggle
    };
});
