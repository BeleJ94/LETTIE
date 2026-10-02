'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const LR = require('../../public/assets/js/lt-listreport.js');

function memoryStorage() {
    const data = {};
    return {
        get: (key, fallback) => (key in data ? data[key] : fallback),
        set: (key, value) => { data[key] = value; },
        data,
    };
}

test('statuses map to semantic states, never to a hand-picked colour', () => {
    assert.equal(LR.tagDesign('status', 'registered').design, 'Information');
    assert.equal(LR.tagDesign('status', 'awaiting_reply').design, 'Critical');
    assert.equal(LR.tagDesign('status', 'closed').design, 'Positive');
    assert.equal(LR.tagDesign('status', 'archived').design, 'Neutral');
    assert.equal(LR.tagDesign('priority', 'urgent').design, 'Negative');
    assert.equal(LR.tagDesign('priority', 'high').design, 'Critical');
    assert.deepEqual(LR.tagDesign('direction', 'incoming'), { design: 'Set2', colorScheme: '6' });
    assert.deepEqual(LR.tagDesign('status', 'unknown'), { design: 'Neutral', colorScheme: null });
    assert.deepEqual(LR.tagDesign('nothing', 'x'), { design: 'Neutral', colorScheme: null });
});

test('every mail status of the application has a state', () => {
    const statuses = ['registered', 'assigned', 'in_progress', 'awaiting_reply', 'answered', 'closed', 'archived'];
    assert.deepEqual(Object.keys(LR.TAG_DESIGNS.status).sort(), statuses.slice().sort());
});

test('a due date is flagged only while the mail is pending', () => {
    const now = new Date('2026-10-01T10:00:00Z');
    assert.equal(LR.dueDesign('2026-09-20', 'in_progress', now, 'Europe/Paris'), 'Negative');
    assert.equal(LR.dueDesign('2026-10-01', 'assigned', now, 'Europe/Paris'), 'Critical');
    assert.equal(LR.dueDesign('2026-10-20', 'assigned', now, 'Europe/Paris'), '');
    assert.equal(LR.dueDesign('2026-09-20', 'closed', now, 'Europe/Paris'), '');
    assert.equal(LR.dueDesign(null, 'assigned', now, 'Europe/Paris'), '');
});

test('buildQuery follows the server table contract and drops empty filters', () => {
    assert.deepEqual(
        LR.buildQuery({ page: 2, perPage: 25, sort: 'mail_date', dir: 'desc', search: '  ENT-2026 ', filters: { status: 'registered', priority: '', mine: true, overdue: false } }),
        { page: 2, per_page: 25, sort: 'mail_date', dir: 'desc', q: 'ENT-2026', mine: '1', status: 'registered' },
    );
    assert.deepEqual(LR.buildQuery({}), { page: 1, per_page: 25 });
    assert.equal(LR.buildQuery({ sort: 'subject', dir: 'sideways' }).dir, 'desc');
});

test('a filter can never overwrite the paging, sort or search parameters', () => {
    const query = LR.buildQuery({ page: 3, sort: 'subject', dir: 'asc', filters: { page: '99', sort: 'password', q: 'x', status: 'closed' } });
    assert.deepEqual(query, { page: 3, per_page: 25, sort: 'subject', dir: 'asc', status: 'closed' });
});

test('filtersFromSearch reads deep links and ignores unknown parameters', () => {
    const names = ['direction', 'status', 'mine', 'overdue'];
    assert.deepEqual(LR.filtersFromSearch('?direction=incoming&status=registered', names),
        { filters: { direction: 'incoming', status: 'registered' }, search: '', any: true });
    assert.deepEqual(LR.filtersFromSearch('?mine=1&overdue=1&evil=1', names),
        { filters: { mine: '1', overdue: '1' }, search: '', any: true });
    assert.deepEqual(LR.filtersFromSearch('?q=Pr%C3%A9fecture+du+Rh%C3%B4ne', names),
        { filters: {}, search: 'Préfecture du Rhône', any: true });
    assert.deepEqual(LR.filtersFromSearch('?mine=0&status=', names), { filters: {}, search: '', any: false });
    assert.deepEqual(LR.filtersFromSearch('', names), { filters: {}, search: '', any: false });
    assert.equal(LR.filtersFromSearch('?q=%E0%A4%A', names).search, '', 'a malformed escape is not an error');
});

