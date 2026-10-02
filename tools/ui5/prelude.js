/**
 * First module of the UI5 bundle: configures UI5 before any component is defined.
 * Reads what the layout and public/assets/js/lt-theme.js (loaded in <head>) wrote on <html>:
 *   lang="fr|en"  data-lt-theme="sap_horizon|sap_horizon_dark|sap_horizon_hcb|sap_horizon_hcw"
 *
 * The values are handed to UI5 as its *initial* configuration (a JSON data block created here,
 * read once by UI5 on first use), never with setLanguage()/setTheme(): a language change while
 * the components are being defined re-renders them before their texts are loaded (rendering
 * errors), and resolves the CLDR data too early (see below).
 * The views contain no inline block: the CSP stays `script-src 'self'`.
 */
import { registerLocaleDataLoader } from '@ui5/webcomponents-base/dist/asset-registries/LocaleData.js';

const root = document.documentElement;
const config = document.createElement('script');
config.type = 'application/json';
config.setAttribute('data-ui5-config', '');
config.textContent = JSON.stringify({
    language: root.getAttribute('lang') || 'fr',
    theme: root.getAttribute('data-lt-theme') || 'sap_horizon',
    // The "72" font faces are served locally by ui5-fonts.css (UI5 would fetch them from jsDelivr).
    defaultFontLoading: false,
    // ?sap-ui-theme=… and the like must not override the application's settings.
    ignoreUrlParams: true,
});
document.head.append(config);

// Local CLDR data. UI5 ships a built-in "en" loader that fetches from jsDelivr (blocked by the CSP):
// it must never be the one in place. The languages must match LOCALES in components.mjs.
registerLocaleDataLoader('fr', async () => (await import('@ui5/webcomponents-localization/dist/generated/assets/cldr/fr.json')).default);
registerLocaleDataLoader('en', async () => (await import('@ui5/webcomponents-localization/dist/generated/assets/cldr/en.json')).default);
