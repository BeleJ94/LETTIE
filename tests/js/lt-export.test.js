'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const LT = require('../../public/assets/js/lt-core.js');
const X = require('../../public/assets/js/lt-export.js');

const t = LT.createTranslator({
    js: {
        columns: { reference: 'Référence', subject: 'Objet', mail_date: 'Date', due_date: 'Échéance', status: 'Statut' },
        export: {
            generated: 'Généré le :date par Lettie',
            page: 'Page :page / :pages',
            truncated: 'Limité à :limit lignes',
            masked: '[Objet confidentiel]',
            empty: 'Aucune ligne.'
        }
    },
    enums: { status: { in_progress: 'En cours' }, direction: { incoming: 'Entrant' } }
});
const ctx = { t, locale: 'fr', timeZone: 'Europe/Paris', now: new Date('2026-09-30T08:05:00Z') };
const col = (key, type, extra) => Object.assign({ key, label: 'js.columns.' + key, type, width: 12, wide: false }, extra);

const rows = [
    { reference: 'ENT-2026-00001', subject: 'Facture', mail_date: '2026-09-29 22:30:00', due_date: '2026-10-15', status: 'in_progress', masked: false },
    { reference: 'ENT-2026-00002', subject: null, mail_date: '2026-09-30 07:00:00', due_date: null, status: 'unknown', masked: true }
];
const columns = [col('reference', 'text'), col('mail_date', 'datetime'), col('subject', 'subject', { wide: true }), col('due_date', 'date'), col('status', 'enum:status')];

test('column sets are complete and translated keys exist in lang files', () => {
    const fr = require('fs').readFileSync(__dirname + '/../../lang/fr.php', 'utf8');
    for (const [name, set] of Object.entries(X.COLUMN_SETS)) {
        assert.ok(set.length >= 8, name);
        for (const c of set) {
            assert.match(c.type, /^(text|subject|date|datetime|enum:[a-z_]+)$/, `${name}.${c.key}`);
            assert.ok(fr.includes(`'${c.key}' =>`), `lang/fr.php js.columns.${c.key}`);
        }
    }
});

test('cellText formats dates in the time zone, translates enums and masks secret subjects', () => {
    assert.equal(X.cellText(rows[0], columns[1], ctx), '30/09/2026 00:30');
    assert.equal(X.cellText(rows[0], columns[3], ctx), '15/10/2026');
    assert.equal(X.cellText(rows[0], columns[4], ctx), 'En cours');
    assert.equal(X.cellText(rows[1], columns[4], ctx), 'unknown', 'unknown enum values stay readable');
    assert.equal(X.cellText(rows[1], columns[2], ctx), '[Objet confidentiel]');
    assert.equal(X.cellText(rows[1], columns[3], ctx), '');
});

test('cellValue gives Excel real dates (local wall clock for datetimes)', () => {
    assert.equal(X.cellValue(rows[0], columns[1], ctx).toISOString(), '2026-09-30T00:30:00.000Z');
    assert.equal(X.cellValue(rows[0], columns[3], ctx).toISOString(), '2026-10-15T00:00:00.000Z');
    assert.equal(X.cellValue(rows[1], columns[3], ctx), null);
    assert.equal(X.cellValue(rows[0], columns[0], ctx), 'ENT-2026-00001');
    // Daylight saving time is applied per date.
    assert.equal(X.wallClock(new Date('2026-01-15T12:00:00Z'), 'Europe/Paris').toISOString(), '2026-01-15T13:00:00.000Z');
    assert.equal(X.wallClock(new Date('2026-07-15T12:00:00Z'), 'Europe/Paris').toISOString(), '2026-07-15T14:00:00.000Z');
});

test('fileName is a safe slug with the local date', () => {
    assert.equal(X.fileName('Registre du courrier — Entrant', 'xlsx', ctx.now, 'Europe/Paris'), 'lettie-registre-du-courrier-entrant-2026-09-30.xlsx');
    // Accents removed, separators and path characters collapsed; date of the day in Paris.
    assert.equal(X.fileName('../../Évé:nements*', 'pdf', new Date('2026-09-30T23:30:00Z'), 'Europe/Paris'), 'lettie-eve-nements-2026-10-01.pdf');
    assert.equal(X.slugify(''), 'export');
});

