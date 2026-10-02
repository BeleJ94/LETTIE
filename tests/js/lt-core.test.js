'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const LT = require('../../public/assets/js/lt-core.js');

const NBSP = ' ';
const NNBSP = ' ';

test('escapeHtml escapes the five HTML special characters', () => {
    assert.equal(LT.escapeHtml(`<a href="x">'&'</a>`), '&lt;a href=&quot;x&quot;&gt;&#039;&amp;&#039;&lt;/a&gt;');
    assert.equal(LT.escapeHtml(null), '');
    assert.equal(LT.escapeHtml(undefined), '');
    assert.equal(LT.escapeHtml(42), '42');
});

test('createTranslator: dot keys, placeholders, missing keys', () => {
    const t = LT.createTranslator({ js: { hello: 'Bonjour :name, :name !', n: 5 }, 'flat.key': 'Flat' });
    assert.equal(t('js.hello', { name: 'Ana' }), 'Bonjour Ana, Ana !');
    assert.equal(t('flat.key'), 'Flat');
    assert.equal(t('js.missing'), 'js.missing');
    assert.equal(t('js.n'), 'js.n', 'non-string values are not translations');
    assert.equal(t('js.hello.deeper'), 'js.hello.deeper');
});

test('createTranslator replaces whole placeholder names only', () => {
    const t = LT.createTranslator({ p: 'Page :page / :pages', u: ':unknown stays, :n = 0' });
    assert.equal(t('p', { page: 2, pages: 7 }), 'Page 2 / 7');
    assert.equal(t('u', { n: 0 }), ':unknown stays, 0 = 0');
});

test('parseServerDate treats naive datetimes as UTC and keeps date-only values', () => {
    assert.equal(LT.parseServerDate('2026-09-30 22:15:00').toISOString(), '2026-09-30T22:15:00.000Z');
    assert.equal(LT.parseServerDate('2026-09-30T22:15').toISOString(), '2026-09-30T22:15:00.000Z');
    assert.equal(LT.parseServerDate('2026-09-30 22:15:00.123456').toISOString(), '2026-09-30T22:15:00.000Z');
    assert.equal(LT.parseServerDate('2026-09-30T22:15:00+02:00').toISOString(), '2026-09-30T20:15:00.000Z');
    assert.equal(LT.parseServerDate('2026-09-30').ltDateOnly, true);
    assert.equal(LT.parseServerDate('2026-02-30'), null);
    assert.equal(LT.parseServerDate('30/09/2026'), null);
    assert.equal(LT.parseServerDate(''), null);
    assert.equal(LT.parseServerDate(null), null);
});

test('formatDate / formatDateTime convert to the application time zone', () => {
    const paris = { locale: 'fr', timeZone: 'Europe/Paris' };
    assert.equal(LT.formatDate('2026-09-30', paris), '30/09/2026');
    // 22:15 UTC is already the next day in Paris.
    assert.equal(LT.formatDate('2026-09-30 22:15:00', paris), '01/10/2026');
    assert.equal(LT.formatDateTime('2026-09-30 22:15:00', paris), '01/10/2026 00:15');
    assert.equal(LT.formatDateTime('2026-09-30 10:05:00', { locale: 'en', timeZone: 'Europe/Paris' }), '30/09/2026, 12:05');
    // Date-only values are never shifted, whatever the zone.
    assert.equal(LT.formatDate('2026-09-30', { locale: 'fr', timeZone: 'America/Los_Angeles' }), '30/09/2026');
    assert.equal(LT.formatDateTime('2026-09-30', paris), '30/09/2026');
    assert.equal(LT.formatDate('nope', paris), '');
});

test('daysUntil and dueStatus use calendar days in the time zone', () => {
    const now = new Date('2026-09-30T22:30:00Z'); // 1 October 00:30 in Paris
    assert.equal(LT.daysUntil('2026-10-01', now, 'Europe/Paris'), 0);
    assert.equal(LT.daysUntil('2026-10-01', now, 'UTC'), 1);
    assert.equal(LT.daysUntil('2026-09-30', now, 'Europe/Paris'), -1);
    assert.equal(LT.dueStatus('2026-09-30', now, 'Europe/Paris'), 'overdue');
    assert.equal(LT.dueStatus('2026-10-03', now, 'Europe/Paris'), 'soon');
    assert.equal(LT.dueStatus('2026-10-04', now, 'Europe/Paris'), 'ok');
    assert.equal(LT.dueStatus('2026-10-04', now, 'Europe/Paris', 5), 'soon');
    assert.equal(LT.dueStatus(null, now, 'UTC'), null);
});

