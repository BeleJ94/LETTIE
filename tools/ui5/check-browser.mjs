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
        if (/status of 422/.test(m.text())) return;
        if (m.type() === 'error' || /Content.Security.Policy/i.test(m.text())) problems.push(`console: ${m.text()} [${m.location()?.url ?? ''}]`);
    });
    page.on('response', (r) => { // 422 is the expected answer to a refused form (tested below).
        if (r.status() >= 400 && r.status() !== 422) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
    page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
    await login(page, demo.accounts[account], demo.password);
    return page;
}

/** Theme toggle as the user does it: profile avatar of the ShellBar, then the menu item. */
async function toggleTheme(page) {
    await page.locator('#lt-profile').click();
    await page.locator('ui5-user-menu-item[data-lt-theme-toggle]').click();
}

/** Text of one column of the List Report table, for the rows currently loaded. */
const rowsOf = (page, column) => page.evaluate((name) => {
    const index = [...document.querySelectorAll('table.lt-dt thead th')].findIndex((c) => c.dataset.ltName === name);
    return [...document.querySelectorAll('table.lt-dt tbody tr[data-id]')].map((row) => (row.children[index]?.textContent ?? '').trim());
}, column);

/** Waits until the table has finished a (re)load. */
async function loaded(page) {
    await page.waitForFunction(() => {
        const table = document.querySelector('[data-lt-table]');
        const processing = document.querySelector('div.dt-processing');
        return table && (!processing || getComputedStyle(processing).display === 'none');
    });
    await page.waitForTimeout(150);
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

    await toggleTheme(page);
    await page.waitForTimeout(800);
    s = await state(page);
    expect(s.theme === 'sap_horizon_dark' && s.ui5Theme === 'sap_horizon_dark', `toggle → sap_horizon_dark (${s.ui5Theme})`);
    expect(s.background !== lightBackground, `page background follows the theme (${lightBackground} → ${s.background})`);
    await page.reload({ waitUntil: 'networkidle' });
    s = await state(page);
    expect(s.ui5Theme === 'sap_horizon_dark', 'dark theme remembered after reload');
    await toggleTheme(page);
    await page.waitForTimeout(500);
    expect((await state(page)).ui5Theme === 'sap_horizon', 'toggle back → sap_horizon');

    console.log('Shell and launchpad:');
    await page.goto(BASE_URL + '/', { waitUntil: 'networkidle' });
    const tiles = await page.locator('ui5-card.lt-tile').evaluateAll((els) => els.map((el) => `${el.dataset.tile}:${el.dataset.state}`));
    const rank = { Negative: 0, Critical: 1, Information: 2, None: 3 };
    const todo = await page.locator('[data-group="todo"] ui5-card.lt-tile').evaluateAll((els) => els.map((el) => el.dataset.state));
    expect(tiles.length >= 10, `admin sees ${tiles.length} tiles`);
    expect(todo.every((s, i) => i === 0 || rank[todo[i - 1]] <= rank[s]), `exceptions first (${todo.join(', ')})`);
    expect(await page.locator('ui5-card.lt-tile[data-tile="retention"]').count() === 1, 'administration tile visible for the admin');
    const badge = await page.locator('#lt-shellbar').getAttribute('notifications-count');
    expect(badge !== null, `notification badge set on the ShellBar ("${badge}")`);

    await page.locator('ui5-card.lt-tile[data-tile="to_assign"] ui5-card-header').click();
    await page.waitForURL('**/mails?direction=incoming&status=registered');
    await page.waitForSelector('table.lt-dt tbody tr[data-id]');
    expect(await page.locator('#f-status').evaluate((el) => el.value) === 'registered', 'a tile opens the list with its filter applied');
    const statuses = await rowsOf(page, 'status');
    expect(statuses.length > 0 && new Set(statuses).size === 1, `list filtered by status (${statuses.length} rows)`);

    await page.goto(BASE_URL + '/', { waitUntil: 'networkidle' });
    await page.locator('ui5-card.lt-tile[data-tile="mails"] ui5-card-header .ui5-card-header-focusable-element').focus();
    await page.keyboard.press('Enter');
    await page.waitForURL('**/mails');
    expect(true, 'a tile opens with the keyboard (Enter)');

    const search = page.locator('ui5-shellbar-search');
    await page.locator('#lt-shellbar').evaluate((bar) => { bar.showSearchField = true; });
    await search.locator('input').fill('ENT-2026');
    await search.locator('input').press('Enter');
    await page.waitForURL('**/mails?q=ENT-2026');
    await page.waitForSelector('table.lt-dt tbody tr[data-id]');
    expect(await page.locator('#f-search').evaluate((el) => el.value) === 'ENT-2026', 'global search opens the list filtered by the term');

    await page.locator('#lt-profile').click();
    await page.locator('ui5-user-menu-item[data-lt-locale]').first().locator('xpath=..').click();
    await page.locator('ui5-user-menu-item[data-lt-locale="en"]').click();
    await page.waitForLoadState('networkidle');
    expect(await page.locator('html').getAttribute('lang') === 'en', 'language switched to English from the profile menu');
    expect((await state(page)).language === 'en', 'UI5 follows the page language');
    await page.locator('#lt-profile').click();
    await page.locator('ui5-user-menu-item[data-lt-locale]').first().locator('xpath=..').click();
    await page.locator('ui5-user-menu-item[data-lt-locale="fr"]').click();
    await page.waitForLoadState('networkidle');

    const agentContext = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'fr-FR' });
    const agentPage = await open(agentContext, 'agent');
    const agentTiles = await agentPage.locator('ui5-card.lt-tile').evaluateAll((els) => els.map((el) => el.dataset.tile));
    expect(!agentTiles.includes('to_assign') && !agentTiles.includes('retention') && !agentTiles.includes('processing'),
        `agent sees only its tiles (${agentTiles.join(', ')})`);
    await agentPage.locator('#lt-profile').click();
    await agentPage.locator('ui5-user-menu').evaluate((menu) => menu.dispatchEvent(new CustomEvent('sign-out-click')));
    await agentPage.waitForURL('**/login');
    expect(true, 'sign out from the profile menu');

    console.log('Object Page (mail):');
    const opContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
    const op = await open(opContext, 'secretariat');
    const mailId = await op.evaluate(() => fetch('/mails/data?status=in_progress&direction=incoming&per_page=1', { headers: { Accept: 'application/json' } })
        .then((r) => r.json()).then((j) => j.data[0].id));
    const settled = async () => { await op.waitForLoadState('networkidle'); await op.waitForFunction(() => document.documentElement.classList.contains('lt-ui5-ready')); await op.waitForTimeout(400); };
    await op.goto(`${BASE_URL}/mails/${mailId}`, { waitUntil: 'networkidle' });
    await settled();
    expect(await op.locator('ui5-breadcrumbs-item').first().getAttribute('href') === '/mails', 'breadcrumb back to the list');
    expect(await op.locator('.lt-kpi').count() === 4, 'four key figures in the header');
    expect(await op.locator('ui5-dynamic-page-title ui5-tag').count() >= 3, 'status shown as semantic tags in the title');
    expect(await op.locator('ui5-bar[slot="footerArea"] [data-lt-action]').count() >= 1, 'main actions in the footer');
    expect(await op.locator('#general ui5-input, #general ui5-select').count() === 0, 'display mode by default');

    // Header snaps while scrolling; anchor bar scrolls to a section
    expect(await op.locator('#mail-page').evaluate((p) => p.headerSnapped) === false, 'header expanded at the top');
    await op.locator('ui5-tab[data-target="history"]').evaluate((tab) => {
        tab.closest('ui5-tabcontainer').dispatchEvent(new CustomEvent('tab-select', { detail: { tab } }));
    });
    await op.waitForTimeout(1200);
    expect(await op.locator('#history').evaluate((s) => { const r = s.getBoundingClientRect(); return r.top >= 0 && r.top < window.innerHeight * 0.6; }), 'an anchor scrolls to its section');
    expect(await op.locator('#mail-page').evaluate((p) => p.headerSnapped) === true, 'the header snaps when the page scrolls');
    expect(await op.locator('ui5-bar[slot="footerArea"]').isVisible(), 'the footer stays visible');
    const anchor = await op.evaluate(() => {
        const bar = document.querySelector('.lt-anchor-bar').getBoundingClientRect();
        const title = document.querySelector('#mail-page').shadowRoot.querySelector('[class*="title-header-wrapper"]').getBoundingClientRect();
        return { below: bar.top >= title.bottom - 1, selected: document.querySelector('ui5-tab[selected]')?.dataset.target };
    });
    expect(anchor.below, 'the anchor bar stays visible under the snapped header');
    expect(await op.evaluate(() => document.documentElement.scrollTop === 0 && document.documentElement.scrollHeight <= innerHeight), 'only the page content scrolls, not the window');

    // Section action in a dialog: annotation
    const notes = await op.locator('#annotations ui5-li-custom').count();
    await op.locator('[data-lt-open-dialog="dlg-annotate"]').click();
    await op.locator('#dlg-annotate [data-lt-dialog-submit]').click();
    expect(await op.locator('#note-body').evaluate((el) => el.valueState) === 'Negative', 'an empty required field is flagged in the dialog');
    await op.locator('#note-body').evaluate((el) => { el.value = 'Contrôle automatique : annotation.'; });
    await op.locator('#dlg-annotate [data-lt-dialog-submit]').click();
    await op.waitForURL(/#annotations$/);
    await settled();
    expect(await op.locator('#annotations ui5-li-custom').count() === notes + 1, 'annotation added from its dialog');

    // Upload through ui5-file-uploader (form-associated)
    const files = await op.locator('#attachments ui5-li-custom').count();
    await op.locator('[data-lt-open-dialog="dlg-upload"]').click();
    await op.locator('#file input[type="file"]').setInputFiles({ name: 'controle.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF') });
    await op.locator('#dlg-upload [data-lt-dialog-submit]').click();
    await op.waitForURL(/\/mails\/\d+(#.*)?$/);
    await settled();
    expect(await op.locator('#attachments ui5-li-custom').count() === files + 1, 'attachment uploaded from its dialog');
    expect((await op.locator('.lt-kpi[data-kpi="attachments"] ui5-title').textContent()).trim() === String(files + 1), 'the key figure follows');

    // Edit mode, validation errors in the message popover, link to the field
    await op.locator('ui5-toolbar-button[data-lt-href$="/edit"]').click();
    await op.waitForURL(/\/edit$/);
    await settled();
    expect(await op.locator('#general ui5-input#subject').count() === 1 && await op.locator('[data-lt-open-dialog]').count() === 0, '"Modifier" switches the page to edit mode');
    const subject = await op.locator('#subject').evaluate((el) => el.value);
    await op.locator('#subject').evaluate((el) => { el.value = ''; });
    await op.locator('#due_date').evaluate((el) => { el.value = '2000-01-01'; });
    await op.locator('[data-lt-submit="mail-form"]').click();
    await op.waitForFunction(() => document.querySelector('[data-lt-messages]')?.open === true);
    const messages = await op.locator('[data-lt-messages] ui5-li').evaluateAll((items) => items.map((i) => i.getAttribute('data-lt-focus')));
    expect(messages.includes('subject'), `errors listed in the message popover (${messages.join(', ')})`);
    expect(await op.locator('#subject').evaluate((el) => el.valueState) === 'Negative', 'the field in error is flagged');
    await op.locator('[data-lt-messages] ui5-li[data-lt-focus="subject"]').click();
    await op.waitForTimeout(700);
    expect(await op.evaluate(() => document.activeElement?.id) === 'subject', 'a message leads to its field');
    await op.locator('#subject').evaluate((el, value) => { el.value = value + ' (vérifié)'; }, subject);
    await op.locator('#due_date').evaluate((el) => { el.value = ''; });
    await op.locator('[data-lt-submit="mail-form"]').click();
    await op.waitForURL(/\/mails\/\d+$/);
    await settled();
    expect((await op.locator('ui5-title[slot="heading"]').textContent()).includes('(vérifié)'), 'saved from the footer, back in display mode');

    // Correspondent suggestions in edit mode
    await op.goto(`${BASE_URL}/mails/${mailId}/edit`, { waitUntil: 'networkidle' });
    await settled();
    await op.locator('#correspondent_id input').fill('');
    await op.locator('#correspondent_id input').pressSequentially('Conseil', { delay: 40 });
    await op.waitForSelector('#correspondent_id ui5-suggestion-item');
    expect(await op.locator('#correspondent-value').inputValue() === '', 'typing drops the previous correspondent');
    const pick = await op.locator('#correspondent_id ui5-suggestion-item').first().evaluate((item) => {
        item.closest('ui5-input').dispatchEvent(new CustomEvent('selection-change', { detail: { item } }));
        return item.getAttribute('data-id');
    });
    expect(await op.locator('#correspondent-value').inputValue() === pick, 'choosing a suggestion sets the correspondent');

    // Footer action with confirmation
    await op.goto(`${BASE_URL}/mails/${mailId}`, { waitUntil: 'networkidle' });
    await settled();
    await op.locator('[data-lt-action="wf-close"]').click();
    expect(await op.locator('#lt-confirm').evaluate((d) => d.open && d.state === 'Critical' && d.headerText === 'Clôturer'), 'closing is confirmed in a dialog');
    expect(await op.evaluate(() => document.activeElement?.id) === 'lt-confirm-cancel', 'initial focus on Cancel');
    await op.locator('#lt-confirm-input').evaluate((el) => { el.value = 'Contrôle automatique'; });
    await op.locator('[data-lt-confirm-ok]').click();
    await op.waitForURL(/\/mails\/\d+(#.*)?$/);
    await settled();
    expect((await op.locator('div[slot="subheading"] ui5-tag').allTextContents()).some((text) => text.trim() === 'Clos'), 'the mail is closed, with its comment in the history');

    // Correspondent Object Page (creation)
    await op.goto(`${BASE_URL}/correspondents/new`, { waitUntil: 'networkidle' });
    await settled();
    await op.locator('[data-lt-submit="correspondent-form"]').click();
    await op.waitForFunction(() => document.querySelector('[data-lt-messages]')?.open === true);
    expect(await op.locator('[data-lt-messages] ui5-li[data-lt-focus="name"]').count() === 1, 'correspondent page: errors in the message popover');

    console.log('Overview Page:');
    const ov = await open(await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' }), 'head');
    const ovReady = async () => { await ov.waitForLoadState('networkidle'); await ov.waitForFunction(() => document.documentElement.classList.contains('lt-ui5-ready')); await ov.waitForTimeout(500); };
    await ov.goto(BASE_URL + '/', { waitUntil: 'networkidle' });
    await ovReady();
    await ov.locator('ui5-card.lt-tile[data-tile="overview"] ui5-card-header').click();
    await ov.waitForURL('**/overview');
    await ovReady();
    expect(true, 'the Launchpad tile of the steering group opens the overview');
    const cards = await ov.locator('.lt-ov-card').evaluateAll((els) => els.map((el) => el.dataset.card));
    expect(cards.filter((c) => c === 'kpi').length === 4 && cards.filter((c) => c === 'list').length === 2 && cards.filter((c) => c === 'chart').length === 2,
        `KPI, list and chart cards (${cards.join(', ')})`);
    const states = await ov.locator('.lt-ov-card--kpi').evaluateAll((els) => els.map((el) => [el.dataset.state, el.querySelector('ui5-tag').design, getComputedStyle(el.querySelector('.lt-ov-kpi__value')).color]));
    expect(states.every(([state, design]) => state === design), `each indicator carries its semantic state (${states.map((x) => x[0]).join(', ')})`);
    const chartColours = () => ov.evaluate(() => [...document.querySelectorAll('canvas[data-lt-ov-chart]')].map((canvas) => {
        const chart = window.Chart.getChart(canvas);
        return chart ? chart.data.datasets.map((d) => d.backgroundColor) : null;
    }));
    const themeColours = () => ov.evaluate(() => { const st = getComputedStyle(document.documentElement); return ['--sapChart_OrderedColor_1', '--sapChart_OrderedColor_2', '--sapChart_Bad'].map((v) => st.getPropertyValue(v).trim()); });
    let drawn = await chartColours();
    let theme = await themeColours();
    expect(drawn.length === 2 && drawn.every(Boolean), 'both charts are drawn');
    expect(drawn[0][0] === theme[0] && drawn[0][1] === theme[1] && drawn[1][0] === theme[2], `chart colours come from the theme (${theme.join(', ')})`);
    const lightColour = theme[0];
    await ov.evaluate(() => window.LT.theme.toggle());
    await ov.waitForTimeout(1500);
    drawn = await chartColours();
    theme = await themeColours();
    expect(theme[0] !== lightColour && drawn[0][0] === theme[0], `charts are redrawn with the colours of the new theme (${lightColour} → ${theme[0]})`);
    await ov.evaluate(() => window.LT.theme.toggle());
    await ov.waitForTimeout(800);
    const kpiSpan = await ov.locator('.lt-ov-card--kpi').first().evaluate((el) => getComputedStyle(el).gridColumnStart);
    expect(kpiSpan === 'span 3', `four KPI cards on one row on a desktop (${kpiSpan})`);
    await ov.locator('.lt-ov-card[data-key="overdue"][data-card="list"] ui5-li').first().click();
    await ov.waitForURL(/\/mails\/\d+$/);
    expect(true, 'a row of a list card opens the mail');
    await ov.goto(BASE_URL + '/overview', { waitUntil: 'networkidle' });
    await ovReady();
    await ov.locator('.lt-ov-card[data-key="overdue"][data-card="kpi"] ui5-card-header').click();
    await ov.waitForURL('**/mails?overdue=1');
    expect(true, 'a KPI card opens the detailed application, filtered');
    const phoneOv = await open(await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR' }), 'head');
    await phoneOv.goto(BASE_URL + '/overview', { waitUntil: 'networkidle' });
    await phoneOv.waitForTimeout(800);
    expect(await phoneOv.locator('.lt-ov-card').evaluateAll((els) => els.every((el) => getComputedStyle(el).gridColumnStart === 'span 12')), 'cards stacked on a phone');

    const darkStats = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
    await darkStats.addInitScript(() => { try { localStorage.setItem('lt.theme', 'dark'); } catch { /* ignore */ } });
    const stats = await open(darkStats, 'management');
    await stats.goto(BASE_URL + '/statistics', { waitUntil: 'networkidle' });
    await stats.waitForFunction(() => window.Chart && window.Chart.getChart(document.querySelector('canvas[data-chart="volumes"]')));
    const statsColours = await stats.evaluate(() => ({
        drawn: window.Chart.getChart(document.querySelector('canvas[data-chart="volumes"]')).data.datasets.map((d) => d.backgroundColor),
        theme: ['--sapChart_OrderedColor_1', '--sapChart_OrderedColor_2'].map((v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim()),
    }));
    expect(statsColours.drawn.join() === statsColours.theme.join() && statsColours.theme[0] !== lightColour,
        `statistics charts use the theme colours, dark theme at load included (${statsColours.drawn.join(', ')})`);

    console.log('Wizard and simple forms:');
    const wz = await open(await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' }), 'secretariat');
    const ready = async (p) => { await p.waitForLoadState('networkidle'); await p.waitForFunction(() => document.documentElement.classList.contains('lt-ui5-ready')); await p.waitForTimeout(400); };
    const step = () => wz.evaluate(() => document.querySelector('ui5-wizard-step[selected]')?.dataset.step);
    const next = async () => { await wz.locator('[data-lt-wizard-next]').click(); await wz.waitForTimeout(350); };
    await wz.goto(`${BASE_URL}/mails/new`, { waitUntil: 'networkidle' });
    await ready(wz);
    expect(await wz.locator('ui5-wizard-step').count() === 5 && await step() === 'identification', 'five numbered steps, the first one open');
    expect(await wz.locator('[data-lt-submit="mail-form"]').isHidden(), 'no Save before the review');
    await next();
    expect(await step() === 'identification' && await wz.locator('#subject').evaluate((el) => el.valueState) === 'Negative', 'a step with an empty required field does not let go');
    expect((await wz.locator('#subject [slot="valueStateMessage"]').textContent()) === 'Ce champ est obligatoire.', 'the error is shown in the field');
    await wz.locator('#subject input').fill('Demande de contrôle automatique');
    await wz.locator('#summary').evaluate((el) => { el.value = 'Résumé du contrôle.'; });
    await next();
    expect(await step() === 'correspondent', 'next step once the step is valid');
    await wz.locator('#correspondent_id input').pressSequentially('Conseil', { delay: 40 });
    await wz.waitForSelector('#correspondent_id ui5-suggestion-item');
    await next();
    expect(await step() === 'correspondent' && (await wz.locator('#correspondent_id [slot="valueStateMessage"]').textContent()).startsWith('Choisissez'), 'typed text is not enough: a suggestion must be chosen');
    const chosen = await wz.locator('#correspondent_id ui5-suggestion-item').first().evaluate((item) => {
        const input = item.closest('ui5-input');
        input.value = item.getAttribute('text');
        input.dispatchEvent(new CustomEvent('selection-change', { detail: { item } }));
        return item.getAttribute('text');
    });
    await next();
    expect(await step() === 'processing', 'correspondent chosen from the suggestions');
    await wz.locator('#priority').evaluate((el) => { el.value = 'high'; });
    await next();
    await wz.locator('#file input[type="file"]').setInputFiles({ name: 'scan-controle.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF') });
    await next();
    expect(await step() === 'review' && await wz.locator('[data-lt-submit="mail-form"]').isVisible() && await wz.locator('[data-lt-wizard-next]').isHidden(), 'review step: Save replaces Next');
    const summary = await wz.locator('[data-lt-summary]').evaluateAll((items) => Object.fromEntries(items.map((i) => [i.dataset.ltSummary, i.textContent.trim()])));
    expect(summary.subject === 'Demande de contrôle automatique' && summary.correspondent_id === chosen && summary.priority === 'Haute'
        && summary.file === 'scan-controle.pdf' && /^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/.test(summary.received_at),
        `review shows the values as displayed (${summary.priority}, ${summary.received_at}, ${summary.file})`);
    await wz.locator('[data-lt-wizard-previous]').click();
    await wz.waitForTimeout(300);
    expect(await step() === 'attachment', 'previous step');
    await wz.locator('ui5-wizard-step[data-step="review"]').evaluate((s) => { document.querySelectorAll('ui5-wizard-step').forEach((x) => { x.selected = x === s; }); s.closest('ui5-wizard').dispatchEvent(new CustomEvent('step-change', { detail: { step: s } })); });
    await wz.waitForTimeout(300);
    await wz.locator('[data-lt-submit="mail-form"]').click();
    await wz.waitForURL(/\/mails\/\d+$/);
    await ready(wz);
    expect((await wz.locator('ui5-title[slot="heading"]').textContent()).includes('Demande de contrôle automatique')
        && (await wz.locator('.lt-kpi[data-kpi="attachments"] ui5-title').textContent()).trim() === '1', 'mail registered with its scan');

    // Simple form: inline validation, value help on a long list
    await wz.goto(`${BASE_URL}/delegations`, { waitUntil: 'networkidle' });
    await ready(wz);
    const absences = await wz.locator('form[action$="/cancel"]').count();
    await wz.locator('[data-lt-submit="delegation-form"]').click();
    await wz.waitForTimeout(400);
    expect(wz.url().endsWith('/delegations') && await wz.locator('#delegate_id').evaluate((el) => el.valueState) === 'Negative', 'simple form: empty required field flagged without leaving the page');
    await wz.locator('#delegate_id input').pressSequentially('Mar', { delay: 40 });
    await wz.waitForTimeout(500);
    const filtered = await wz.locator('#delegate_id').evaluate((el) => [...el.querySelectorAll('ui5-cb-item')].length);
    await wz.locator('#delegate_id').evaluate((el) => { const item = [...el.querySelectorAll('ui5-cb-item')].find((i) => i.text.startsWith('Marc')); el.value = item.text; el.selectedValue = item.value; });
    const days = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
    await wz.locator('#starts_on').evaluate((el, v) => { el.value = v; }, days(40));
    await wz.locator('#ends_on').evaluate((el, v) => { el.value = v; }, days(45));
    await wz.locator('[data-lt-submit="delegation-form"]').click();
    await wz.waitForLoadState('networkidle');
    await ready(wz);
    expect(await wz.locator('form[action$="/cancel"]').count() === absences + 1, `absence saved from the UI5 form (combo box with ${filtered} people)`);

    console.log('Messages and confirmations (UI5 only):');
    expect(await wz.evaluate(() => typeof window.Swal === 'undefined'), 'SweetAlert2 is no longer loaded');
    await wz.locator('form[action$="/cancel"] button[type="submit"]').last().click();
    await wz.waitForSelector('ui5-dialog[open]');
    const confirmState = await wz.locator('ui5-dialog[open]').evaluate((d) => ({
        state: d.state, buttons: [...d.querySelectorAll('[slot="footer"] ui5-button')].map((b) => b.textContent.trim()), focus: document.activeElement?.textContent.trim(),
    }));
    expect(confirmState.state === 'Negative' && confirmState.buttons.length === 2 && confirmState.buttons[1] === 'Annuler' && confirmState.focus === 'Annuler',
        `destructive confirmation in a ui5-dialog: action first, Cancel last and focused (${confirmState.buttons.join(' / ')})`);
    await wz.locator('ui5-dialog[open] [slot="footer"] ui5-button').last().click();
    await wz.waitForTimeout(400);
    expect(await wz.locator('form[action$="/cancel"]').count() === absences + 1 && await wz.locator('ui5-dialog[open]').count() === 0, 'Cancel closes the dialog and nothing is submitted');
    await wz.locator('form[action$="/cancel"] button[type="submit"]').last().click();
    await wz.waitForSelector('ui5-dialog[open]');
    await wz.locator('ui5-dialog[open] [slot="footer"] ui5-button').first().click();
    await wz.waitForLoadState('networkidle');
    await ready(wz);
    expect(await wz.locator('form[action$="/cancel"]').count() === absences, 'confirming submits the form');
    await wz.evaluate(() => window.LT.ui.toast('Contrôle'));
    await wz.waitForTimeout(300);
    expect(await wz.locator('ui5-toast').evaluate((el) => el.open && el.textContent === 'Contrôle'), 'short messages use ui5-toast');
    const shown = wz.evaluate(() => window.LT.ui.error({ message: 'Refusé.', errors: { subject: ['Objet obligatoire.'] } }));
    await wz.waitForSelector('ui5-dialog[open]');
    expect(await wz.locator('ui5-dialog[open]').evaluate((d) => d.state === 'Negative' && d.headerText === 'Erreur' && d.querySelectorAll('ui5-li').length === 1), 'errors use a ui5-dialog with their details');
    await wz.locator('ui5-dialog[open] [slot="footer"] ui5-button').click();
    await shown;

    console.log('List Report (mail list):');
    const listContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', acceptDownloads: true });
    const list = await open(listContext, 'secretariat');
    await list.goto(BASE_URL + '/mails', { waitUntil: 'networkidle' });
    await list.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(list);
    const count = () => list.locator('[data-lt-count]').textContent();
    const totalTitle = await count();
    expect(/^Courriers \(\d+\)$/.test(totalTitle), `table title with counter (${totalTitle})`);
    expect(await list.locator('table.lt-dt tbody tr[data-id]').count() === 25, 'first page of 25 rows');
    expect((await rowsOf(list, 'status')).every((text) => text !== ''), 'statuses shown as tags');
    const designs = await list.locator('table.lt-dt tbody tr[data-id] ui5-tag').evaluateAll((tags) => [...new Set(tags.map((tag) => tag.design))]);
    expect(designs.every((d) => ['Information', 'Positive', 'Critical', 'Negative', 'Neutral', 'Set2'].includes(d)), `semantic tag designs (${designs.join(', ')})`);

    const firstReference = (await rowsOf(list, 'reference'))[0];
    await list.locator('.dt-paging button', { hasText: 'Suivante' }).click();
    await loaded(list);
    expect((await rowsOf(list, 'reference'))[0] !== firstReference && (await list.locator('.dt-info').textContent()).includes('26'), 'paging: the next page shows the following rows');
    await list.locator('table.lt-dt thead th[data-lt-name="subject"]').click();
    await loaded(list);
    const bySubject = await rowsOf(list, 'subject');
    expect(bySubject.join('|') === [...bySubject].sort((a, b) => a.localeCompare(b, 'fr')).join('|'), 'a click on a column header sorts the table');
    const rowHeight = await list.locator('table.lt-dt tbody tr[data-id]').first().evaluate((tr) => Math.round(tr.getBoundingClientRect().height));
    expect(rowHeight <= 34, `compact rows (${rowHeight} px)`);
    await list.locator('[data-lt-reset]').click();
    await loaded(list);

    // Filter bar: nothing happens until "Exécuter"
    await list.locator('#f-status').evaluate((el) => { el.value = 'closed'; });
    await list.waitForTimeout(400);
    expect(await count() === totalTitle, 'changing a filter does not reload the table');
    await list.locator('[data-lt-go]').click();
    await loaded(list);
    const closedTitle = await count();
    expect(closedTitle !== totalTitle && new Set(await rowsOf(list, 'status')).size === 1, `"Exécuter" applies the filters (${closedTitle})`);
    expect((await list.locator('[data-lt-variant-button]').textContent()).includes('modifiée'), 'the view is shown as modified');

    // Variants
    await list.locator('[data-lt-variant-button]').click();
    await list.locator('[data-lt-variant-save-as]').click();
    await list.locator('[data-lt-variant-name]').evaluate((el) => { el.value = 'Courriers clos'; });
    await list.locator('[data-lt-variant-default]').evaluate((el) => { el.checked = true; });
    await list.locator('[data-lt-variant-dialog] [data-lt-dialog-confirm]').click();
    await list.waitForTimeout(300);
    expect((await list.locator('[data-lt-variant-button]').textContent()).trim() === 'Courriers clos', 'view saved under a name');
    await list.locator('[data-lt-reset]').click();
    await loaded(list);
    expect(await count() === totalTitle && (await list.locator('[data-lt-variant-button]').textContent()).trim() === 'Standard', 'reset goes back to Standard');
    await list.reload({ waitUntil: 'networkidle' });
    await list.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(list);
    expect(await count() === closedTitle && await list.locator('#f-status').evaluate((el) => el.value) === 'closed', 'the default view is applied on return');
    await list.locator('[data-lt-variant-button]').click();
    await list.locator('[data-lt-variant-list] ui5-li').first().evaluate((item) => {
        item.closest('ui5-list').dispatchEvent(new CustomEvent('item-delete', { detail: { item } }));
    });
    await list.keyboard.press('Escape');
    await list.locator('[data-lt-reset]').click();
    await loaded(list);
    expect(await list.evaluate(() => JSON.parse(localStorage.getItem('lt.variants.mails')).variants.length) === 0, 'view deleted');

    // Sort and columns (table settings)
    await list.locator('[data-lt-settings]').click();
    await list.locator('[data-lt-sort-column]').evaluate((el) => { el.value = 'reference'; });
    await list.locator('[data-lt-sort-dir]').evaluate((el) => { el.value = 'asc'; });
    await list.locator('[data-lt-column-list] ui5-li[data-name="priority"]').evaluate((el) => { el.selected = false; });
    await list.locator('[data-lt-settings-dialog] [data-lt-dialog-confirm]').click();
    await loaded(list);
    const references = await rowsOf(list, 'reference');
    expect(references[0].endsWith('-00001'), `sorted by reference, ascending (${references[0]})`);
    expect(await list.locator('table.lt-dt thead th[data-lt-name="reference"]').evaluate((th) => th.classList.contains('dt-ordering-asc')), 'sort indicator on the column');
    expect(await list.locator('table.lt-dt thead th[data-lt-name="priority"]').count() === 0, 'a column can be hidden');
    await list.reload({ waitUntil: 'networkidle' });
    await list.waitForSelector('table.lt-dt tbody tr[data-id]');
    expect(await list.locator('table.lt-dt thead th[data-lt-name="priority"]').count() === 0, 'hidden columns are remembered on the device');
    await list.evaluate(() => localStorage.removeItem('lt.columns.mails'));

    // Empty state
    await list.locator('#f-search').evaluate((el) => { el.value = 'zzz-no-such-mail'; });
    await list.locator('[data-lt-go]').click();
    await loaded(list);
    expect(await count() === 'Courriers (0)' && await list.locator('table.lt-dt ui5-illustrated-message').isVisible(), 'empty state with an illustrated message');
    await list.locator('[data-lt-reset]').click();
    await loaded(list);

    // Selection, bulk actions, export
    expect(await list.locator('[data-lt-bulk="assign"]').evaluate((b) => b.disabled) === true, 'bulk actions disabled without selection');
    await list.goto(BASE_URL + '/mails?direction=incoming&status=registered', { waitUntil: 'networkidle' });
    await list.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(list);
    const toAssign = await list.locator('table.lt-dt tbody tr[data-id]').count();
    const keys = await list.locator('table.lt-dt tbody tr[data-id]').evaluateAll((rows) => rows.slice(0, 2).map((row) => row.getAttribute('data-id')));
    await list.locator('table.lt-dt tbody .lt-dt__check').nth(0).check();
    await list.locator('table.lt-dt tbody .lt-dt__check').nth(1).check();
    expect(await list.locator('table.lt-dt tbody tr.selected').count() === 2, 'selected rows are highlighted');
    expect(await list.locator('[data-lt-bulk="assign"]').evaluate((b) => b.disabled) === false, 'bulk actions enabled with a selection');

    const [download] = await Promise.all([list.waitForEvent('download'), list.locator('[data-lt-export="xlsx"]').click()]);
    expect(download.suggestedFilename().endsWith('.xlsx'), `export of the selection (${download.suggestedFilename()})`);
    const exported = await list.evaluate((ids) => fetch(`/mails/export?ids=${ids}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()), keys.join(','));
    expect(exported.data.length === 2, 'the export endpoint returns only the selected rows');

    await list.locator('[data-lt-bulk="assign"]').click();
    await list.locator('#mails-assign [data-lt-dialog-confirm]').click();
    await list.waitForTimeout(800);
    expect(await list.locator('#mails-assign [data-lt-dialog-error]').isVisible(), 'assigning to nobody is refused in the dialog');
    await list.locator('#assign-user').evaluate((el) => { el.value = [...el.querySelectorAll('ui5-option')].find((o) => o.textContent.includes('Agent')).getAttribute('value'); });
    await list.locator('#mails-assign [data-lt-dialog-confirm]').click();
    await list.waitForSelector('[data-lt-result]:not([hidden])');
    await loaded(list);
    const assigned = await list.locator('[data-lt-result]').textContent();
    expect(/^2 courrier/.test(assigned) && await list.locator('[data-lt-result]').getAttribute('design') === 'Positive', `bulk assign done (${assigned.trim()})`);
    expect(await list.locator('table.lt-dt tbody tr[data-id]').count() === toAssign - 2, 'the list is reloaded after a bulk action');

    // Bulk close: one closable mail and one already closed → partial result
    await list.goto(BASE_URL + '/mails', { waitUntil: 'networkidle' });
    await list.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(list);
    const mixed = await list.evaluate(async () => {
        const get = (status) => fetch(`/mails/data?status=${status}&per_page=1`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((j) => j.data[0].id);
        return [await get('in_progress'), await get('closed')];
    });
    const closeResult = await list.evaluate((ids) => new Promise((resolve) => {
        window.LT.api.post('/mails/bulk/close', { ids: ids.join(','), comment: 'Contrôle automatique' }).then(resolve, resolve);
    }), mixed);
    expect(closeResult.done.length === 1 && closeResult.failed.length === 1 && closeResult.failed[0].message !== '',
        `bulk close reports each refusal (${closeResult.failed[0]?.message})`);
    await list.locator('table.lt-dt tbody .lt-dt__check').first().check();
    await list.locator('[data-lt-bulk="close"]').click();
    expect(await list.locator('#mails-close').evaluate((d) => d.open && d.state === 'Critical'), 'closing asks for confirmation in a dialog');
    expect((await list.locator('#mails-close [data-lt-dialog-question]').textContent()).includes('1 courrier'), 'the dialog states how many mails are concerned');
    await list.locator('#mails-close [data-lt-dialog-cancel]').click();

    // Row click → Object Page
    await list.locator('table.lt-dt tbody tr[data-id]').first().locator('td').nth(3).click();
    await list.waitForURL(/\/mails\/\d+$/);
    expect(true, 'a click on a row opens the mail page');

    const agentList = await open(await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR' }), 'agent');
    await agentList.goto(BASE_URL + '/mails', { waitUntil: 'networkidle' });
    await agentList.waitForSelector('table.lt-dt tbody tr[data-id]');
    expect(await agentList.locator('[data-lt-bulk="assign"]').count() === 0 && await agentList.locator('[data-lt-href="/mails/new?direction=incoming"]').count() === 0,
        'an agent sees neither "assign" nor "create"');
    expect(await agentList.locator('ui5-dynamic-page').evaluate((p) => p.headerSnapped) === true, 'filter bar collapsed on a phone');

    console.log('List Report template (correspondents, register, retention rules):');
    const others = await open(await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', acceptDownloads: true }), 'secretariat');
    const countOf = (p) => p.locator('[data-lt-count]').textContent();
    const number = (title) => Number((/\((\d+)\)/.exec(title.replace(/\s/g, '')) || [0, -1])[1]);

    await others.goto(BASE_URL + '/correspondents', { waitUntil: 'networkidle' });
    await others.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(others);
    const activeCount = number(await countOf(others));
    expect(activeCount > 0 && (await rowsOf(others, 'type')).every((text) => text !== ''), `correspondents listed with their type as a tag (${activeCount})`);
    const names = await rowsOf(others, 'name');
    expect(names.join('|') === [...names].sort((a, b) => a.localeCompare(b, 'fr')).join('|'), 'correspondents sorted by name');
    await others.locator('[data-lt-filter="inactive"]').click();
    await others.locator('[data-lt-go]').click();
    await loaded(others);
    expect(number(await countOf(others)) > activeCount, 'the "inactive" filter adds the inactive correspondents');
    expect(await others.locator('[data-lt-export]').count() === 0 && await others.locator('.lt-dt__check').count() === 0, 'no export and no selection where the screen has none');
    await others.locator('table.lt-dt tbody tr[data-id]').first().locator('td').nth(2).click();
    await others.waitForURL(/\/correspondents\/\d+\/edit$/);
    expect(true, 'a click on a row opens the correspondent');

    await others.goto(BASE_URL + '/register', { waitUntil: 'networkidle' });
    await loaded(others);
    const month = new Date().toLocaleDateString('fr-CA', { timeZone: 'Europe/Paris' }).slice(0, 8) + '01';
    expect(await others.locator('#f-direction').evaluate((el) => el.value) === 'incoming' && await others.locator('#f-from').evaluate((el) => el.value) === month,
        'register opens on the incoming mail of the current month');
    expect((await others.locator('[data-lt-variant-button]').textContent()).trim() === 'Standard', 'the default period is the standard view, not a modified one');
    await others.locator('#f-from').evaluate((el) => { el.value = new Date(Date.now() - 300 * 86400000).toISOString().slice(0, 10); });
    await others.locator('[data-lt-go]').click();
    await others.waitForSelector('table.lt-dt tbody tr[data-id]');
    await loaded(others);
    const registered = await rowsOf(others, 'reference');
    expect(registered.every((reference) => reference.startsWith('ENT-')) && registered.join('|') === [...registered].sort().join('|'),
        `register rows of one direction, in registration order (${registered[0]}…)`);
    const [registerFile] = await Promise.all([others.waitForEvent('download'), others.locator('[data-lt-export="xlsx"]').click()]);
    expect(/registre/i.test(registerFile.suggestedFilename()) && registerFile.suggestedFilename().endsWith('.xlsx'), `register exported as a document (${registerFile.suggestedFilename()})`);
    await others.locator('[data-lt-reset]').click();
    await loaded(others);
    expect(await others.locator('#f-from').evaluate((el) => el.value) === month, 'reset goes back to the current month');

    const rulesPage = await open(await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' }), 'admin');
    await rulesPage.goto(BASE_URL + '/retention-rules', { waitUntil: 'networkidle' });
    const ruleRows = await rulesPage.locator('table.lt-dt tbody tr').count();
    expect(ruleRows > 0 && await rulesPage.locator('table.lt-dt tbody ui5-tag').count() >= ruleRows, `retention rules in the compact table, state as a tag (${ruleRows})`);
    await rulesPage.locator('[data-lt-open-dialog="retention-new"]').click();
    expect(await rulesPage.locator('#retention-new').evaluate((d) => d.open), 'a rule is created in a dialog opened from the table toolbar');
    await rulesPage.locator('#retention-new [data-lt-dialog-submit]').click();
    expect(await rulesPage.locator('#retention-new').evaluate((d) => d.open) && await rulesPage.locator('#name').evaluate((el) => el.valueState) === 'Negative',
        'an empty required field is flagged in the dialog');
    await rulesPage.locator('#retention-new [data-lt-dialog-cancel]').click();
    await rulesPage.locator('table.lt-dt tbody ui5-button').first().click();
    await rulesPage.waitForTimeout(500);
    const asked = await rulesPage.evaluate(() => [...document.querySelectorAll('ui5-dialog')].filter((d) => d.open).map((d) => d.id || d.headerText));
    expect(asked.length === 1 && rulesPage.url().endsWith('/retention-rules'), `switching a rule off asks for confirmation (${asked.join(', ')} — ${rulesPage.url()})`);

    console.log('Side navigation:');
    const navPage = await open(await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' }), 'secretariat');
    await navPage.goto(BASE_URL + '/mails?direction=incoming&status=registered', { waitUntil: 'networkidle' });
    const navState = () => navPage.evaluate(() => ({
        collapsed: document.getElementById('lt-layout').isSideCollapsed(),
        selected: [...document.querySelectorAll('#lt-sidenav [selected]')].map((item) => item.getAttribute('data-label') || item.getAttribute('text')),
        toAssign: document.querySelector('#lt-sidenav [data-count="unassigned"]').getAttribute('text'),
        fixed: [...document.querySelectorAll('#lt-sidenav [slot="fixedItems"]')].map((item) => item.getAttribute('text')),
    }));
    let nav = await navState();
    expect(nav.selected.join() === 'À affecter', `the shortcut that matches the address is selected (${nav.selected.join()})`);
    expect(/^À affecter \(\d+\)$/.test(nav.toAssign), `counter on the shortcut (${nav.toAssign})`);
    expect(nav.fixed.join() === 'Absences', `personal settings pinned at the bottom (${nav.fixed.join()})`);
    await navPage.locator('[data-lt-toggle="nav"]').click();
    await navPage.goto(BASE_URL + '/register', { waitUntil: 'networkidle' });
    nav = await navState();
    expect(nav.collapsed === true, 'a collapsed navigation stays collapsed on the next screen');
    await navPage.locator('[data-lt-toggle="nav"]').click();
    await navPage.goto(BASE_URL + '/', { waitUntil: 'networkidle' });
    expect((await navState()).collapsed === false, 'and stays expanded once reopened');
    const mailEntry = navPage.locator('#lt-sidenav ui5-side-navigation-item[unselectable]');
    await mailEntry.click({ position: { x: 60, y: 16 } });
    await navPage.waitForTimeout(600);
    expect(new URL(navPage.url()).pathname === '/' && await mailEntry.evaluate((item) => item.expanded) === false, 'closing the shortcuts of "Courrier" stays on the screen');
    await mailEntry.click({ position: { x: 60, y: 16 } });
    await navPage.locator('#lt-sidenav ui5-side-navigation-sub-item[data-lt-nav-query=""]').click();
    await navPage.waitForURL('**/mails');
    expect((await navState()).selected.join() === 'Tous les courriers', '"Tous les courriers" opens the whole list and takes the selection');
    await navPage.locator('#lt-sidenav ui5-side-navigation-item[design="Action"]').click();
    await navPage.waitForURL('**/mails/new');
    expect(true, '"Enregistrer un courrier" opens the registration from any screen');

    console.log('Touch screen:');
    const touch = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR' });
    const phone = await open(touch, 'agent');
    s = await state(phone);
    expect(s.density === 'cozy' && !s.compact, 'cozy density on a touch screen');
    const columns = await phone.locator('.lt-tiles').first().evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    expect(columns === 1, `tiles stacked in one column on a phone (${columns})`);
    const tabletContext = await browser.newContext({ viewport: { width: 820, height: 1100 }, hasTouch: true, locale: 'fr-FR' });
    const tablet = await open(tabletContext, 'agent');
    const tabletColumns = await tablet.locator('.lt-tiles').first().evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    expect(tabletColumns >= 2 && tabletColumns <= 3, `tiles reflow on a tablet (${tabletColumns} columns)`);

    console.log('High contrast:');
    const contrast = await browser.newContext({ forcedColors: 'active', locale: 'fr-FR' });
    const hc = await open(contrast, 'agent');
    s = await state(hc);
    expect(s.ui5Theme === 'sap_horizon_hcw', `forced colours → sap_horizon_hcw (${s.ui5Theme})`);

    console.log('Printable slip (theme locked):');
    await toggleTheme(page);
    await page.goto(`${BASE_URL}/mails`, { waitUntil: 'networkidle' });
    await page.waitForSelector('table.lt-dt tbody tr[data-id]');
    const mail = await page.locator('table.lt-dt tbody tr[data-id]').first().getAttribute('data-id');
    await page.goto(`${BASE_URL}/mails/${mail}/slip`, { waitUntil: 'networkidle' });
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