test('toggleSort flips the direction of the same column', () => {
    assert.deepEqual(LR.toggleSort({ sort: 'subject', dir: 'asc' }, 'subject'), { sort: 'subject', dir: 'desc' });
    assert.deepEqual(LR.toggleSort({ sort: 'subject', dir: 'desc' }, 'subject'), { sort: 'subject', dir: 'asc' });
    assert.deepEqual(LR.toggleSort({ sort: 'subject', dir: 'desc' }, 'mail_date'), { sort: 'mail_date', dir: 'asc' });
    assert.deepEqual(LR.toggleSort(null, 'mail_date'), { sort: 'mail_date', dir: 'asc' });
});

test('visibleColumns keeps the order, the required columns and at least one column', () => {
    const columns = [{ name: 'reference', required: true }, { name: 'subject' }, { name: 'status' }];
    assert.deepEqual(LR.visibleColumns(columns, ['subject']).map((c) => c.name), ['reference', 'status']);
    assert.deepEqual(LR.visibleColumns(columns, ['reference', 'subject', 'status']).map((c) => c.name), ['reference']);
    assert.deepEqual(LR.visibleColumns([{ name: 'a' }, { name: 'b' }], ['a', 'b']).map((c) => c.name), ['a']);
    assert.deepEqual(LR.visibleColumns(columns).map((c) => c.name), ['reference', 'subject', 'status']);
});

test('countTitle shows the counter once it is known', () => {
    assert.equal(LR.countTitle('Courriers', 46, 'fr'), 'Courriers (46)');
    assert.equal(LR.countTitle('Courriers', 0, 'fr'), 'Courriers (0)');
    assert.equal(LR.countTitle('Courriers', null, 'fr'), 'Courriers');
    assert.match(LR.countTitle('Mail', 12345, 'en'), /^Mail \(12,345\)$/);
});

test('bulkSummary reports what was done and what was refused', () => {
    const references = { 4: 'ENT-2026-00004', 9: 'ENT-2026-00009' };
    assert.deepEqual(LR.bulkSummary({ done: [1, 2, 3], failed: [] }, references), { done: 3, failed: 0, state: 'Positive', details: [] });
    assert.deepEqual(LR.bulkSummary({ done: [1], failed: [{ id: 4, message: 'Déjà clos.' }] }, references),
        { done: 1, failed: 1, state: 'Critical', details: ['ENT-2026-00004: Déjà clos.'] });
    const french = LR.bulkSummary({ done: [], failed: [{ id: 4, message: 'Déjà clos.' }] }, references, (reference, message) => `${reference} : ${message}`);
    assert.deepEqual(french.details, ['ENT-2026-00004 : Déjà clos.'], 'the caller translates the line');
    const refused = LR.bulkSummary({ done: [], failed: [{ id: 9, message: 'Refusé.' }, { id: 12, message: 'Introuvable.' }] }, references);
    assert.equal(refused.state, 'Negative');
    assert.deepEqual(refused.details, ['ENT-2026-00009: Refusé.', '#12: Introuvable.']);
    assert.deepEqual(LR.bulkSummary(null), { done: 0, failed: 0, state: 'Positive', details: [] });
});

