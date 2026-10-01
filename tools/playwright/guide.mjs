// npm run guide — user guide: fresh screenshots, then docs/guide/index.html and the PDF.
//   Options: --skip-screenshots (reuse docs/guide/screenshots), --no-seed (reuse the demo database)
import { readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { pathToFileURL } from 'node:url';
import { ROOT, launchBrowser } from './demo.mjs';
import { takeScreenshots } from './screenshots.mjs';

const GUIDE_DIR = join(ROOT, 'docs/guide');
const HTML_OUT = join(GUIDE_DIR, 'index.html');
const PDF_OUT = join(GUIDE_DIR, 'Lettie-guide-utilisateur.pdf');

const args = process.argv.slice(2);

async function main() {
    if (!args.includes('--skip-screenshots')) {
        console.log('• Screenshots');
        await takeScreenshots({ seed: !args.includes('--no-seed') });
    }

    console.log('• HTML');
    const date = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Paris' }).format(new Date());
    const toGuide = (p) => relative(GUIDE_DIR, join(ROOT, p)).split('\\').join('/');
    const html = readFileSync(join(ROOT, 'tools/guide/guide.html'), 'utf8')
        .replaceAll('{{date}}', date)
        .replaceAll('{{fonts}}', toGuide('public/assets/vendor/ibm-plex-sans-5.1.0'))
        .replaceAll('{{hero}}', toGuide('public/assets/img/login-hero.svg'));
    writeFileSync(HTML_OUT, html);

    console.log('• PDF');
    const browser = await launchBrowser();
    try {
        const page = await browser.newPage();
        await page.goto(pathToFileURL(HTML_OUT).href, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const footer = `<div style="width:100%;font-size:7.5pt;color:#5b6573;padding:0 15mm;display:flex;justify-content:space-between;font-family:sans-serif">
            <span>Lettie — Guide utilisateur — ${date}</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;
        await page.pdf({
            path: PDF_OUT,
            format: 'A4',
            printBackground: true,
            displayHeaderFooter: true,
            headerTemplate: '<span></span>',
            footerTemplate: footer,
            margin: { top: '16mm', bottom: '18mm', left: '0', right: '0' },
            tagged: true,
            outline: true,
        });
    } finally {
        await browser.close();
    }
    const kb = Math.round(statSync(PDF_OUT).size / 1024);
    console.log(`Guide ready: docs/guide/Lettie-guide-utilisateur.pdf (${kb} KB) and docs/guide/index.html`);
}

main().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
