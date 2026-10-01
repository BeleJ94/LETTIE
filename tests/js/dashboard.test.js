'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const LT = require('../../public/assets/js/lt-core.js');
const D = require('../../public/assets/js/pages/dashboard.js');

const t = LT.createTranslator({
    js: {
        stats: {
            week: 'S:week :year', no_department: 'Sans service', overdue_now: 'En retard', days: 'jours',
            average_days: 'Délai moyen (jours)', days_value: ':value j', bucket: ':range j de retard',
            kpi_incoming: 'Entrants', kpi_outgoing: 'Sortants', kpi_processing: 'Délai moyen', kpi_overdue: 'En retard',
            median: 'Médiane : :value', late_rate: ':rate % en retard'
        }
    },
    enums: { direction: { incoming: 'Entrant', outgoing: 'Sortant' } }
});

const data = {
    period: { from: '2026-09-28', to: '2026-09-30', granularity: 'day', today: '2026-09-30' },
    totals: { incoming: 12, outgoing: 1234 },
    volumes: { labels: ['2026-09-28', '2026-09-29', '2026-09-30'], incoming: [5, 0, 7], outgoing: [1, 2, 3] },
    departments: [{ name: 'RH', incoming: 8, outgoing: 3, pending: 4, overdue: 2 }, { name: null, incoming: 4, outgoing: 0, pending: 1, overdue: 0 }],
    processing: { count: 3, average_days: 5.3, median_days: 4, closed_late: 1, late_rate: 33.3, by_department: [{ name: null, count: 1, average_days: 10 }] },
    overdue: { total: 4, buckets: { '1-7': 2, '8-30': 1, '31+': 1 }, by_department: [] },
    correspondents: [{ name: 'Mairie', incoming: 5, outgoing: 2, total: 7 }]
};

test('periodLabel for days, ISO weeks and months', () => {
    assert.equal(D.periodLabel('2026-09-30', 'day', 'fr', t), '30/09');
    assert.equal(D.periodLabel('2026-W05', 'week', 'fr', t), 'S5 2026');
    assert.equal(D.periodLabel('2026-09', 'month', 'fr', t), 'sept. 2026');
    assert.equal(D.periodLabel('2026-09', 'month', 'en', t), 'Sept 2026');
    assert.equal(D.periodLabel('weird', 'day', 'fr', t), 'weird');
});

test('volumeChart stacks incoming and outgoing per period', () => {
    const c = D.volumeChart(data, t, D.DEFAULT_PALETTE, 'fr');
    assert.equal(c.type, 'bar');
    assert.deepEqual(c.data.labels, ['28/09', '29/09', '30/09']);
    assert.deepEqual(c.data.datasets.map((d) => [d.label, d.data]), [['Entrant', [5, 0, 7]], ['Sortant', [1, 2, 3]]]);
    assert.equal(c.options.scales.x.stacked, true);
    assert.equal(c.options.scales.y.ticks.precision, 0, 'counts are integers');
    assert.equal(c.options.maintainAspectRatio, false);
});

test('department and processing charts name the missing department', () => {
    const dep = D.departmentChart(data, t);
    assert.equal(dep.options.indexAxis, 'y');
    assert.deepEqual(dep.data.labels, ['RH', 'Sans service']);
    assert.deepEqual(dep.data.datasets[2].data, [2, 0], 'overdue series');

    const proc = D.processingChart(data, t);
    assert.deepEqual(proc.data.labels, ['Sans service']);
    assert.deepEqual(proc.data.datasets[0].data, [10]);
    assert.equal(proc.options.plugins.legend.display, false);
});

test('overdue doughnut and top correspondents', () => {
    const o = D.overdueChart(data, t);
    assert.equal(o.type, 'doughnut');
    assert.deepEqual(o.data.labels, ['1-7 j de retard', '8-30 j de retard', '31+ j de retard']);
    assert.deepEqual(o.data.datasets[0].data, [2, 1, 1]);

    const c = D.correspondentsChart(data, t);
    assert.deepEqual(c.data.labels, ['Mairie']);
    assert.equal(c.options.scales.x.stacked, true);
});

test('kpis format numbers and durations for the locale', () => {
    const k = D.kpis(data, t, 'fr');
    assert.deepEqual(k.map((x) => x.key), ['incoming', 'outgoing', 'processing', 'overdue']);
    assert.equal(k[1].value, '1 234');
    assert.equal(k[2].value, '5,3 j');
    assert.equal(k[2].hint, 'Médiane : 4 j');
    assert.equal(k[3].hint, '33,3 % en retard');

    const empty = D.kpis(Object.assign({}, data, { processing: { average_days: null, median_days: null, late_rate: null } }), t, 'fr');
    assert.equal(empty[2].value, '—');
    assert.equal(empty[2].hint, '');
});

test('isEmptyChart: no labels, or only zeros', () => {
    assert.equal(D.isEmptyChart(D.volumeChart(data, t, D.DEFAULT_PALETTE, 'fr')), false);
    const zeros = Object.assign({}, data, { overdue: { total: 0, buckets: { '1-7': 0, '8-30': 0, '31+': 0 }, by_department: [] } });
    assert.equal(D.isEmptyChart(D.overdueChart(zeros, t)), true, 'an all-zero doughnut is empty');
    assert.equal(D.isEmptyChart(D.correspondentsChart(Object.assign({}, data, { correspondents: [] }), t)), true);
});

test('tableFromChart gives an accessible table for every chart', () => {
    const table = D.tableFromChart(D.volumeChart(data, t, D.DEFAULT_PALETTE, 'fr'));
    assert.deepEqual(table.headers, ['', 'Entrant', 'Sortant']);
    assert.deepEqual(table.rows[0], ['28/09', '5', '1']);
    assert.deepEqual(D.tableFromChart(D.overdueChart(data, t)).rows[2], ['31+ j de retard', '1']);
});
