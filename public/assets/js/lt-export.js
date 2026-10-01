/**
 * Lettie export module (pure): builds Excel workbooks (ExcelJS) and PDF
 * document definitions (pdfmake) from server rows. No DOM, no network:
 * the libraries are passed in, so node:test can use fakes.
 *
 * spec = {title, subtitle?, sheetName?, columns: COLUMN_SETS[x], rows: [...], meta?: {truncated, limit}}
 * ctx  = {t, locale, timeZone, now: Date}
 *
 * Browser: window.LT.export (needs lt-core.js). Node: module.exports.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('./lt-core.js'));
    } else {
        root.LT = root.LT || {};
        root.LT.export = factory(root.LT);
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    /* Column sets. type: text | subject | date | datetime | enum:<group>. width: Excel characters. */
    function col(key, type, width, wide) {
        return { key: key, label: 'js.columns.' + key, type: type || 'text', width: width || 14, wide: !!wide };
    }

    var COLUMN_SETS = {
        mails: [
            col('reference', 'text', 16),
            col('direction', 'enum:direction', 10),
            col('mail_date', 'datetime', 16),
            col('correspondent_name', 'text', 28, true),
            col('subject', 'subject', 46, true),
            col('department_name', 'text', 20),
            col('due_date', 'date', 12),
            col('priority', 'enum:priority', 10),
            col('status', 'enum:status', 18)
        ],
        register_incoming: [
            col('reference', 'text', 16),
            col('received_at', 'datetime', 16),
            col('document_date', 'date', 12),
            col('correspondent_name', 'text', 28, true),
            col('subject', 'subject', 46, true),
            col('department_name', 'text', 20),
            col('channel', 'enum:channel', 16),
            col('status', 'enum:status', 18)
        ],
        register_outgoing: [
            col('reference', 'text', 16),
            col('sent_at', 'datetime', 16),
            col('document_date', 'date', 12),
            col('correspondent_name', 'text', 28, true),
            col('subject', 'subject', 46, true),
            col('department_name', 'text', 20),
            col('channel', 'enum:channel', 16),
            col('status', 'enum:status', 18)
        ]
    };

    var BRAND = '1F5FD1';
    var HEADER_FILL = 'EEF4FF';

    function translator(ctx) {
        return (ctx && ctx.t) || function (key) { return key; };
    }

    /* ----------------------------------------------------------------- cells */

    /** Display text of a cell (PDF, and Excel for non-date columns). */
    function cellText(row, column, ctx) {
        var t = translator(ctx);
        var value = row ? row[column.key] : null;
        if (column.type === 'subject' && row && row.masked) {
            return t('js.export.masked');
        }
        if (value === null || value === undefined || value === '') {
            return '';
        }
        if (column.type === 'date') {
            return core.formatDate(value, ctx);
        }
        if (column.type === 'datetime') {
            return core.formatDateTime(value, ctx);
        }
        if (column.type.indexOf('enum:') === 0) {
            var key = 'enums.' + column.type.slice(5) + '.' + value;
            var label = t(key);
            return label === key ? String(value) : label;
        }
        return String(value);
    }

    /**
     * Excel value: dates become real dates (sortable, filterable in Excel).
     * Excel has no time zone: a datetime is stored as its local wall-clock time.
     */
    function cellValue(row, column, ctx) {
        var value = row ? row[column.key] : null;
        if ((column.type === 'date' || column.type === 'datetime') && value) {
            var date = core.parseServerDate(value);
            if (!date) {
                return null;
            }
            if (column.type === 'date' || date.ltDateOnly) {
                return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
            }
            return wallClock(date, (ctx && ctx.timeZone) || 'UTC');
        }
        var text = cellText(row, column, ctx);
        return text === '' ? null : text;
    }

    /** A Date whose UTC fields equal the local time of `date` in `timeZone`. */
    function wallClock(date, timeZone) {
        var parts = {};
        new Intl.DateTimeFormat('en-GB', {
            timeZone: timeZone, hourCycle: 'h23',
            year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
        }).formatToParts(date).forEach(function (p) { parts[p.type] = p.value; });
        return new Date(Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second));
    }

    /** @return {{headers: string[], body: string[][]}} */
    function buildTable(columns, rows, ctx) {
        var t = translator(ctx);
        return {
            headers: columns.map(function (c) { return t(c.label); }),
            body: (rows || []).map(function (row) {
                return columns.map(function (c) { return cellText(row, c, ctx); });
            })
        };
    }

    /* ----------------------------------------------------------------- names */

    function slugify(text) {
        return String(text || '')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
            .slice(0, 80) || 'export';
    }

    /** lettie-registre-du-courrier-entrant-2026-09-30.xlsx */
    function fileName(title, extension, now, timeZone) {
        var day = new Intl.DateTimeFormat('en-CA', { timeZone: timeZone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' })
            .format(now || new Date());
        return 'lettie-' + slugify(title) + '-' + day + '.' + extension;
    }

    function generatedLine(ctx) {
        var t = translator(ctx);
        return t('js.export.generated', { date: core.formatDateTime((ctx && ctx.now) || new Date(), ctx) });
    }

    function truncatedLine(spec, ctx) {
        return spec.meta && spec.meta.truncated
            ? translator(ctx)('js.export.truncated', { limit: spec.meta.limit })
            : null;
    }

    /* ----------------------------------------------------------------- Excel */

    /**
     * Fills a new ExcelJS workbook: title, subtitle, generation date, styled
     * header row, frozen header, auto-filter, real dates, column widths.
     *
     * @param {object} ExcelJS the library (window.ExcelJS)
     * @return {object} the workbook (call workbook.xlsx.writeBuffer())
     */
    function writeWorkbook(ExcelJS, spec, ctx) {
        var t = translator(ctx);
        var columns = spec.columns;
        var workbook = new ExcelJS.Workbook();
        workbook.creator = 'Lettie';
        workbook.created = (ctx && ctx.now) || new Date();
        workbook.title = spec.title;

        var sheet = workbook.addWorksheet(String(spec.sheetName || spec.title).replace(/[\\/?*[\]:]/g, ' ').slice(0, 31));
        sheet.columns = columns.map(function (c) { return { width: c.width }; });

        var title = sheet.addRow([spec.title]);
        title.font = { bold: true, size: 14, color: { argb: 'FF' + BRAND } };
        sheet.mergeCells(1, 1, 1, columns.length);
        var infoLines = [spec.subtitle, generatedLine(ctx), truncatedLine(spec, ctx)].filter(Boolean);
        infoLines.forEach(function (line) {
            sheet.addRow([line]).font = { italic: true, color: { argb: 'FF5B6573' } };
        });
        sheet.addRow([]);

        var header = sheet.addRow(columns.map(function (c) { return t(c.label); }));
        var headerRow = header.number;
        header.font = { bold: true, color: { argb: 'FF161B22' } };
        header.eachCell(function (cell) {
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + HEADER_FILL } };
            cell.border = { bottom: { style: 'thin', color: { argb: 'FF' + BRAND } } };
            cell.alignment = { vertical: 'middle' };
        });

        (spec.rows || []).forEach(function (row) {
            var excelRow = sheet.addRow(columns.map(function (c) { return cellValue(row, c, ctx); }));
            columns.forEach(function (c, i) {
                if (c.type === 'date') {
                    excelRow.getCell(i + 1).numFmt = 'dd/mm/yyyy';
                } else if (c.type === 'datetime') {
                    excelRow.getCell(i + 1).numFmt = 'dd/mm/yyyy hh:mm';
                } else if (c.wide) {
                    excelRow.getCell(i + 1).alignment = { wrapText: true, vertical: 'top' };
                }
            });
        });

        sheet.views = [{ state: 'frozen', ySplit: headerRow }];
        sheet.autoFilter = { from: { row: headerRow, column: 1 }, to: { row: headerRow, column: columns.length } };
        return workbook;
    }

    /* ------------------------------------------------------------------- PDF */

    /** pdfmake document definition (A4, landscape when the table is wide). */
    function pdfDefinition(spec, ctx) {
        var t = translator(ctx);
        var table = buildTable(spec.columns, spec.rows, ctx);
        var landscape = spec.columns.length > 6;
        var generated = generatedLine(ctx);
        var truncated = truncatedLine(spec, ctx);

        var content = [{ text: spec.title, style: 'title' }];
        if (spec.subtitle) {
            content.push({ text: spec.subtitle, style: 'subtitle' });
        }
        if (truncated) {
            content.push({ text: truncated, style: 'warning' });
        }
        content.push({
            table: {
                headerRows: 1,
                widths: spec.columns.map(function (c) { return c.wide ? '*' : 'auto'; }),
                body: [table.headers.map(function (h) { return { text: h, style: 'tableHeader' }; })].concat(table.body)
            },
            layout: {
                hLineWidth: function (i) { return i === 1 ? 1 : 0.4; },
                vLineWidth: function () { return 0; },
                hLineColor: function (i) { return i === 1 ? '#' + BRAND : '#dde1e7'; },
                fillColor: function (rowIndex) { return rowIndex > 0 && rowIndex % 2 === 0 ? '#f6f7f9' : null; },
                paddingTop: function () { return 3; },
                paddingBottom: function () { return 3; }
            }
        });
        if (table.body.length === 0) {
            content.push({ text: t('js.export.empty'), style: 'subtitle', margin: [0, 12, 0, 0] });
        }

        return {
            info: { title: spec.title, creator: 'Lettie', producer: 'Lettie' },
            pageSize: 'A4',
            pageOrientation: landscape ? 'landscape' : 'portrait',
            pageMargins: [28, 32, 28, 40],
            content: content,
            footer: function (page, pages) {
                return {
                    margin: [28, 12, 28, 0],
                    columns: [
                        { text: generated, style: 'footer' },
                        { text: t('js.export.page', { page: page, pages: pages }), style: 'footer', alignment: 'right' }
                    ]
                };
            },
            defaultStyle: { fontSize: 8, color: '#161b22' },
            styles: {
                title: { fontSize: 15, bold: true, color: '#' + BRAND, margin: [0, 0, 0, 2] },
                subtitle: { fontSize: 9, color: '#5b6573', margin: [0, 0, 0, 8] },
                warning: { fontSize: 9, color: '#9a6700', margin: [0, 0, 0, 8] },
                tableHeader: { bold: true, fillColor: '#' + HEADER_FILL, color: '#161b22' },
                footer: { fontSize: 7, color: '#5b6573' }
            }
        };
    }

    return {
        COLUMN_SETS: COLUMN_SETS,
        cellText: cellText,
        cellValue: cellValue,
        wallClock: wallClock,
        buildTable: buildTable,
        slugify: slugify,
        fileName: fileName,
        writeWorkbook: writeWorkbook,
        pdfDefinition: pdfDefinition
    };
});
