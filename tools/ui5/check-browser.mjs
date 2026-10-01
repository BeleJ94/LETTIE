/**
 * Browser check of the UI5 set-up (development only: `npm run ui5:check`).
 * Uses the demo database (tools/demo/demo.json, `composer demo:seed` first) and the built-in
 * PHP server, like the screenshots. Fails on any CSP violation or JS error and checks:
 * theme variables applied, "72" font, sap_horizon ↔ sap_horizon_dark toggle, compact on
 * desktop / cozy on touch, French UI5 texts.
 */
import { BASE_URL, launchBrowser, login, readDemo, startServer } from '../playwright/demo.mjs';

const PAGES = ['/', '/mails', '/register', '/statistics', '/delegations', '/notifications'];

const demo = readDemo();
const server = await startServer(demo);
const browser = await launchBrowser();
const problems = [];

function expect(condition, message) {
    console.log(`  ${condition ? 'ok ' : 'KO '} ${message}`);
    if (!condition) problems.push(message);
}

async function open(context, account) {
    const page = await context.newPage();
    page.on('console', (m) => {
        if (m.location()?.url?.endsWith('/favicon.ico')) return; // no favicon yet (unrelated to UI5)
        if (m.type() === 'error' || /Content.Security.Policy/i.test(m.text())) problems.push(`console: ${m.text()} [${m.location()?.url ?? ''}]`);
    });
    page.on('response', (r) => { if (r.status() >= 400) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
    page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
    await login(page, demo.accounts[account], demo.password);
    return page;
}

const state = (page) => page.evaluate(async () => {
    await document.fonts.ready;
    const html = document.documentElement;
    const css = getComputedStyle(document.body);
    const probe = document.createElement('ui5-date-picker');
    document.body.append(probe);
    await customElements.whenDefined('ui5-date-picker');
    await new Promise((r) => setTimeout(r, 300));
    const placeholder = probe.shadowRoot?.querySelector('[ui5-datetime-input], ui5-input, input')?.getAttribute('placeholder') ?? '';
    probe.remove();
    return {
        ready: html.classList.contains('lt-ui5-ready'),
        theme: html.getAttribute('data-lt-theme'),
        ui5Theme: window.LT_UI5?.getTheme(),
        language: window.LT_UI5?.getLanguage(),
        density: html.getAttribute('data-lt-density'),
        compact: html.classList.contains('ui5-content-density-compact'),
        brand: getComputedStyle(html).getPropertyValue('--sapBrandColor').trim(),
        background: css.backgroundColor,
        font: css.fontFamily,
        font72: document.fonts.check('14px "72"'),
        placeholder,
    };
});

try {
    console.log('Desktop (mouse):');
    const desktop = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'fr-FR' });
    const page = await open(desktop, 'admin');
    for (const path of PAGES) {
        await page.goto(BASE_URL + path, { waitUntil: 'networkidle' });
        const s = await state(page);
        expect(s.ready && s.brand !== '', `${path}: theme variables applied (--sapBrandColor ${s.brand})`);
    }
    let s = await state(page);
    expect(s.theme === 'sap_horizon' && s.ui5Theme === 'sap_horizon', `default theme sap_horizon (${s.ui5Theme})`);
    expect(s.font.startsWith('"72"') && s.font72, `font 72 loaded (${s.font})`);
    expect(s.density === 'compact' && s.compact, 'compact density with a mouse');
    expect(s.language === 'fr', `UI5 language fr (${s.language})`);
    expect(/^(jj|JJ)/.test(s.placeholder) || /\d/.test(s.placeholder), `UI5 date picker formatted for French (placeholder "${s.placeholder}")`);
    const lightBackground = s.background;

    await page.click('[data-lt-theme-toggle]');
    await page.waitForTimeout(800);
    s = await state(page);
    expect(s.theme === 'sap_horizon_dark' && s.ui5Theme === 'sap_horizon_dark', `toggle → sap_horizon_dark (${s.ui5Theme})`);
    expect(s.background !== lightBackground, `page background follows the theme (${lightBackground} → ${s.background})`);
    await page.reload({ waitUntil: 'networkidle' });
    s = await state(page);
    expect(s.ui5Theme === 'sap_horizon_dark', 'dark theme remembered after reload');
    await page.click('[data-lt-theme-toggle]');
    await page.waitForTimeout(500);
    expect((await state(page)).ui5Theme === 'sap_horizon', 'toggle back → sap_horizon');

    console.log('Touch screen:');
    const touch = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR' });
    const phone = await open(touch, 'agent');
    s = await state(phone);
    expect(s.density === 'cozy' && !s.compact, 'cozy density on a touch screen');

    console.log('High contrast:');
    const contrast = await browser.newContext({ forcedColors: 'active', locale: 'fr-FR' });
    const hc = await open(contrast, 'agent');
    s = await state(hc);
    expect(s.ui5Theme === 'sap_horizon_hcw', `forced colours → sap_horizon_hcw (${s.ui5Theme})`);

    console.log('Printable slip (theme locked):');
    await page.click('[data-lt-theme-toggle]');
    await page.goto(`${BASE_URL}/mails`, { waitUntil: 'networkidle' });
    const mail = await page.locator('table.dataTable tbody a[href*="/mails/"]').first().getAttribute('href');
    await page.goto(`${BASE_URL}${mail}/slip`, { waitUntil: 'networkidle' });
    s = await state(page);
    expect(s.ui5Theme === 'sap_horizon', `slip stays in sap_horizon with the dark preference (${s.ui5Theme})`);
} finally {
    await browser.close();
    server.kill();
}

if (problems.length > 0) {
    console.error(`\n${problems.length} problem(s):\n- ${problems.join('\n- ')}`);
    process.exit(1);
}
console.log('\nUI5 set-up OK.');
