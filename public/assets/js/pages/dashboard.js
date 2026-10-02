/**
 * Statistics page (Chart.js). Pure chart-config builders are exported for
 * node:test; the DOM part runs only in the browser on [data-lt-stats].
 *
 * Server: GET /statistics/data?from=&to=&granularity=&department_id= (see StatsService::dashboard).
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('../lt-core.js'));
    } else {
        root.LT = root.LT || {};
        root.LT.dashboard = factory(root.LT);
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    /**
     * Theme variable of each colour role (docs/FIORI_DESIGN.md §3): no colour is written here.
     * Series use the ordered chart palette, states the semantic chart colours.
     */
    var VARIABLES = {
        incoming: '--sapChart_OrderedColor_1',
        outgoing: '--sapChart_OrderedColor_2',
        primary: '--sapChart_OrderedColor_1',
        warning: '--sapChart_Critical',
        danger: '--sapChart_Bad',
        severe: '--sapIndicationColor_1',
        muted: '--sapChart_Neutral',
        grid: '--sapList_BorderColor',
        text: '--sapContent_LabelColor'
    };

    /**
     * @param {function(string): string} read returns the value of a CSS variable of the current theme
     * @returns {Object<string, string>} colour role → value
     */
    function themePalette(read) {
        var colours = {};
        Object.keys(VARIABLES).forEach(function (role) {
            colours[role] = String(read(VARIABLES[role]) || '').trim();
        });
        return colours;
    }

    function tr(t) {
        return t || function (key) { return key; };
    }

    /** "2026-09-30" → "30/09"; "2026-W40" → "S40 2026"; "2026-09" → "sept. 2026". */
    function periodLabel(key, granularity, locale, t) {
        var m;
        if (granularity === 'week' && (m = /^(\d{4})-W(\d{2})$/.exec(key))) {
            return tr(t)('js.stats.week', { week: +m[2], year: m[1] });
        }
        if (granularity === 'month' && (m = /^(\d{4})-(\d{2})$/.exec(key))) {
            return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { month: 'short', year: 'numeric', timeZone: 'UTC' })
                .format(new Date(Date.UTC(+m[1], +m[2] - 1, 1)));
        }
        if ((m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key))) {
            return m[3] + '/' + m[2];
        }
        return key;
    }

    function baseOptions(palette, extra) {
        var options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            plugins: { legend: { position: 'bottom', labels: { color: palette.text, boxWidth: 12 } } },
            scales: {
                x: { grid: { color: palette.grid }, ticks: { color: palette.text } },
                y: { beginAtZero: true, grid: { color: palette.grid }, ticks: { color: palette.text, precision: 0 } }
            }
        };
        return Object.assign(options, extra || {});
    }

    function departmentName(name, t) {
        return name === null || name === undefined || name === '' ? tr(t)('js.stats.no_department') : name;
    }

    /** Stacked bars: incoming / outgoing per period. */
    function volumeChart(data, t, palette, locale) {
        var p = palette;
        var options = baseOptions(p);
        options.scales.x.stacked = true;
        options.scales.y.stacked = true;
        return {
            type: 'bar',
            data: {
                labels: data.volumes.labels.map(function (k) { return periodLabel(k, data.period.granularity, locale, t); }),
                datasets: [
                    { label: tr(t)('enums.direction.incoming'), data: data.volumes.incoming, backgroundColor: p.incoming, borderRadius: 3 },
                    { label: tr(t)('enums.direction.outgoing'), data: data.volumes.outgoing, backgroundColor: p.outgoing, borderRadius: 3 }
                ]
            },
            options: options
        };
    }

    /** Horizontal grouped bars: volumes per department. */
    function departmentChart(data, t, palette) {
        var p = palette;
        var rows = data.departments.slice(0, 12);
        var options = baseOptions(p, { indexAxis: 'y' });
        options.scales = {
            x: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 } },
            y: { grid: { display: false }, ticks: { color: p.text } }
        };
        return {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return departmentName(r.name, t); }),
                datasets: [
                    { label: tr(t)('enums.direction.incoming'), data: rows.map(function (r) { return r.incoming; }), backgroundColor: p.incoming, borderRadius: 3 },
                    { label: tr(t)('enums.direction.outgoing'), data: rows.map(function (r) { return r.outgoing; }), backgroundColor: p.outgoing, borderRadius: 3 },
                    { label: tr(t)('js.stats.overdue_now'), data: rows.map(function (r) { return r.overdue; }), backgroundColor: p.danger, borderRadius: 3 }
                ]
            },
            options: options
        };
    }

    /** Average processing time (days) per department. */
    function processingChart(data, t, palette) {
        var p = palette;
        var rows = data.processing.by_department.slice(0, 12);
        var options = baseOptions(p, { indexAxis: 'y' });
        options.plugins.legend = { display: false };
        options.scales = {
            x: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text },
                title: { display: true, text: tr(t)('js.stats.days'), color: p.text } },
            y: { grid: { display: false }, ticks: { color: p.text } }
        };
        return {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return departmentName(r.name, t); }),
                datasets: [{ label: tr(t)('js.stats.average_days'), data: rows.map(function (r) { return r.average_days; }), backgroundColor: p.primary, borderRadius: 3 }]
            },
            options: options
        };
    }

    /** Doughnut: overdue pending mail by age. */
    function overdueChart(data, t, palette) {
        var p = palette;
        var keys = Object.keys(data.overdue.buckets);
        return {
            type: 'doughnut',
            data: {
                labels: keys.map(function (k) { return tr(t)('js.stats.bucket', { range: k }); }),
                datasets: [{ data: keys.map(function (k) { return data.overdue.buckets[k]; }), backgroundColor: [p.warning, p.danger, p.severe], borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { color: p.text, boxWidth: 12 } } }
            }
        };
    }

    /** Top correspondents (total, stacked by direction). */
    function correspondentsChart(data, t, palette) {
        var p = palette;
        var rows = data.correspondents;
        var options = baseOptions(p, { indexAxis: 'y' });
        options.scales = {
            x: { stacked: true, beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 } },
            y: { stacked: true, grid: { display: false }, ticks: { color: p.text } }
        };
        return {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.name; }),
                datasets: [
                    { label: tr(t)('enums.direction.incoming'), data: rows.map(function (r) { return r.incoming; }), backgroundColor: p.incoming, borderRadius: 3 },
                    { label: tr(t)('enums.direction.outgoing'), data: rows.map(function (r) { return r.outgoing; }), backgroundColor: p.outgoing, borderRadius: 3 }
                ]
            },
            options: options
        };
    }

    /** Headline figures. */
    function kpis(data, t, locale) {
        var n = function (v) { return core.formatNumber(v, locale); };
        var days = function (v) { return v === null ? '—' : tr(t)('js.stats.days_value', { value: core.formatNumber(v, locale, { maximumFractionDigits: 1 }) }); };
        return [
            { key: 'incoming', label: tr(t)('js.stats.kpi_incoming'), value: n(data.totals.incoming) },
            { key: 'outgoing', label: tr(t)('js.stats.kpi_outgoing'), value: n(data.totals.outgoing) },
            { key: 'processing', label: tr(t)('js.stats.kpi_processing'), value: days(data.processing.average_days),
                hint: data.processing.median_days === null ? '' : tr(t)('js.stats.median', { value: days(data.processing.median_days) }) },
            { key: 'overdue', label: tr(t)('js.stats.kpi_overdue'), value: n(data.overdue.total),
                hint: data.processing.late_rate === null ? '' : tr(t)('js.stats.late_rate', { rate: core.formatNumber(data.processing.late_rate, locale) }) }
        ];
    }

    /** Nothing to draw: no label, or every value is zero/empty (an all-zero doughnut is a blank circle). */
    function isEmptyChart(config) {
        if (!config.data.labels.length) {
            return true;
        }
        return !config.data.datasets.some(function (d) {
            return d.data.some(function (v) { return Number(v) > 0; });
        });
    }

    /** Accessible data table equivalent of a chart config (shown under each chart). */
    function tableFromChart(config) {
        var datasets = config.data.datasets;
        return {
            headers: [''].concat(datasets.map(function (d) { return d.label || ''; })),
            rows: config.data.labels.map(function (label, i) {
                return [String(label)].concat(datasets.map(function (d) {
                    var v = d.data[i];
                    return v === null || v === undefined ? '' : String(v);
                }));
            })
        };
    }

    /* ------------------------------------------------------------------ DOM */

    function initDom(window, document, $) {
        var LT = window.LT;
        var root = document.querySelector('[data-lt-stats]');
        if (!root || !window.Chart) {
            return;
        }
        var form = document.getElementById(root.getAttribute('data-filters'));
        var charts = {};
        var palette = {};
        var lastData = null;
        // Colours and font are read from the theme each time the charts are drawn.
        function readTheme() {
            var styles = window.getComputedStyle(document.documentElement);
            var read = function (name) { return styles.getPropertyValue(name); };
            palette = themePalette(read);
            window.Chart.defaults.font.family = String(read('--sapFontFamily') || '').trim();
        }
        readTheme();
        var builders = {
            volumes: volumeChart,
            departments: departmentChart,
            processing: processingChart,
            overdue: overdueChart,
            correspondents: correspondentsChart
        };

        function renderTable(container, table) {
            var html = '<table class="lt-table lt-table--compact"><thead><tr>'
                + table.headers.map(function (h) { return '<th scope="col">' + LT.escapeHtml(h) + '</th>'; }).join('')
                + '</tr></thead><tbody>'
                + table.rows.map(function (r) {
                    return '<tr>' + r.map(function (c, i) { return (i === 0 ? '<th scope="row">' : '<td>') + LT.escapeHtml(c) + (i === 0 ? '</th>' : '</td>'); }).join('') + '</tr>';
                }).join('')
                + '</tbody></table>';
            container.innerHTML = html;
        }

        function render(data) {
            lastData = data;
            var kpiBox = root.querySelector('[data-lt-kpis]');
            kpiBox.innerHTML = kpis(data, LT.t, LT.config.locale).map(function (k) {
                return '<div class="lt-stat lt-stat--' + k.key + '"><span class="lt-stat__value">' + LT.escapeHtml(k.value)
                    + '</span><span class="lt-stat__label">' + LT.escapeHtml(k.label) + '</span>'
                    + (k.hint ? '<span class="lt-stat__hint">' + LT.escapeHtml(k.hint) + '</span>' : '') + '</div>';
            }).join('');

            Object.keys(builders).forEach(function (name) {
                var canvas = root.querySelector('[data-chart="' + name + '"]');
                if (!canvas) {
                    return;
                }
                var config = builders[name](data, LT.t, palette, LT.config.locale);
                var empty = isEmptyChart(config);
                canvas.closest('.lt-chart-card').classList.toggle('is-empty', empty);
                if (charts[name]) {
                    charts[name].destroy();
                }
                charts[name] = empty ? null : new window.Chart(canvas, config);
                var tableBox = root.querySelector('[data-chart-table="' + name + '"]');
                if (tableBox) {
                    renderTable(tableBox, tableFromChart(config));
                }
            });
        }

        function load() {
            root.classList.add('is-loading');
            var params = {};
            $(form).serializeArray().forEach(function (f) {
                if (f.name !== '_csrf') {
                    params[f.name] = f.value;
                }
            });
            LT.api.get(root.getAttribute('data-url'), params).then(function (data) {
                root.classList.remove('is-loading');
                render(data);
            }, function (error) {
                root.classList.remove('is-loading');
                LT.ui.error(error);
            });
        }

        // The theme variables change shortly after the event (the theme is loaded on demand).
        document.addEventListener('lt:theme-change', function () {
            window.setTimeout(function () {
                readTheme();
                if (lastData) {
                    render(lastData);
                }
            }, 400);
        });
        $(form).on('change', ':input', LT.debounce(load, 300));
        $(form).on('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            load();
        });
        load();
    }

    if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.jQuery) {
        // The colours are theme variables: wait until UI5 has applied the theme.
        window.jQuery(function () {
            if (document.documentElement.classList.contains('lt-ui5-ready')) {
                initDom(window, document, window.jQuery);
            } else {
                document.addEventListener('lt:ui5-ready', function () {
                    initDom(window, document, window.jQuery);
                }, { once: true });
            }
        });
    }

    return {
        VARIABLES: VARIABLES,
        themePalette: themePalette,
        periodLabel: periodLabel,
        volumeChart: volumeChart,
        departmentChart: departmentChart,
        processingChart: processingChart,
        overdueChart: overdueChart,
        correspondentsChart: correspondentsChart,
        kpis: kpis,
        isEmptyChart: isEmptyChart,
        tableFromChart: tableFromChart
    };
});
