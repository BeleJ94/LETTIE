'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const overview = require('../../public/assets/js/pages/overview.js');

const t = (key, params) => (key === 'js.stats.week' ? `S${params.week} ${params.year}` : key);
const THEME = {
    '--sapChart_OrderedColor_1': ' theme-blue ',
    '--sapChart_OrderedColor_2': 'theme-orange',
    '--sapChart_Bad': 'theme-red',
    '--sapChart_Critical': 'theme-amber',
    '--sapChart_Good': 'theme-green',
    '--sapContent_LabelColor': 'theme-label',
    '--sapList_BorderColor': 'theme-border',
};
const colours = overview.palette((name) => THEME[name]);

test('the palette is read from the theme variables, role by role', () => {
    assert.deepEqual(colours, {
        incoming: 'theme-blue', outgoing: 'theme-orange', negative: 'theme-red', critical: 'theme-amber',
        positive: 'theme-green', text: 'theme-label', grid: 'theme-border',
    });
    assert.ok(Object.values(overview.VARIABLES).every((name) => name.startsWith('--sap')), 'only UI5 theme variables');
});

test('no colour is written in the source: they all come from the theme', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/pages/overview.js'), 'utf8');
    assert.equal(/#[0-9a-f]{3,8}\b/i.test(source), false, 'no hex colour');
    assert.equal(/\brgba?\(|\bhsla?\(/i.test(source), false, 'no colour function');
});

test('volumes: stacked bars per week with the series colours of the theme', () => {
    const config = overview.volumesConfig({ labels: ['2026-W39', '2026-W40'], incoming: [9, 8], outgoing: [1, 1] }, colours, t);
    assert.equal(config.type, 'bar');
    assert.deepEqual(config.data.labels, ['S39', 'S40']);
    assert.deepEqual(config.data.datasets.map((d) => [d.label, d.data, d.backgroundColor]), [
        ['js.overview.incoming', [9, 8], 'theme-blue'],
        ['js.overview.outgoing', [1, 1], 'theme-orange'],
    ]);
    assert.equal(config.options.scales.x.stacked, true);
    assert.equal(config.options.scales.y.ticks.precision, 0, 'whole numbers only');
    assert.equal(config.options.scales.y.ticks.color, 'theme-label');
    assert.equal(config.options.maintainAspectRatio, false);
});

test('overdue by department: horizontal bars in the negative colour, unnamed department labelled', () => {
    const config = overview.overdueConfig([{ name: 'Finances', count: 8 }, { name: null, count: 2 }], colours, t);
    assert.equal(config.options.indexAxis, 'y');
    assert.deepEqual(config.data.labels, ['Finances', 'js.overview.no_department']);
    assert.deepEqual(config.data.datasets[0].data, [8, 2]);
    assert.equal(config.data.datasets[0].backgroundColor, 'theme-red');
    assert.equal(config.options.plugins.legend.display, false);
    assert.equal(config.options.scales.x.beginAtZero, true);
});

test('weekLabel shortens ISO weeks and leaves other keys alone', () => {
    assert.equal(overview.weekLabel('2026-W07', t), 'S7');
    assert.equal(overview.weekLabel('2026-09', t), '2026-09');
});