test('formatNumber and formatFileSize follow the locale', () => {
    assert.equal(LT.formatNumber(1234.5, 'fr'), `1${NNBSP}234,5`);
    assert.equal(LT.formatNumber('1234.5', 'en'), '1,234.5');
    assert.equal(LT.formatNumber('abc', 'fr'), '');
    assert.equal(LT.formatNumber(null, 'fr'), '');

    assert.equal(LT.formatFileSize(512, 'fr'), `512${NBSP}o`);
    assert.equal(LT.formatFileSize(1536, 'fr'), `1,5${NBSP}Ko`);
    assert.equal(LT.formatFileSize(5 * 1024 * 1024, 'en'), `5${NBSP}MB`);
    assert.equal(LT.formatFileSize(-1, 'en'), '');
});

test('buildQuery skips empty values and encodes arrays', () => {
    assert.equal(LT.buildQuery({ q: 'a b&c', page: 2, empty: '', none: null, s: ['x', 'y'] }), 'q=a%20b%26c&page=2&s%5B%5D=x&s%5B%5D=y');
    assert.equal(LT.buildQuery({}), '');
});

test('joinUrl joins the base path', () => {
    assert.equal(LT.joinUrl('/Lettie/public/', '/api/mails'), '/Lettie/public/api/mails');
    assert.equal(LT.joinUrl('', 'api'), '/api');
    assert.equal(LT.joinUrl('/x', 'https://example.org/a'), 'https://example.org/a');
});

/** Transport double: records settings and resolves/rejects like jqXHR. */
function transport(outcome) {
    const calls = [];
    const fn = (settings) => {
        calls.push(settings);
        return {
            then(onOk, onFail) {
                if (outcome.ok) {
                    onOk(outcome.body);
                } else {
                    onFail(outcome.xhr);
                }
            }
        };
    };
    fn.calls = calls;
    return fn;
}

