/**
 * Lettie tables module: pure helpers for server-side DataTables.
 *
 * Server contract (JSON endpoints):
 *   request  GET ?page=1&per_page=25&sort=received_at&dir=desc&q=text&<filters>
 *   response {"data": [...], "meta": {"total": 120, "filtered": 12}}
 *
 * Browser: window.LT.tables (needs lt-core.js loaded first). Node: module.exports.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('./lt-core.js'));
    } else {
        root.LT = root.LT || {};
        root.LT.tables = factory(root.LT);
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    var MAX_PER_PAGE = 100;
    var DEFAULT_PER_PAGE = 25;

    /* ------------------------------------------------------ request/response */

    /**
     * DataTables ajax "data" object -> compact query parameters.
     * Only columns declared sortable can be used as sort key.
     */
    function toServerParams(dt, extra) {
        var length = Number(dt && dt.length);
        var perPage = length > 0 ? Math.min(length, MAX_PER_PAGE) : DEFAULT_PER_PAGE;
        var start = Math.max(0, Number(dt && dt.start) || 0);
        var params = {
            page: Math.floor(start / perPage) + 1,
            per_page: perPage
        };

        var order = dt && Array.isArray(dt.order) ? dt.order[0] : null;
        var columns = dt && Array.isArray(dt.columns) ? dt.columns : [];
        if (order && columns[order.column] && columns[order.column].orderable !== false) {
            var column = columns[order.column];
            var key = column.name || column.data;
            if (typeof key === 'string' && /^[a-z_][a-z0-9_]*$/i.test(key)) {
                params.sort = key;
                params.dir = String(order.dir).toLowerCase() === 'desc' ? 'desc' : 'asc';
            }
        }

        var search = dt && dt.search && typeof dt.search.value === 'string' ? dt.search.value.trim() : '';
        if (search !== '') {
            params.q = search;
        }

        var filters = typeof extra === 'function' ? extra() : extra;
        Object.keys(filters || {}).forEach(function (name) {
            if (['page', 'per_page', 'sort', 'dir', 'q'].indexOf(name) === -1) {
                params[name] = filters[name];
            }
        });
        return params;
    }

    function toDataTablesResult(json, draw) {
        var body = json && typeof json === 'object' ? json : {};
        var data = Array.isArray(body.data) ? body.data : [];
        var meta = body.meta && typeof body.meta === 'object' ? body.meta : {};
        var total = Number(meta.total);
        var filtered = Number(meta.filtered);
        total = isFinite(total) ? total : data.length;
        return {
            draw: Number(draw) || 0,
            recordsTotal: total,
            recordsFiltered: isFinite(filtered) ? filtered : total,
            data: data
        };
    }

    function emptyResult(draw) {
        return { draw: Number(draw) || 0, recordsTotal: 0, recordsFiltered: 0, data: [] };
    }

    /* ------------------------------------------------------------ language */

    function languageFor(t) {
        return {
            search: t('js.table.search'),
            searchPlaceholder: t('js.table.search_placeholder'),
            lengthMenu: t('js.table.length_menu'),
            info: t('js.table.info'),
            infoEmpty: t('js.table.info_empty'),
            infoFiltered: t('js.table.info_filtered'),
            zeroRecords: t('js.table.zero_records'),
            emptyTable: t('js.table.empty'),
            loadingRecords: t('js.loading'),
            processing: t('js.loading'),
            paginate: {
                first: t('js.table.first'),
                last: t('js.table.last'),
                next: t('js.table.next'),
                previous: t('js.table.previous')
            },
            aria: {
                orderable: t('js.table.sort'),
                orderableReverse: t('js.table.sort_reverse')
            }
        };
    }

    /* ----------------------------------------------------------- renderers */

    /** Wraps a display formatter: sort/filter/type requests get the raw value. */
    function displayOnly(format) {
        return function (data, type, row) {
            return type === 'display' ? format(data, row) : data;
        };
    }

    /**
     * Renderer factories. ctx = {locale, timeZone, basePath, t, now}.
     * Every renderer returns escaped HTML.
     */
    function createRenderers(ctx) {
        var c = ctx || {};
        var t = c.t || function (key) { return key; };
        return {
            text: function () {
                return displayOnly(function (v) { return core.escapeHtml(v); });
            },
            date: function () {
                return displayOnly(function (v) {
                    return core.escapeHtml(core.formatDate(v, c));
                });
            },
            datetime: function () {
                return displayOnly(function (v) {
                    return core.escapeHtml(core.formatDateTime(v, c));
                });
            },
            number: function () {
                return displayOnly(function (v) {
                    return core.escapeHtml(core.formatNumber(v, c.locale));
                });
            },
            filesize: function () {
                return displayOnly(function (v) {
                    return core.escapeHtml(core.formatFileSize(v, c.locale));
                });
            },
            /** badge:<prefix> -> <span class="lt-badge lt-badge--<value>">t(prefix.value)</span> */
            badge: function (prefix) {
                return displayOnly(function (v) {
                    if (v === null || v === undefined || v === '') {
                        return '';
                    }
                    var value = String(v);
                    var cls = value.replace(/[^a-z0-9_-]/gi, '');
                    var label = prefix ? t(prefix + '.' + value) : value;
                    return '<span class="lt-badge lt-badge--' + cls + '">' + core.escapeHtml(label) + '</span>';
                });
            },
            /** due date with overdue/soon highlighting */
            due: function () {
                return displayOnly(function (v) {
                    var text = core.formatDate(v, c);
                    if (text === '') {
                        return '';
                    }
                    var status = core.dueStatus(v, c.now ? c.now() : new Date(), c.timeZone);
                    return '<span class="lt-due lt-due--' + status + '">' + core.escapeHtml(text) + '</span>';
                });
            },
            /** link:<path with {field}> e.g. link:/mails/{id} */
            link: function (template) {
                return displayOnly(function (v, row) {
                    if (v === null || v === undefined || v === '') {
                        return '';
                    }
                    var path = String(template || '').replace(/\{([a-z0-9_]+)\}/gi, function (m, field) {
                        return encodeURIComponent(row && row[field] !== undefined ? row[field] : '');
                    });
                    return '<a href="' + core.escapeHtml(core.joinUrl(c.basePath, path)) + '">' + core.escapeHtml(v) + '</a>';
                });
            }
        };
    }

    /** "badge:mail.status" -> renderer; unknown names fall back to text. */
    function resolveRenderer(spec, renderers) {
        var s = String(spec || 'text');
        var i = s.indexOf(':');
        var name = i === -1 ? s : s.slice(0, i);
        var arg = i === -1 ? undefined : s.slice(i + 1);
        var factory = Object.prototype.hasOwnProperty.call(renderers, name) ? renderers[name] : renderers.text;
        return factory(arg);
    }

    /**
     * Column definitions from header descriptors
     * [{name, title, render, sortable, className, priority}].
     */
    function buildColumns(descriptors, renderers) {
        return descriptors.map(function (d) {
            if (!d || typeof d.name !== 'string' || d.name === '') {
                throw new Error('Every table column needs a data-name');
            }
            return {
                data: d.name,
                name: d.name,
                title: d.title,
                orderable: d.sortable !== false,
                searchable: false,
                className: d.className || '',
                defaultContent: '',
                render: resolveRenderer(d.render, renderers)
            };
        });
    }

    /* ------------------------------------------------------------- options */

    /**
     * Full DataTables options for a server-side table.
     *
     * @param {{url: string, columns: Array, api: {get: Function}, t: Function, ctx?: Object,
     *          pageLength?: number, order?: Array, filters?: Object|Function, onError?: Function}} config
     */
    function buildOptions(config) {
        var t = config.t || function (key) { return key; };
        var renderers = createRenderers(Object.assign({ t: t }, config.ctx || {}));
        var columns = buildColumns(config.columns, renderers);
        var titles = columns.map(function (c) { return c.title || ''; });

        var firstSortable = columns.findIndex(function (c) { return c.orderable; });
        var order = config.order || (firstSortable === -1 ? [] : [[firstSortable, 'asc']]);

        return {
            serverSide: true,
            processing: true,
            searchDelay: 350,
            pageLength: Math.min(config.pageLength || DEFAULT_PER_PAGE, MAX_PER_PAGE),
            lengthMenu: [10, 25, 50, 100],
            order: order,
            autoWidth: false,
            columns: columns,
            language: languageFor(t),
            ajax: function (data, callback) {
                config.api.get(config.url, toServerParams(data, config.filters)).then(
                    function (json) {
                        callback(toDataTablesResult(json, data.draw));
                    },
                    function (error) {
                        if (config.onError) {
                            config.onError(error);
                        }
                        callback(emptyResult(data.draw));
                    }
                );
            },
            // Labels used by the stacked (card) layout on small screens.
            createdRow: function (row) {
                var cells = row.children || [];
                for (var i = 0; i < cells.length; i++) {
                    if (titles[i]) {
                        cells[i].setAttribute('data-label', titles[i]);
                    }
                }
            }
        };
    }

    return {
        MAX_PER_PAGE: MAX_PER_PAGE,
        toServerParams: toServerParams,
        toDataTablesResult: toDataTablesResult,
        emptyResult: emptyResult,
        languageFor: languageFor,
        createRenderers: createRenderers,
        resolveRenderer: resolveRenderer,
        buildColumns: buildColumns,
        buildOptions: buildOptions
    };
});