test('variants are saved per list, sorted, and replaced by name', () => {
    const storage = memoryStorage();
    const store = LR.createVariantStore(storage, 'mails');
    assert.deepEqual(store.list(), []);
    assert.equal(store.defaultName(), LR.STANDARD);

    assert.deepEqual(store.save('  Mes   retards ', { filters: { mine: '1', overdue: '1' } }), { ok: true, name: 'Mes retards' });
    assert.deepEqual(store.save('À affecter', { filters: { status: 'registered' } }, true), { ok: true, name: 'À affecter' });
    assert.deepEqual(store.list().map((v) => v.name), ['À affecter', 'Mes retards']);
    assert.equal(store.defaultName(), 'À affecter');
    assert.deepEqual(store.get('Mes retards').state, { filters: { mine: '1', overdue: '1' } });

    store.save('Mes retards', { filters: { mine: '1' } });
    assert.equal(store.list().length, 2, 'same name: replaced, not duplicated');
    assert.deepEqual(store.get('Mes retards').state, { filters: { mine: '1' } });
    assert.equal(store.defaultName(), 'À affecter', 'saving without "default" keeps the default');

    assert.deepEqual(LR.createVariantStore(storage, 'correspondents').list(), [], 'another list has its own variants');
    assert.ok('lt.variants.mails' in storage.data);
});

test('removing the default variant falls back to Standard', () => {
    const store = LR.createVariantStore(memoryStorage(), 'mails');
    store.save('A', { filters: {} }, true);
    store.save('B', { filters: {} });
    store.remove('A');
    assert.equal(store.defaultName(), LR.STANDARD);
    assert.deepEqual(store.list().map((v) => v.name), ['B']);
    store.setDefault('B');
    assert.equal(store.defaultName(), 'B');
    store.setDefault(LR.STANDARD);
    assert.equal(store.defaultName(), LR.STANDARD);
});

test('variant names are checked and the number of variants is bounded', () => {
    const store = LR.createVariantStore(memoryStorage(), 'mails');
    assert.deepEqual(store.save('   ', {}), { ok: false, error: 'empty' });
    assert.deepEqual(store.save(LR.STANDARD, {}), { ok: false, error: 'reserved' });
    assert.equal(store.save('x'.repeat(200), {}).name.length, 60);
    for (let i = 1; i < LR.MAX_VARIANTS; i++) {
        assert.equal(store.save('v' + i, {}).ok, true);
    }
    assert.deepEqual(store.save('one too many', {}), { ok: false, error: 'full' });
    assert.equal(store.save('v1', { filters: { a: '1' } }).ok, true, 'replacing is still allowed when full');
});

test('a corrupted or foreign storage value is ignored', () => {
    const storage = memoryStorage();
    storage.set('lt.variants.mails', '{not json');
    assert.deepEqual(LR.createVariantStore(storage, 'mails').list(), []);
    storage.set('lt.variants.mails', JSON.stringify({ variants: [{ name: '' }, null, { name: 'ok', state: {} }, { name: 'bad', state: 3 }], defaultName: 'gone' }));
    const store = LR.createVariantStore(storage, 'mails');
    assert.deepEqual(store.list().map((v) => v.name), ['ok']);
    assert.equal(store.defaultName(), LR.STANDARD);
});

test('a saved state is a copy: later changes do not alter the variant', () => {
    const store = LR.createVariantStore(memoryStorage(), 'mails');
    const state = { filters: { status: 'closed' }, hidden: ['priority'] };
    store.save('Clos', state);
    state.filters.status = 'archived';
    state.hidden.push('status');
    assert.deepEqual(store.get('Clos').state, { filters: { status: 'closed' }, hidden: ['priority'] });
});

test('isModified compares what a variant stores, whatever the key order', () => {
    const saved = { filters: { status: 'registered', mine: '1' }, search: '', sort: 'mail_date', dir: 'desc', hidden: ['priority', 'direction'] };
    assert.equal(LR.isModified(saved, { filters: { mine: true, status: 'registered', priority: '' }, sort: 'mail_date', dir: 'desc', hidden: ['direction', 'priority'] }), false);
    assert.equal(LR.isModified(saved, Object.assign({}, saved, { search: 'x' })), true);
    assert.equal(LR.isModified(saved, Object.assign({}, saved, { dir: 'asc' })), true);
    assert.equal(LR.isModified(saved, Object.assign({}, saved, { hidden: [] })), true);
    assert.equal(LR.isModified({}, {}), false);
});
