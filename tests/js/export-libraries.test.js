'use strict';

/**
 * Runs lt-export.js against the real vendored ExcelJS and pdfmake bundles
 * (the ones served to browsers) and reads the generated files back.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const LT = require('../../public/assets/js/lt-core.js');
const X = require('../../public/assets/js/lt-export.js');

const vendor = path.join(__dirname, '../../public/assets/vendor');
const ExcelJS = require(path.join(vendor, 'exceljs-4.4.0/exceljs.min.js'));

const t = LT.createTranslator({
    js: {
        columns: { reference: 'Référence', received_at: 'Reçu le', document_date: 'Date du document', correspondent_name: 'Correspondant',
            subject: 'Objet', department_name: 'Service', channel: 'Canal', status: 'Statut' },
        export: { generated: 'Généré le :date par Lettie', page: 'Page :page / :pages', masked: '[Objet confidentiel]', empty: 'Aucune ligne.' }
    },
    enums: { channel: { postal: 'Courrier postal' }, status: { closed: 'Clos', registered: 'Enregistré' } }
});
const ctx = { t, locale: 'fr', timeZone: 'Europe/Paris', now: new Date('2026-09-30T08:00:00Z') };
const rows = Array.from({ length: 120 }, (_, i) => ({
    reference: 'ENT-2026-' + String(i + 1).padStart(5, '0'),
    received_at: '2026-09-29 22:30:00',
    document_date: '2026-09-25',
    correspondent_name: 'Mairie de Saint-Étienne',
    subject: i === 1 ? null : 'Demande de subvention — « été » n°' + (i + 1),
    masked: i === 1,
    department_name: 'Ressources humaines',
    channel: 'postal',
    status: i % 2 ? 'closed' : 'registered'
}));
const spec = { title: 'Registre du courrier — Entrant', subtitle: 'Du 01/09/2026 au 30/09/2026', columns: X.COLUMN_SETS.register_incoming, rows };

test('real ExcelJS: the workbook opens and holds typed, translated cells', async () => {
    const buffer = await X.writeWorkbook(ExcelJS, spec, ctx).xlsx.writeBuffer();
    assert.ok(buffer.byteLength > 5000);

    const back = new ExcelJS.Workbook();
    await back.xlsx.load(buffer);
    const sheet = back.worksheets[0];
    assert.equal(sheet.getCell('A1').value, 'Registre du courrier — Entrant');
    assert.equal(sheet.getRow(5).getCell(1).value, 'Référence');
    assert.equal(sheet.getRow(6).getCell(1).value, 'ENT-2026-00001');
    const received = sheet.getRow(6).getCell(2);
    assert.ok(received.value instanceof Date);
    assert.equal(received.value.toISOString(), '2026-09-30T00:30:00.000Z', 'Paris wall-clock time');
    assert.equal(received.numFmt, 'dd/mm/yyyy hh:mm');
    assert.equal(sheet.getRow(6).getCell(5).value, 'Demande de subvention — « été » n°1');
    assert.equal(sheet.getRow(7).getCell(5).value, '[Objet confidentiel]');
    assert.equal(sheet.getRow(6).getCell(7).value, 'Courrier postal');
    assert.equal(sheet.rowCount, 5 + 120);
    // (ExcelJS does not read auto-filters back when loading: checked with the fake in lt-export.test.js.)
});

test('real pdfmake: the document renders to a multi-page PDF', async (t2) => {
    let pdfMake;
    try {
        pdfMake = require(path.join(vendor, 'pdfmake-0.2.14/pdfmake.min.js'));
        const fonts = require(path.join(vendor, 'pdfmake-0.2.14/vfs_fonts.js'));
        // In the browser vfs_fonts.js attaches itself to window.pdfMake; under Node it is only exported.
        pdfMake.vfs = (fonts.pdfMake && fonts.pdfMake.vfs) || fonts.vfs || fonts;
    } catch (e) {
        t2.skip('pdfmake browser bundle cannot run in this Node version: ' + e.message);
        return;
    }
    const doc = X.pdfDefinition(spec, ctx);
    const buffer = await new Promise((resolve, reject) => {
        try {
            pdfMake.createPdf(doc).getBuffer((b) => resolve(Buffer.from(b)));
        } catch (e) {
            reject(e);
        }
    });
    const text = buffer.toString('latin1');
    assert.equal(text.slice(0, 5), '%PDF-');
    const pages = (text.match(/\/Type\s*\/Page[^s]/g) || []).length;
    assert.ok(pages >= 3, `120 rows span several landscape pages (got ${pages})`);
    // (Text and metadata are in compressed streams: not searchable as plain bytes.)
    assert.ok(buffer.length > 20000, `PDF size ${buffer.length}`);
});
