/**
 * Lettie List Report: pure logic (no DOM) of the Fiori list screens
 * (docs/FIORI_DESIGN.md, "Modèle List Report"). The page wiring lives in
 * pages/list-report.js; this module is tested with node:test.
 *
 * - tagDesign / dueDesign: value → semantic state of a ui5-tag (ObjectStatus)
 * - buildQuery: list state → query of the server table contract
 *   (GET ?page=&per_page=&sort=&dir=&q=&<filters>)
 * - filtersFromSearch: deep link (?status=registered&mine=1&q=…) → filter values
 * - createVariantStore: named filter variants kept per device
 * - visibleColumns, toggleSort, countTitle, bulkSummary: table helpers
 *
 * Browser: window.LT.listReport (needs lt-core.js). Node: module.exports.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('./lt-core.js'));
    } else {
        root.LT = root.LT || {};
        root.LT.listReport = factory(root.LT);
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    var STANDARD = '*standard*';
    var MAX_VARIANTS = 20;
    var MAX_NAME = 60;

    /**
     * Semantic states (docs/FIORI_DESIGN.md §5). One place for every list:
     * a status is never given a colour by hand.
     */
    var TAG_DESIGNS = {
        status: {
            registered: 'Information',
            assigned: 'Information',
            in_progress: 'Information',
            awaiting_reply: 'Critical',
            answered: 'Positive',
            closed: 'Positive',
            archived: 'Neutral'
        },
        priority: { urgent: 'Negative', high: 'Critical', normal: 'Neutral', low: 'Neutral' },
        direction: { incoming: 'Set2', outgoing: 'Set2' },
        user_status: { active: 'Positive', inactive: 'Neutral' }
    };
    /** Colour scheme of the "Set2" tags (categories, not states). */
    var TAG_SCHEMES = { direction: { incoming: '6', outgoing: '9' } };
    var DUE_DESIGNS = { overdue: 'Negative', soon: 'Critical' };
    var FINISHED = ['answered', 'closed', 'archived'];

    /** @returns {{design: string, colorScheme: ?string}} */
    function tagDesign(kind, value) {
        var designs = TAG_DESIGNS[kind] || {};
        return {
            design: designs[value] || 'Neutral',
            colorScheme: (TAG_SCHEMES[kind] || {})[value] || null
        };
    }

    /**
     * State of a due date: none when the mail is finished or has no due date.
     * @returns {''|'Negative'|'Critical'}
     */
    function dueDesign(dueDate, status, now, timeZone) {
        if (!dueDate || FINISHED.indexOf(status) !== -1) {
            return '';
        }
        return DUE_DESIGNS[core.dueStatus(dueDate, now, timeZone)] || '';
    }

    function isEmpty(value) {
        return value === undefined || value === null || value === '' || value === false;
    }

    /** Filter values without the empty ones; booleans become "1". */
    function cleanFilters(filters) {
        var out = {};
        Object.keys(filters || {}).sort().forEach(function (name) {
            var value = filters[name];
            if (!isEmpty(value)) {
                out[name] = value === true ? '1' : String(value);
            }
        });
        return out;
    }

    /**
     * @param {{page?: number, perPage?: number, sort?: string, dir?: string, search?: string, filters?: Object}} state
     * @returns {Object} query parameters of the server table contract
     */
    function buildQuery(state) {
        var query = { page: Math.max(1, state.page || 1), per_page: state.perPage || 25 };
        if (state.sort) {
            query.sort = state.sort;
            query.dir = state.dir === 'asc' ? 'asc' : 'desc';
        }
        var search = String(state.search || '').trim();
        if (search !== '') {
            query.q = search;
        }
        var filters = cleanFilters(state.filters);
        Object.keys(filters).forEach(function (name) {
            if (['page', 'per_page', 'sort', 'dir', 'q'].indexOf(name) === -1) {
                query[name] = filters[name];
            }
        });
        return query;
    }

    /**
     * Deep links (home tiles, global search): values of the known filters, and the search term.
     * @param {string} search location.search
     * @param {string[]} names filter names of the screen
     * @returns {{filters: Object, search: string, any: boolean}}
     */
    function filtersFromSearch(search, names) {
        var filters = {};
        var term = '';
        String(search || '').replace(/^\?/, '').split('&').forEach(function (pair) {
            if (pair === '') {
                return;
            }
            var index = pair.indexOf('=');
            var decode = function (text) {
                try {
                    return decodeURIComponent(text.replace(/\+/g, ' '));
                } catch (e) {
                    return '';
                }
            };
            var name = decode(index === -1 ? pair : pair.slice(0, index));
            var value = index === -1 ? '' : decode(pair.slice(index + 1));
            if (name === 'q') {
                term = value.trim();
            } else if (names.indexOf(name) !== -1 && value !== '' && value !== '0') {
                filters[name] = value;
            }
        });
        return { filters: filters, search: term, any: term !== '' || Object.keys(filters).length > 0 };
    }

    /** Next sort when a column is chosen: same column flips the direction, another one starts ascending. */
    function toggleSort(current, column) {
        if (current && current.sort === column) {
            return { sort: column, dir: current.dir === 'asc' ? 'desc' : 'asc' };
        }
        return { sort: column, dir: 'asc' };
    }

    /**
     * Columns to show, in their declared order. A column marked `required` cannot be hidden,
     * and at least one column always remains.
     * @param {{name: string, required?: boolean}[]} columns
     * @param {string[]} hidden
     */
    function visibleColumns(columns, hidden) {
        var hide = hidden || [];
        var visible = columns.filter(function (column) {
            return column.required || hide.indexOf(column.name) === -1;
        });
        return visible.length > 0 ? visible : columns.slice(0, 1);
    }

    /** "Courriers (46)": table title with its counter; no counter while it is unknown. */
    function countTitle(title, count, locale) {
        if (typeof count !== 'number') {
            return title;
        }
        return title + ' (' + core.formatNumber(count, locale) + ')';
    }

    /**
     * Outcome of a bulk action, from the server answer {done: [ids], failed: [{id, message}]}.
     * @param {Object<string, string>} references id → reference, for the messages
     * @param {function(string, string): string} [format] (reference, message) → one line; translated by the caller
     * @returns {{done: number, failed: number, state: 'Positive'|'Critical'|'Negative', details: string[]}}
     */
    function bulkSummary(result, references, format) {
        var done = (result && result.done ? result.done : []).length;
        var failures = result && result.failed ? result.failed : [];
        return {
            done: done,
            failed: failures.length,
            state: failures.length === 0 ? 'Positive' : (done === 0 ? 'Negative' : 'Critical'),
            details: failures.map(function (failure) {
                var reference = (references || {})[failure.id];
                var label = reference ? reference : '#' + failure.id;
                return format ? format(label, failure.message) : label + ': ' + failure.message;
            })
        };
    }

    /**
     * Named variants of one list (filters, search, sort, hidden columns), kept per device.
     * "Standard" (no filter, default sort) always exists and is not stored.
     *
     * @param {{get: function(string, *): *, set: function(string, string): void}} storage LT.safeStorage
     * @param {string} listKey e.g. "mails"
     */
    function createVariantStore(storage, listKey) {
        var key = 'lt.variants.' + listKey;

        function read() {
            var data;
            try {
                data = JSON.parse(storage.get(key, '') || '{}');
            } catch (e) {
                data = {};
            }
            var variants = Array.isArray(data.variants) ? data.variants.filter(function (v) {
                return v && typeof v.name === 'string' && v.name !== '' && v.state && typeof v.state === 'object';
            }) : [];
            var names = variants.map(function (v) { return v.name; });
            return {
                variants: variants,
                defaultName: names.indexOf(data.defaultName) !== -1 ? data.defaultName : STANDARD
            };
        }

        function write(data) {
            storage.set(key, JSON.stringify(data));
        }

        function normalize(name) {
            return String(name || '').replace(/\s+/g, ' ').trim().slice(0, MAX_NAME);
        }

        return {
            STANDARD: STANDARD,
            /** @returns {{name: string, state: Object}[]} sorted by name */
            list: function () {
                return read().variants.slice().sort(function (a, b) {
                    return a.name.localeCompare(b.name);
                });
            },
            get: function (name) {
                return read().variants.filter(function (v) { return v.name === name; })[0] || null;
            },
            defaultName: function () {
                return read().defaultName;
            },
            /**
             * Creates or replaces a variant.
             * @returns {{ok: boolean, name?: string, error?: 'empty'|'reserved'|'full'}}
             */
            save: function (name, state, makeDefault) {
                var clean = normalize(name);
                if (clean === '') {
                    return { ok: false, error: 'empty' };
                }
                if (clean === STANDARD) {
                    return { ok: false, error: 'reserved' };
                }
                var data = read();
                var others = data.variants.filter(function (v) { return v.name !== clean; });
                if (others.length >= MAX_VARIANTS) {
                    return { ok: false, error: 'full' };
                }
                others.push({ name: clean, state: JSON.parse(JSON.stringify(state)) });
                write({ variants: others, defaultName: makeDefault ? clean : data.defaultName });
                return { ok: true, name: clean };
            },
            remove: function (name) {
                var data = read();
                write({
                    variants: data.variants.filter(function (v) { return v.name !== name; }),
                    defaultName: data.defaultName === name ? STANDARD : data.defaultName
                });
            },
            setDefault: function (name) {
                var data = read();
                write({ variants: data.variants, defaultName: name });
            }
        };
    }

    /** True when two list states differ (the current variant is then shown as modified). */
    function isModified(saved, current) {
        var pick = function (state) {
            var s = state || {};
            return JSON.stringify({
                filters: cleanFilters(s.filters),
                search: String(s.search || '').trim(),
                sort: s.sort || '',
                dir: s.dir || '',
                hidden: (s.hidden || []).slice().sort()
            });
        };
        return pick(saved) !== pick(current);
    }

    return {
        STANDARD: STANDARD,
        MAX_VARIANTS: MAX_VARIANTS,
        TAG_DESIGNS: TAG_DESIGNS,
        tagDesign: tagDesign,
        dueDesign: dueDesign,
        cleanFilters: cleanFilters,
        buildQuery: buildQuery,
        filtersFromSearch: filtersFromSearch,
        toggleSort: toggleSort,
        visibleColumns: visibleColumns,
        countTitle: countTitle,
        bulkSummary: bulkSummary,
        createVariantStore: createVariantStore,
        isModified: isModified
    };
});
