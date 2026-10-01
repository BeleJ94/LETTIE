/**
 * First module of the UI5 bundle: configures UI5 before any component is defined.
 * Reads what public/assets/js/lt-theme.js (loaded in <head>) wrote on <html>:
 *   data-lt-theme="sap_horizon|sap_horizon_dark|sap_horizon_hcb|sap_horizon_hcw"
 * No inline script and no JSON config block: the CSP stays `script-src 'self'`.
 */
import { setDefaultFontLoading } from '@ui5/webcomponents-base/dist/config/Fonts.js';
import { setTheme } from '@ui5/webcomponents-base/dist/config/Theme.js';

// The "72" font faces are served locally by ui5-fonts.css (UI5 would fetch them from jsDelivr).
setDefaultFontLoading(false);

const root = document.documentElement;
setTheme(root.getAttribute('data-lt-theme') || 'sap_horizon');
// The language (<html lang>) is set at the end of the bundle entry, once the local CLDR loaders
// are registered: set here, it would resolve to UI5's CDN "en" loader.
