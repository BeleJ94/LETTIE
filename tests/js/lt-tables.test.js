'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const tables = require('../../public/assets/js/lt-tables.js');
const LT = require('../../public/assets/js/lt-core.js');

const dtRequest = (over = {}) => Object.assign({
    draw: 3,
    start: 50,
    length: 25,
    order: [{ column: 1, dir: 'desc' }],
    columns: [
        { data: 'reference', name: 'reference', orderable: true },
        { data: 'received_at', name: 'received_at', orderable: true },
        { data: 'actions', name: 'actions', orderable: false }
    ],
    search: { value: '  facture  ' }
}, over);

test('toServerParams maps paging, sorting and search', () => {
    assert.deepEqual(tables.toServerParams(dtRequest()), {
        page: 3, per_page: 25, sort: 'received_at', dir: 'desc', q: 'facture'
    });
});

test('toServerParams caps page size and ignores unsafe or unsortable sort keys', () => {
    assert.equal(tables.toServerParams(dtRequest({ length: 1000, start: 0 })).per_page, tables.MAX_PER_PAGE);
    assert.equal(tables.toServerParams(dtRequest({ length: -1 })).per_page, 25);

    const unsortable = tables.toServerParams(dtRequest({ order: [{ column: 2, dir: 'asc' }] }));
    assert.equal(unsortable.sort, undefined);

    const injected = dtRequest();
    injected.columns[1].name = 'received_at; DROP TABLE mails';
    injected.columns[1].data = 'x y';
    assert.equal(tables.toServerParams(injected).sort, undefined);

    assert.equal(tables.toServerParams(dtRequest({ order: [{ column: 0, dir: 'weird' }] })).dir, 'asc');
    assert.equal(tables.toServerParams(dtRequest({ search: { value: '   ' } })).q, undefined);
});

test('toServerParams merges filters without overriding core parameters', () => {
    const params = tables.toServerParams(dtRequest(), () => ({ status: 'open', page: 99, direction: 'incoming' }));
    assert.equal(params.status, 'open');
    assert.equal(params.direction, 'incoming');
    assert.equal(params.page, 3);
    assert.equal(tables.toServerParams(dtRequest(), { site: 2 }).site, 2);
});

test('toDataTablesResult converts the server envelope', () => {
    assert.deepEqual(tables.toDataTablesResult({ data: [{ id: 1 }], meta: { total: 120, filtered: 12 } }, '4'), {
        draw: 4, recordsTotal: 120, recordsFiltered: 12, data: [{ id: 1 }]
    });
    assert.deepEqual(tables.toDataTablesResult({ data: [{ id: 1 }, { id: 2 }] }, 1), {
        draw: 1, recordsTotal: 2, recordsFiltered: 2, data: [{ id: 1 }, { id: 2 }]
    });
    assert.deepEqual(tables.toDataTablesResult(null, 2), tables.emptyResult(2));
});

test('languageFor maps translation keys to DataTables options', () => {
    const t = LT.createTranslator({ js: { table: { next: 'Suivante', info: '_START_-_END_/_TOTAL_' }, loading: 'Chargement' } });
    const lang = tables.languageFor(t);
    assert.equal(lang.paginate.next, 'Suivante');
    assert.equal(lang.info, '_START_-_END_/_TOTAL_');
    assert.equal(lang.processing, 'Chargement');
});

const ctx = {
    locale: 'fr',
    timeZone: 'Europe/Paris',
    basePath: '/app',
    now: () => new Date('2026-09-30T08:00:00Z'),
    t: LT.createTranslator({ mail: { status: { in_progress: 'En cours' } } })
};

test('renderers escape output and only format for display', () => {
    const r = tables.createRenderers(ctx);
    const text = r.text();
    assert.equal(text('<b>x</b>', 'display'), '&lt;b&gt;x&lt;/b&gt;');
    assert.equal(text('<b>x</b>', 'sort'), '<b>x</b>');

    assert.equal(r.date()('2026-09-30 22:15:00', 'display'), '01/10/2026');
    assert.equal(r.datetime()('2026-09-30 10:00:00', 'display'), '30/09/2026 12:00');
    assert.equal(r.filesize()(2048, 'display'), '2 Ko');
    assert.equal(r.number()(1500, 'display'), '1 500');
});

test('badge renderer translates and sanitizes the class name', () => {
    const badge = tables.createRenderers(ctx).badge('mail.status');
    assert.equal(badge('in_progress', 'display'), '<span class="lt-badge lt-badge--in_progress">En cours</span>');
    assert.equal(badge('x" onclick="y', 'display'), '<span class="lt-badge lt-badge--xonclicky">mail.status.x&quot; onclick=&quot;y</span>');
    assert.equal(badge('', 'display'), '');
});

