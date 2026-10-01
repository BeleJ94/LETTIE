// Shared helpers for the dev-only Playwright scripts: demo database, PHP server, browser, login.
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
export const PORT = Number(process.env.DEMO_PORT || 8765);
export const BASE_URL = `http://127.0.0.1:${PORT}`;

/** PHP CLI: $PHP, then XAMPP's default location, then "php" on PATH. */
export function phpBinary() {
    if (process.env.PHP) return process.env.PHP;
    const xampp = 'C:\\xampp\\php\\php.exe';
    return existsSync(xampp) ? xampp : 'php';
}

/** (Re)creates the demo database through tools/demo/seed.php; returns tools/demo/demo.json. */
export function seedDemo() {
    console.log('• Demo database: seeding…');
    const run = spawnSync(phpBinary(), [join(ROOT, 'tools/demo/seed.php')], { cwd: ROOT, stdio: 'inherit' });
    if (run.status !== 0) {
        throw new Error('tools/demo/seed.php failed (is MariaDB running?)');
    }
    return readDemo();
}

export function readDemo() {
    return JSON.parse(readFileSync(join(ROOT, 'tools/demo/demo.json'), 'utf8'));
}

/** Starts PHP's built-in server on the demo database (never the .env database). */
export async function startServer(demo) {
    const env = {
        ...process.env,
        DB_NAME: demo.database,
        STORAGE_PATH: demo.storage,
        APP_BASE_PATH: '',
        APP_ENV: 'demo',
        APP_DEBUG: 'false',
        APP_LOCALE: 'fr',
        APP_TIMEZONE: 'Europe/Paris',
        SESSION_SECURE: 'false',
    };
    const server = spawn(phpBinary(), ['-S', `127.0.0.1:${PORT}`, '-t', 'public', 'tools/demo/router.php'], { cwd: ROOT, env, stdio: 'ignore' });
    for (let i = 0; i < 60; i++) {
        try {
            const res = await fetch(`${BASE_URL}/login`);
            if (res.ok) return server;
        } catch {
            // not listening yet
        }
        await new Promise((r) => setTimeout(r, 250));
    }
    server.kill();
    throw new Error(`The PHP server did not start on ${BASE_URL}`);
}

/** Installed Edge (no download needed), else Playwright's Chromium. */
export async function launchBrowser() {
    const channel = process.env.PW_CHANNEL ?? 'msedge';
    try {
        return await chromium.launch(channel ? { channel } : {});
    } catch (error) {
        try {
            return await chromium.launch();
        } catch {
            throw new Error(`No browser available (${error.message.split('\n')[0]}). Install Edge, or run: npx playwright install chromium`);
        }
    }
}

export async function login(page, email, password) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#email', email);
    await page.fill('#password', password);
    // The login form's own button: the top bar also has a submit button (language switch).
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('.lt-login-submit')]);
}

/** Waits for icons, tables and charts to settle before a screenshot. */
export async function settle(page, extra = 400) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(extra);
}