/** Minimal ExcelJS double that records what the module does. */
function fakeExcel() {
    const sheets = [];
    class Workbook {
        constructor() { this.xlsx = {}; }
        addWorksheet(name) {
            const sheet = {
                name, rows: [], merges: [], columns: null, views: null, autoFilter: null,
                addRow(values) {
                    const cells = values.map((value) => ({ value }));
                    const row = {
                        number: sheet.rows.length + 1, values, cells,
                        getCell: (i) => cells[i - 1] || (cells[i - 1] = { value: null }),
                        eachCell: (fn) => cells.forEach((c, i) => fn(c, i + 1))
                    };
                    sheet.rows.push(row);
                    return row;
                },
                mergeCells(...args) { sheet.merges.push(args); }
            };
            sheets.push(sheet);
            return sheet;
        }
    }
    return { Workbook, sheets };
}

test('writeWorkbook builds a titled, filtered, frozen sheet with typed cells', () => {
    const ExcelJS = fakeExcel();
    const wb = X.writeWorkbook(ExcelJS, { title: 'Registre / 2026', subtitle: 'Du 01/09 au 30/09', columns, rows, meta: { truncated: true, limit: 2 } }, ctx);
    const sheet = ExcelJS.sheets[0];

    assert.equal(wb.creator, 'Lettie');
    assert.equal(sheet.name, 'Registre   2026', 'characters forbidden in sheet names are replaced');
    assert.deepEqual(sheet.columns.map((c) => c.width), [12, 12, 12, 12, 12]);
    assert.deepEqual(sheet.rows.slice(0, 4).map((r) => r.values[0]), ['Registre / 2026', 'Du 01/09 au 30/09', 'Généré le 30/09/2026 10:05 par Lettie', 'Limité à 2 lignes']);
    assert.deepEqual(sheet.merges[0], [1, 1, 1, 5]);

    const header = sheet.rows[5];
    assert.deepEqual(header.values, ['Référence', 'Date', 'Objet', 'Échéance', 'Statut']);
    assert.equal(header.cells[0].fill.fgColor.argb, 'FFEEF4FF');
    assert.deepEqual(sheet.views, [{ state: 'frozen', ySplit: 6 }]);
    assert.deepEqual(sheet.autoFilter, { from: { row: 6, column: 1 }, to: { row: 6, column: 5 } });

    const first = sheet.rows[6];
    assert.ok(first.values[1] instanceof Date);
    assert.equal(first.cells[1].numFmt, 'dd/mm/yyyy hh:mm');
    assert.equal(first.cells[3].numFmt, 'dd/mm/yyyy');
    assert.equal(first.cells[2].alignment.wrapText, true);
    assert.equal(sheet.rows[7].values[2], '[Objet confidentiel]');
    assert.equal(sheet.rows.length, 8);
});

test('pdfDefinition: A4, landscape when wide, header row, footer with pages', () => {
    const doc = X.pdfDefinition({ title: 'Liste du courrier', columns, rows }, ctx);
    assert.equal(doc.pageSize, 'A4');
    assert.equal(doc.pageOrientation, 'portrait', '5 columns fit in portrait');
    assert.equal(doc.content[0].text, 'Liste du courrier');

    const table = doc.content.find((c) => c.table).table;
    assert.equal(table.headerRows, 1);
    assert.deepEqual(table.widths, ['auto', 'auto', '*', 'auto', 'auto']);
    assert.deepEqual(table.body[0].map((h) => h.text), ['Référence', 'Date', 'Objet', 'Échéance', 'Statut']);
    assert.deepEqual(table.body[1], ['ENT-2026-00001', '30/09/2026 00:30', 'Facture', '15/10/2026', 'En cours']);

    const footer = doc.footer(2, 7);
    assert.equal(footer.columns[1].text, 'Page 2 / 7');
    assert.equal(footer.columns[0].text, 'Généré le 30/09/2026 10:05 par Lettie');

    const wide = X.pdfDefinition({ title: 'x', columns: X.COLUMN_SETS.register_incoming, rows: [] }, ctx);
    assert.equal(wide.pageOrientation, 'landscape');
    assert.ok(wide.content.some((c) => c.text === 'Aucune ligne.'));
    assert.equal(doc.info.creator, 'Lettie');
});