test('due renderer flags overdue and soon dates', () => {
    const due = tables.createRenderers(ctx).due();
    assert.equal(due('2026-09-29', 'display'), '<span class="lt-due lt-due--overdue">29/09/2026</span>');
    assert.equal(due('2026-10-01', 'display'), '<span class="lt-due lt-due--soon">01/10/2026</span>');
    assert.equal(due('2026-12-01', 'display'), '<span class="lt-due lt-due--ok">01/12/2026</span>');
    assert.equal(due(null, 'display'), '');
});

test('link renderer builds encoded, escaped URLs from row fields', () => {
    const link = tables.createRenderers(ctx).link('/mails/{id}');
    assert.equal(link('IN-2026-000001', 'display', { id: 7 }), '<a href="/app/mails/7">IN-2026-000001</a>');
    assert.equal(link('<x>', 'display', { id: '1/../2' }), '<a href="/app/mails/1%2F..%2F2">&lt;x&gt;</a>');
});

test('resolveRenderer parses "name:argument" and falls back to text', () => {
    const r = tables.createRenderers(ctx);
    assert.equal(tables.resolveRenderer('badge:mail.status', r)('in_progress', 'display'), '<span class="lt-badge lt-badge--in_progress">En cours</span>');
    assert.equal(tables.resolveRenderer('unknown', r)('<i>', 'display'), '&lt;i&gt;');
    assert.equal(tables.resolveRenderer('__proto__', r)('<i>', 'display'), '&lt;i&gt;');
});

test('buildColumns requires names and maps sortable flags', () => {
    const cols = tables.buildColumns([
        { name: 'reference', title: 'Réf.' },
        { name: 'actions', sortable: false, className: 'lt-num' }
    ], tables.createRenderers(ctx));
    assert.equal(cols[0].data, 'reference');
    assert.equal(cols[0].orderable, true);
    assert.equal(cols[1].orderable, false);
    assert.equal(cols[1].className, 'lt-num');
    assert.throws(() => tables.buildColumns([{ title: 'no name' }], tables.createRenderers(ctx)));
});

test('buildOptions wires server-side ajax through the api', async () => {
    const calls = [];
    const api = {
        get(url, params) {
            calls.push([url, params]);
            return Promise.resolve({ data: [{ reference: 'A' }], meta: { total: 10, filtered: 1 } });
        }
    };
    const options = tables.buildOptions({
        url: '/api/mails',
        columns: [{ name: 'reference', title: 'Réf.' }, { name: 'received_at', title: 'Reçu' }],
        api,
        t: (k) => k,
        ctx,
        filters: () => ({ status: 'open' }),
        pageLength: 500
    });

    assert.equal(options.serverSide, true);
    assert.equal(options.pageLength, 100);
    assert.deepEqual(options.order, [[0, 'asc']]);

    const result = await new Promise((resolve) => options.ajax(dtRequest({ order: [{ column: 0, dir: 'asc' }], columns: options.columns }), resolve));
    assert.deepEqual(calls[0], ['/api/mails', { page: 3, per_page: 25, sort: 'reference', dir: 'asc', q: 'facture', status: 'open' }]);
    assert.deepEqual(result, { draw: 3, recordsTotal: 10, recordsFiltered: 1, data: [{ reference: 'A' }] });
});

test('buildOptions reports errors and returns an empty page', async () => {
    const seen = [];
    const options = tables.buildOptions({
        url: '/x',
        columns: [{ name: 'a' }],
        api: { get: () => Promise.reject(new LT.ApiError('server', 500, 'boom')) },
        onError: (e) => seen.push(e.kind)
    });
    const result = await new Promise((resolve) => options.ajax({ draw: 7 }, resolve));
    assert.deepEqual(result, tables.emptyResult(7));
    assert.deepEqual(seen, ['server']);
});

test('createdRow adds data-label attributes for the mobile card layout', () => {
    const options = tables.buildOptions({
        url: '/x',
        columns: [{ name: 'a', title: 'Objet' }, { name: 'b', title: '' }],
        api: { get: () => Promise.resolve({}) }
    });
    const cells = [{ attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } }, { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } }];
    options.createdRow({ children: cells });
    assert.deepEqual(cells[0].attrs, { 'data-label': 'Objet' });
    assert.deepEqual(cells[1].attrs, {});
});
