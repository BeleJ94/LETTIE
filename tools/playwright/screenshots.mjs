// npm run screenshots — screenshots of every screen, on the demo database, for the user guide.
//   Options: --no-seed (reuse the existing demo database)
// Output: docs/guide/screenshots/*.png
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { BASE_URL, ROOT, launchBrowser, login, readDemo, seedDemo, settle, startServer } from './demo.mjs';

export const SHOTS_DIR = join(ROOT, 'docs/guide/screenshots');

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

/**
 * Every screenshot: file name, account, viewport, and what to do before the capture.
 * Kept in the order of the guide.
 */
function plan(demo) {
    const mail = demo.showcaseMailId;
    return [
        { file: '01-connexion', as: null, go: '/login' },
        { file: '02-tableau-de-bord', as: 'secretariat', go: '/' },
        { file: '03-liste-courrier', as: 'secretariat', go: '/mails', wait: 'table.dataTable tbody tr' },
        {
            file: '04-nouveau-courrier', as: 'secretariat', go: '/mails/new?direction=incoming',
            before: async (page) => {
                await page.fill('#subject', 'Demande d\'autorisation de voirie — rue des Tilleuls');
                await page.fill('[data-lt-picker-input]', 'Mairie');
                await page.waitForSelector('.lt-picker__option');
            },
        },
        { file: '05-fiche-courrier', as: 'head', go: `/mails/${mail}` },
        {
            file: '06-cloture-confirmation', as: 'head', go: `/mails/${mail}`,
            before: async (page) => {
                await page.click('form[action$="/actions/close"] button');
                await page.waitForSelector('.swal2-popup', { state: 'visible' });
                await page.fill('.swal2-textarea', 'Avis favorable transmis au bureau communautaire.');
            },
        },
        { file: '07-bordereau', as: 'secretariat', go: `/mails/${mail}/slip`, fullPage: true },
        { file: '08-reponse', as: 'secretariat', go: `/mails/new?reply_to=${mail}` },
        { file: '09-absences', as: 'agent2', go: '/delegations' },
        { file: '10-notifications', as: 'agent', go: '/notifications' },
        { file: '11-registre', as: 'secretariat', go: '/register' },
        { file: '12-statistiques', as: 'management', go: '/statistics', wait: 'canvas[data-chart="volumes"]', extra: 1200, fullPage: true },
        { file: '13-conservation', as: 'admin', go: '/retention-rules' },
        { file: '14-mobile-accueil', as: 'agent', go: '/', viewport: MOBILE },
        { file: '15-mobile-liste', as: 'agent', go: '/mails', viewport: MOBILE, wait: 'table.dataTable tbody tr' },
        { file: '16-theme-sombre', as: 'agent', go: '/mails', theme: 'dark', wait: 'table.dataTable tbody tr' },
    ];
}

export async function takeScreenshots({ seed = true } = {}) {
    const demo = seed ? seedDemo() : readDemo();
    mkdirSync(SHOTS_DIR, { recursive: true });
    const server = await startServer(demo);
    const browser = await launchBrowser();
    const contexts = new Map();
    const files = [];

    try {
        for (const shot of plan(demo)) {
            const viewport = shot.viewport ?? DESKTOP;
            const key = `${shot.as}|${viewport.width}|${shot.theme ?? 'light'}`;
            if (!contexts.has(key)) {
                const context = await browser.newContext({
                    viewport,
                    locale: 'fr-FR',
                    timezoneId: 'Europe/Paris',
                    deviceScaleFactor: viewport === MOBILE ? 2 : 1,
                    isMobile: viewport === MOBILE,
                    hasTouch: viewport === MOBILE,
                });
                // Light/dark is a per-device preference stored by lt-theme.js; density follows the pointer (touch: cozy).
                await context.addInitScript((theme) => { try { localStorage.setItem('lt.theme', theme); } catch { /* ignore */ } }, shot.theme ?? 'light');
                const page = await context.newPage();
                page.on('pageerror', (e) => console.warn(`  ! JS error on ${shot.file}: ${e.message}`));
                if (shot.as) {
                    await login(page, demo.accounts[shot.as], demo.password);
                }
                contexts.set(key, { context, page });
            }
            const { page } = contexts.get(key);
            await page.goto(BASE_URL + shot.go);
            await settle(page);
            if (shot.wait) await page.waitForSelector(shot.wait);
            if (shot.before) await shot.before(page);
            await settle(page, shot.extra ?? 400);
            const path = join(SHOTS_DIR, `${shot.file}.png`);
            await page.screenshot({ path, fullPage: !!shot.fullPage });
            files.push(path);
            console.log(`  ✓ ${shot.file}.png`);
        }
    } finally {
        for (const { context } of contexts.values()) await context.close();
        await browser.close();
        server.kill();
    }
    return files;
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    takeScreenshots({ seed: !process.argv.includes('--no-seed') })
        .then((files) => console.log(`${files.length} screenshots in docs/guide/screenshots/`))
        .catch((error) => { console.error(error.message); process.exit(1); });
}
