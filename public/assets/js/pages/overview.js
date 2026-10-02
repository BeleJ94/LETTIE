/**
 * Overview Page charts (Chart.js). Colours come from the UI5 theme variables, read when a
 * chart is built and again when the theme changes: nothing is hard-coded
 * (docs/FIORI_DESIGN.md §3 and §15). The config builders are pure and tested with node:test;
 * the DOM part runs only in the browser on <canvas data-lt-ov-chart data-chart='…'>.
 */
(function (root, factory) {
    'use strict';
    var overview = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = overview;
    } else {
        root.LT = root.LT || {};
        root.LT.overview = overview;
        overview.start(root);
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** Theme variable of each colour role. Series use the ordered chart palette; states the semantic chart colours. */
    var VARIABLES = {
        incoming: '--sapChart_OrderedColor_1',
        outgoing: '--sapChart_OrderedColor_2',
        negative: '--sapChart_Bad',
        critical: '--sapChart_Critical',
        positive: '--sapChart_Good',
        text: '--sapContent_LabelColor',
        grid: '--sapList_BorderColor'
    };

    /**
     * @param {function(string): string} read returns the value of a CSS variable
     * @returns {Object<string, string>} colour role → value of the current theme
     */
    function palette(read) {
        var colours = {};
        Object.keys(VARIABLES).forEach(function (role) {
            colours[role] = String(read(VARIABLES[role]) || '').trim();
        });
        return colours;
    }

    /** "2026-W40" → "S40" (short: the card is small); anything else unchanged. */
    function weekLabel(key, t) {
        var m = /^(\d{4})-W(\d{2})$/.exec(key);
        return m ? t('js.stats.week', { week: +m[2], year: m[1] }).replace(' ' + m[1], '') : key;
    }

    function axes(colours, extra) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 200 },
            plugins: { legend: { position: 'bottom', labels: { color: colours.text, boxWidth: 12 } } },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { color: colours.text } },
                y: { stacked: true, beginAtZero: true, grid: { color: colours.grid }, ticks: { color: colours.text, precision: 0 } }
            }
        }, extra || {});
    }

    /** Stacked bars: incoming and outgoing mail per week. */
    function volumesConfig(data, colours, t) {
        return {
            type: 'bar',
            data: {
                labels: data.labels.map(function (key) { return weekLabel(key, t); }),
                datasets: [
                    { label: t('js.overview.incoming'), data: data.incoming, backgroundColor: colours.incoming },
                    { label: t('js.overview.outgoing'), data: data.outgoing, backgroundColor: colours.outgoing }
                ]
            },
            options: axes(colours)
        };
    }

    /** Horizontal bars: overdue mail per department, in the "negative" colour (it is a count of failures). */
    function overdueConfig(rows, colours, t) {
        var options = axes(colours, { indexAxis: 'y' });
        options.plugins = { legend: { display: false } };
        options.scales = {
            x: { beginAtZero: true, grid: { color: colours.grid }, ticks: { color: colours.text, precision: 0 } },
            y: { grid: { display: false }, ticks: { color: colours.text } }
        };
        return {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.name || t('js.overview.no_department'); }),
                datasets: [{ label: t('js.overview.overdue'), data: rows.map(function (row) { return row.count; }), backgroundColor: colours.negative }]
            },
            options: options
        };
    }

    var BUILDERS = { volumes: volumesConfig, overdue: overdueConfig };

    /* ------------------------------------------------------- browser only */

    function start(window) {
        var document = window.document;
        var charts = [];

        function draw() {
            if (!window.Chart) {
                return;
            }
            var style = window.getComputedStyle(document.documentElement);
            var colours = palette(function (name) { return style.getPropertyValue(name); });
            charts.forEach(function (chart) { chart.destroy(); });
            charts = Array.prototype.map.call(document.querySelectorAll('canvas[data-lt-ov-chart]'), function (canvas) {
                var build = BUILDERS[canvas.getAttribute('data-lt-ov-chart')];
                var data = JSON.parse(canvas.getAttribute('data-chart') || 'null');
                return new window.Chart(canvas, build(data, colours, window.LT.t));
            });
            fit(25);
        }

        /**
         * A chart created before its card is laid out measures an empty container and stays 0 × 0:
         * measure again until every chart has a size (the cards render shortly after UI5 starts).
         */
        function fit(attempts) {
            var empty = charts.filter(function (chart) { return chart.width === 0; });
            if (empty.length === 0 || attempts === 0) {
                return;
            }
            empty.forEach(function (chart) { chart.resize(); });
            window.setTimeout(function () { fit(attempts - 1); }, 200);
        }

        function ready() {
            draw();
            // The theme variables change shortly after the event (the theme is loaded on demand).
            document.addEventListener('lt:theme-change', function () {
                window.setTimeout(draw, 400);
            });
        }

        if (document.documentElement.classList.contains('lt-ui5-ready')) {
            ready();
        } else {
            document.addEventListener('lt:ui5-ready', ready, { once: true });
        }
    }

    return { VARIABLES: VARIABLES, palette: palette, weekLabel: weekLabel, volumesConfig: volumesConfig, overdueConfig: overdueConfig, start: start };
});