test('createApi sends CSRF and AJAX headers, and query strings for GET', async () => {
    const tr = transport({ ok: true, body: { data: [1] } });
    const api = LT.createApi({ transport: tr, csrfToken: () => 'tok', basePath: '/app' });

    assert.deepEqual(await api.get('/api/mails', { page: 2, q: '' }), { data: [1] });
    const s = tr.calls[0];
    assert.equal(s.url, '/app/api/mails?page=2');
    assert.equal(s.method, 'GET');
    assert.equal(s.data, undefined);
    assert.equal(s.headers['X-CSRF-Token'], 'tok');
    assert.equal(s.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(s.headers.Accept, 'application/json');
});

test('createApi tunnels PUT/PATCH/DELETE through POST with _method', async () => {
    const tr = transport({ ok: true, body: {} });
    const api = LT.createApi({ transport: tr, csrfToken: 'static' });

    await api.delete('/api/mails/3');
    await api.patch('/api/mails/3', { status: 'closed' });
    assert.deepEqual(tr.calls.map((c) => [c.method, c.data._method]), [['POST', 'DELETE'], ['POST', 'PATCH']]);
    assert.equal(tr.calls[1].data.status, 'closed');
    assert.equal(tr.calls[1].headers['X-CSRF-Token'], 'static');
});

test('createApi normalizes errors and calls hooks', async () => {
    const t = LT.createTranslator({ js: { errors: { validation: 'Champs invalides', session_expired: 'Expirée' } } });
    let unauthorized = 0;
    let expired = 0;
    const make = (xhr) => LT.createApi({
        transport: transport({ ok: false, xhr }),
        csrfToken: 'x',
        t,
        onUnauthorized: () => unauthorized++,
        onSessionExpired: () => expired++
    });

    await assert.rejects(make({ status: 422, responseJSON: { errors: { subject: ['Requis'] } } }).post('/a', {}), (e) => {
        assert.ok(e instanceof LT.ApiError);
        assert.equal(e.kind, 'validation');
        assert.equal(e.message, 'Champs invalides');
        assert.deepEqual(e.errors, { subject: ['Requis'] });
        return true;
    });
    await assert.rejects(make({ status: 401 }).get('/a'), { kind: 'unauthorized' });
    await assert.rejects(make({ status: 419 }).post('/a'), { kind: 'session_expired', message: 'Expirée' });
    await assert.rejects(make({ status: 0 }).get('/a'), { kind: 'network' });
    await assert.rejects(make({ status: 503, responseJSON: { error: 'Down' } }).get('/a'), { kind: 'server', message: 'Down' });
    assert.equal(unauthorized, 1);
    assert.equal(expired, 1);
});

test('createApi requires a transport', () => {
    assert.throws(() => LT.createApi({}), TypeError);
});

test('confirmAttributes reads data-lt-confirm-* from a dataset', () => {
    assert.deepEqual(LT.confirmAttributes({
        ltConfirm: 'Clôturer ?',
        ltConfirmTitle: 'Clôturer',
        ltConfirmDanger: '',
        ltConfirmInput: 'comment',
        ltConfirmInputLabel: 'Commentaire'
    }), {
        message: 'Clôturer ?',
        title: 'Clôturer',
        button: undefined,
        danger: true,
        input: 'comment',
        inputLabel: 'Commentaire',
        inputRequired: false
    });
    assert.equal(LT.confirmAttributes({ ltConfirm: 'x', ltConfirmDanger: 'false' }).danger, false);
    assert.equal(LT.confirmAttributes(undefined).message, '');
});

test('dialogSpec describes a confirmation with the Fiori rules', () => {
    const t = LT.createTranslator({ js: { ok: 'OK', confirm: { title: 'Confirmation', yes: 'Confirmer', no: 'Annuler', input_required: 'Obligatoire' }, dialog: { info: 'Information', warning: 'Attention', error: 'Erreur' } } });

    const simple = LT.dialogSpec({ confirm: true, attrs: { message: 'Rouvrir ?' } }, t);
    assert.equal(simple.title, 'Confirmation');
    assert.equal(simple.message, 'Rouvrir ?');
    assert.equal(simple.state, 'Critical');
    assert.equal(simple.confirmText, 'Confirmer');
    assert.equal(simple.confirmDesign, 'Emphasized');
    assert.equal(simple.cancelText, 'Annuler');
    assert.equal(simple.initialFocus, 'confirm');
    assert.equal(simple.input, null);

    const danger = LT.dialogSpec({ confirm: true, attrs: { message: 'Archiver ?', title: 'Archiver', button: 'Archiver', danger: true } }, t);
    assert.equal(danger.state, 'Negative');
    assert.equal(danger.confirmText, 'Archiver', 'the action carries its verb');
    assert.equal(danger.confirmDesign, 'Negative');
    assert.equal(danger.initialFocus, 'cancel', 'irreversible: cancel is the default');

    const withInput = LT.dialogSpec({ confirm: true, attrs: { message: 'Réaffecter ?', input: 'comment', inputLabel: 'Motif', inputRequired: true } }, t);
    assert.deepEqual(withInput.input, { name: 'comment', label: 'Motif', required: true, requiredMessage: 'Obligatoire', maxlength: 1000 });
    assert.equal(LT.dialogSpec({ confirm: true, attrs: { message: 'x', input: 'comment' } }, t).input.required, false, 'optional by default');
});

test('dialogSpec describes a message to acknowledge, with its semantic state', () => {
    const t = LT.createTranslator({ js: { ok: 'OK', dialog: { info: 'Information', warning: 'Attention', error: 'Erreur' } } });
    const error = LT.dialogSpec({ message: 'Refusé.', kind: 'error', details: ['Objet obligatoire.'] }, t);
    assert.equal(error.title, 'Erreur');
    assert.equal(error.state, 'Negative');
    assert.deepEqual(error.details, ['Objet obligatoire.']);
    assert.equal(error.cancelText, null, 'nothing to cancel');
    assert.equal(error.confirmText, 'OK');
    assert.equal(LT.dialogSpec({ message: 'x', kind: 'warning' }, t).state, 'Critical');
    assert.equal(LT.dialogSpec({ message: 'x' }, t).state, 'Information');
    assert.equal(LT.dialogSpec({ message: 'x', kind: 'nope' }, t).title, 'Information');
});

test('badgeText hides zero and caps at 99+', () => {
    assert.equal(LT.badgeText(0), '');
    assert.equal(LT.badgeText(-3), '');
    assert.equal(LT.badgeText(undefined), '');
    assert.equal(LT.badgeText('7'), '7');
    assert.equal(LT.badgeText(99), '99');
    assert.equal(LT.badgeText(100), '99+');
});

test('debounce calls once with the last arguments', async () => {
    const seen = [];
    const fn = LT.debounce((v) => seen.push(v), 10);
    fn(1);
    fn(2);
    fn(3);
    await new Promise((r) => setTimeout(r, 30));
    assert.deepEqual(seen, [3]);
});

test('safeStorage never throws', () => {
    const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
    const s = LT.safeStorage(broken);
    assert.equal(s.get('k', 'fallback'), 'fallback');
    assert.equal(s.set('k', 'v'), false);

    const map = new Map();
    const ok = LT.safeStorage({ getItem: (k) => (map.has(k) ? map.get(k) : null), setItem: (k, v) => map.set(k, v) });
    assert.equal(ok.set('k', 1), true);
    assert.equal(ok.get('k'), '1');
    assert.equal(LT.safeStorage(null).get('k', 'd'), 'd');
});
