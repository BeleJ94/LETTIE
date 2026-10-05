/**
 * Lettie UI bootstrap (browser only): wires lt-core/lt-tables to the DOM,
 * jQuery, DataTables, Lucide and the UI5 dialogs. No inline script is allowed by
 * the CSP: pages configure behaviour with data-* attributes.
 *
 *   <body data-base-path data-locale data-timezone data-i18n='{"js":…}'>
 *   <ui5-button data-lt-toggle="nav">         collapses/expands the side navigation (ui5-navigation-layout)
 *   <… data-lt-theme-toggle>                  light ↔ dark theme (lt-theme.js, remembered)
 *   <… data-lt-locale="fr|en">                switches the language (hidden form #lt-locale-form)
 *   <ui5-shellbar-search data-lt-search data-url="/mails">  global search → list filtered by ?q=
 *   <… data-lt-href="/path">                  UI5 tile header or list item that opens a page
 *   <form data-lt-confirm="message">          ui5-dialog confirmation before submit
 *         [data-lt-confirm-title|-button|-danger|-input="field"|-input-label|-input-required]
 *   <table data-lt-table data-url="/api/…">   server-side DataTable;
 *       <th data-lt-name="subject" data-lt-render="link:/mails/{id}" data-lt-sortable="false">
 *       (always data-lt-*: DataTables reads plain data-* attributes of <th> as column options)
 *   <div data-lt-flash="success">message</div> toast on load
 */
(function (window, document, $) {
    'use strict';

    var LT = window.LT;
    var body = document.body;

    /* -------------------------------------------------------------- config */

    function parseJson(text) {
        try {
            return JSON.parse(text || '{}');
        } catch (e) {
            return {};
        }
    }

    var config = {
        basePath: body.getAttribute('data-base-path') || '',
        locale: body.getAttribute('data-locale') || 'fr',
        timeZone: body.getAttribute('data-timezone') || 'UTC'
    };
    var t = LT.createTranslator(parseJson(body.getAttribute('data-i18n')));
    var storage = LT.safeStorage(window.localStorage ? (function () {
        try { return window.localStorage; } catch (e) { return null; }
    })() : null);

    LT.config = config;
    LT.t = t;
    LT.ctx = { locale: config.locale, timeZone: config.timeZone, basePath: config.basePath, t: t };

    /* ------------------------------------------------------------------ ui */

    function el(tag, attributes, text) {
        var node = document.createElement(tag);
        Object.keys(attributes || {}).forEach(function (name) {
            var value = attributes[name];
            if (value !== undefined && value !== null && value !== false) {
                node.setAttribute(name, value === true ? '' : value);
            }
        });
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    var dialogCount = 0;

    /**
     * Opens a ui5-dialog built from a spec (see LT.dialogSpec) and resolves when it closes:
     * {confirmed: boolean, value: string} (value: the optional comment).
     * Footer: the action first, "Cancel" last, both to the right (docs/FIORI_DESIGN.md §5).
     */
    function openDialog(spec) {
        // UI5 failed to load: the browser's own dialogs keep the action possible.
        if (!window.customElements || !window.customElements.get('ui5-dialog')) {
            return Promise.resolve({ confirmed: spec.cancelText ? window.confirm(spec.message) : (window.alert(spec.message), true), value: '' });
        }
        return new Promise(function (resolve) {
            var id = 'lt-dialog-' + (++dialogCount);
            var dialog = el('ui5-dialog', { 'header-text': spec.title, state: spec.state, 'accessible-name': spec.title });
            var body = el('div', { 'class': 'lt-dialog-form' });
            var footer = el('div', { slot: 'footer', 'class': 'lt-dialog-footer' });
            var confirm = el('ui5-button', { id: id + '-ok', design: spec.confirmDesign }, spec.confirmText);
            var input = null;
            var result = { confirmed: false, value: '' };

            body.appendChild(el('ui5-text', {}, spec.message));
            if (spec.details && spec.details.length) {
                var list = el('ui5-list', { separators: 'None', 'accessible-name': spec.title });
                spec.details.forEach(function (detail) {
                    list.appendChild(el('ui5-li', { type: 'Inactive', 'wrapping-type': 'Normal' }, detail));
                });
                body.appendChild(list);
            }
            if (spec.input) {
                body.appendChild(el('ui5-label', { 'for': id + '-input', 'show-colon': true, required: spec.input.required }, spec.input.label));
                input = el('ui5-textarea', { id: id + '-input', rows: 3, maxlength: spec.input.maxlength, required: spec.input.required });
                body.appendChild(input);
            }
            footer.appendChild(confirm);
            if (spec.cancelText) {
                var cancel = el('ui5-button', { id: id + '-cancel', design: 'Transparent' }, spec.cancelText);
                cancel.addEventListener('click', function () {
                    dialog.open = false;
                });
                footer.appendChild(cancel);
            }
            dialog.setAttribute('initial-focus', id + (spec.initialFocus === 'cancel' && spec.cancelText ? '-cancel' : (input ? '-input' : '-ok')));

            confirm.addEventListener('click', function () {
                var value = input ? String(input.value || '').trim() : '';
                if (input && spec.input.required && value === '') {
                    var message = input.querySelector('[slot="valueStateMessage"]') || input.appendChild(el('div', { slot: 'valueStateMessage' }));
                    message.textContent = spec.input.requiredMessage;
                    input.valueState = 'Negative';
                    input.focus();
                    return;
                }
                result = { confirmed: true, value: value };
                dialog.open = false;
            });
            dialog.addEventListener('close', function () {
                dialog.remove();
                resolve(result);
            });
            dialog.appendChild(body);
            dialog.appendChild(footer);
            document.body.appendChild(dialog);
            dialog.open = true;
        });
    }

    LT.ui = {
        /** Short confirmation of a finished action (ui5-toast): disappears by itself, never for an error. */
        toast: function (message) {
            if (!window.customElements || !window.customElements.get('ui5-toast')) {
                return;
            }
            var toast = el('ui5-toast', { duration: 4000, placement: 'BottomCenter' }, message);
            toast.addEventListener('close', function () {
                toast.remove();
            });
            document.body.appendChild(toast);
            toast.open = true;
        },
        /** A message the user must acknowledge. kind: 'info' | 'warning' | 'error'. */
        alert: function (message, kind) {
            return openDialog(LT.dialogSpec({ message: message, kind: kind || 'info' }, t)).then(function () {
                return undefined;
            });
        },
        /** Yes/no question; resolves to true when confirmed. */
        confirm: function (message) {
            return openDialog(LT.dialogSpec({ message: message, confirm: true }, t)).then(function (result) {
                return result.confirmed;
            });
        },
        /** Shows an LT.ApiError (validation errors are listed). */
        error: function (error) {
            var details = error && error.errors ? Object.keys(error.errors).map(function (field) {
                return [].concat(error.errors[field]).join(' ');
            }) : [];
            return openDialog(LT.dialogSpec({
                message: error && error.message ? error.message : t('js.errors.server'),
                kind: 'error',
                details: details
            }, t)).then(function () {
                return undefined;
            });
        },
        icons: function () {
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons({ attrs: { 'aria-hidden': 'true', focusable: 'false' } });
            }
        }
    };

    /* ----------------------------------------------------------------- api */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    LT.api = LT.createApi({
        transport: $.ajax,
        csrfToken: csrfToken,
        basePath: config.basePath,
        t: t,
        onUnauthorized: function () {
            window.location.assign(LT.joinUrl(config.basePath, '/login'));
        },
        onSessionExpired: function () {
            LT.ui.alert(t('js.errors.session_expired'), 'warning').then(function () {
                window.location.reload();
            });
        }
    });

    /* --------------------------------------------------------------- shell */

    function go(path) {
        window.location.assign(LT.joinUrl(config.basePath, path));
    }

    /** UI5 elements fire their own "click": listen on the element, not by delegation. */
    function onUi5(selector, eventName, handler) {
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
            el.addEventListener(eventName, function (e) {
                handler(el, e);
            });
        });
    }

    /* Side navigation: collapsed or expanded is remembered on the device (a collapsed menu leaves its width to the tables). */
    var NAV_KEY = 'lt.nav';
    function restoreNav() {
        var layout = document.getElementById('lt-layout');
        if (layout && LT.ui.storage.get(NAV_KEY, '') === 'collapsed') {
            layout.setAttribute('mode', 'Collapsed');
        }
    }
    onUi5('[data-lt-toggle="nav"]', 'click', function () {
        var layout = document.getElementById('lt-layout');
        var collapse = !layout.isSideCollapsed();
        layout.mode = collapse ? 'Collapsed' : 'Expanded';
        LT.ui.storage.set(NAV_KEY, collapse ? 'collapsed' : 'expanded');
    });

    /** Shortcut of the side navigation that matches the address (same parameters, any order): it takes the selection. */
    function selectNavShortcut(nav) {
        var current = window.location.search.replace(/^\?/, '').split('&').filter(Boolean).sort().join('&');
        Array.prototype.forEach.call(nav.querySelectorAll('[data-lt-nav-query]'), function (item) {
            if (current !== '' && item.getAttribute('data-lt-nav-query').split('&').sort().join('&') === current) {
                Array.prototype.forEach.call(item.parentNode.children, function (sibling) {
                    sibling.removeAttribute('selected');
                });
                item.setAttribute('selected', '');
            }
        });
    }

    /** Counters of the side navigation ("Mes retards (3)"), refreshed like the bell. */
    function watchNavCounts(nav) {
        function refresh() {
            if (document.hidden) {
                return;
            }
            LT.api.get(nav.getAttribute('data-url')).then(function (json) {
                Array.prototype.forEach.call(nav.querySelectorAll('[data-count]'), function (item) {
                    var count = Number(json[item.getAttribute('data-count')]) || 0;
                    var label = item.getAttribute('data-label');
                    item.setAttribute('text', count > 0 ? t('js.nav.count', { label: label, count: LT.badgeText(count) }) : label);
                });
            }, function () {
                // Silent: the next refresh will try again.
            });
        }
        refresh();
        window.setInterval(refresh, 60000);
    }

    function switchLocale(locale) {
        var form = document.getElementById('lt-locale-form');
        form.elements.locale.value = locale;
        form.submit();
    }

    onUi5('ui5-shellbar-item[data-lt-theme-toggle]', 'click', function () {
        LT.theme.toggle();
    });
    onUi5('ui5-shellbar-item[data-lt-locale]', 'click', function (el) {
        switchLocale(el.getAttribute('data-lt-locale'));
    });

    onUi5('#lt-shellbar', 'profile-click', function () {
        document.getElementById('lt-user-menu').open = true;
    });
    onUi5('#lt-shellbar', 'notifications-click', function (el) {
        go(el.getAttribute('data-href'));
    });
    onUi5('#lt-user-menu', 'item-click', function (el, e) {
        var item = e.detail.item;
        if (item.hasAttribute('data-lt-theme-toggle')) {
            LT.theme.toggle();
        } else if (item.hasAttribute('data-lt-locale')) {
            switchLocale(item.getAttribute('data-lt-locale'));
        } else if (item.hasAttribute('data-lt-href')) {
            go(item.getAttribute('data-lt-href'));
        }
    });
    onUi5('#lt-user-menu', 'sign-out-click', function () {
        document.getElementById('lt-logout-form').submit();
    });

    onUi5('[data-lt-search]', 'search', function (el) {
        var term = (el.value || '').trim();
        if (term !== '') {
            go(el.getAttribute('data-url') + '?q=' + encodeURIComponent(term));
        }
    });

    /* Tiles (interactive card headers) and navigation list items. */
    onUi5('ui5-card-header[data-lt-href], ui5-toolbar-button[data-lt-href], ui5-button[data-lt-href]', 'click', function (el) {
        go(el.getAttribute('data-lt-href'));
    });
    LT.ui.go = go;
    LT.ui.t = t;
    LT.ui.storage = storage;
    onUi5('ui5-list[data-lt-links]', 'item-click', function (el, e) {
        var href = e.detail.item.getAttribute('data-lt-href');
        if (href) {
            go(href);
        }
    });

    /* --------------------------------------------------------------- theme */

    function showTheme() {
        var dark = /_(dark|hcb)$/.test(LT.theme.current());
        $('[data-lt-theme-toggle]').attr('icon', dark ? 'light-mode' : 'dark-mode')
            .attr('text', dark ? t('js.theme.light') : t('js.theme.dark'));
    }

    showTheme();
    document.addEventListener('lt:theme-change', showTheme);

    function updateOnline() {
        body.classList.toggle('lt-offline', !window.navigator.onLine);
    }
    window.addEventListener('online', function () {
        updateOnline();
        LT.ui.toast(t('js.online'));
    });
    window.addEventListener('offline', updateOnline);
    updateOnline();

    /* --------------------------------------------------------------- forms */

    $(document).on('submit', 'form', function (e) {
        var form = this;
        if (form.hasAttribute('data-lt-confirm') && form.getAttribute('data-lt-confirmed') !== '1') {
            e.preventDefault();
            LT.ui.confirmForm(form).then(function (ok) {
                if (ok) {
                    form.setAttribute('data-lt-confirmed', '1');
                    lockSubmit(form);
                    // Native submit: no second "submit" event, works even with an input named "submit".
                    HTMLFormElement.prototype.submit.call(form);
                }
            });
            return;
        }
        lockSubmit(form);
    });

    /**
     * Confirmation in a ui5-dialog, configured by the form's data-lt-confirm-* attributes
     * (see LT.dialogSpec). An optional comment is copied into the form.
     */
    LT.ui.confirmForm = function (form) {
        var attrs = LT.confirmAttributes(form.dataset);
        return openDialog(LT.dialogSpec({ message: attrs.message, confirm: true, attrs: attrs }, t)).then(function (result) {
            if (!result.confirmed) {
                return false;
            }
            if (attrs.input) {
                var field = form.elements.namedItem(attrs.input);
                if (!field) {
                    field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = attrs.input;
                    form.appendChild(field);
                }
                field.value = result.value || '';
            }
            return true;
        });
    };

    /** Prevents double submission. */
    function lockSubmit(form) {
        $(form).find('button[type="submit"], input[type="submit"]').prop('disabled', true).addClass('is-loading');
    }

    // Back/forward cache restores disabled buttons: re-enable them.
    window.addEventListener('pageshow', function () {
        $('form [type="submit"]').prop('disabled', false).removeClass('is-loading');
        $('form[data-lt-confirmed]').removeAttr('data-lt-confirmed');
    });

    /* -------------------------------------------------------------- tables */

    function columnDescriptors(table) {
        return $(table).find('thead th').map(function () {
            return {
                name: this.getAttribute('data-lt-name'),
                title: $(this).text().trim(),
                render: this.getAttribute('data-lt-render') || 'text',
                sortable: this.getAttribute('data-lt-sortable') !== 'false',
                className: this.getAttribute('data-lt-class') || ''
            };
        }).get();
    }

    function filtersFor(table) {
        var formId = table.getAttribute('data-filters');
        var form = formId ? document.getElementById(formId) : null;
        return function () {
            var values = {};
            if (form) {
                $(form).serializeArray().forEach(function (field) {
                    if (field.name !== '_csrf') {
                        values[field.name] = field.value;
                    }
                });
            }
            return values;
        };
    }

    /**
     * Deep links (home tiles, global search): copies the query string into the filter form
     * (?status=registered&mine=1) and returns the search term (?q=…) for the table.
     */
    function prefillFromUrl(form) {
        var params = new window.URLSearchParams(window.location.search);
        if (form) {
            params.forEach(function (value, name) {
                var field = form.elements[name];
                if (!field || name === 'q') {
                    return;
                }
                if (field.type === 'checkbox') {
                    field.checked = value !== '' && value !== '0';
                } else {
                    field.value = value;
                }
            });
        }
        return (params.get('q') || '').trim();
    }

    LT.initTable = function (table) {
        if (!window.DataTable || $(table).data('ltTable')) {
            return $(table).data('ltTable');
        }
        var sortIndex = table.getAttribute('data-order-column');
        var initialSearch = prefillFromUrl(document.getElementById(table.getAttribute('data-filters') || ''));
        var options = LT.tables.buildOptions({
            url: table.getAttribute('data-url'),
            columns: columnDescriptors(table),
            api: LT.api,
            t: t,
            ctx: LT.ctx,
            pageLength: Number(table.getAttribute('data-page-length')) || undefined,
            order: sortIndex !== null ? [[Number(sortIndex), table.getAttribute('data-order-dir') || 'asc']] : undefined,
            filters: filtersFor(table),
            onError: LT.ui.error
        });
        if (initialSearch !== '') {
            options.search = { search: initialSearch };
        }
        var instance = new window.DataTable(table, options);
        $(table).data('ltTable', instance);

        var filterForm = document.getElementById(table.getAttribute('data-filters') || '');
        if (filterForm) {
            $(filterForm).on('change', ':input', LT.debounce(function () {
                instance.ajax.reload();
            }, 250));
            $(filterForm).on('reset', function () {
                // Field values are cleared after the event: reload on the next tick.
                window.setTimeout(function () {
                    instance.ajax.reload();
                }, 0);
            });
            $(filterForm).on('submit', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                instance.ajax.reload();
            });
        }
        return instance;
    };

    /* -------------------------------------------------------------- exports */

    var loadedScripts = {};

    /** Loads a local script once (CSP: same origin only). */
    LT.loadScript = function (path) {
        if (!loadedScripts[path]) {
            loadedScripts[path] = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = LT.joinUrl(config.basePath, path);
                script.onload = resolve;
                script.onerror = function () { reject(new Error('Cannot load ' + path)); };
                document.head.appendChild(script);
            });
        }
        return loadedScripts[path];
    };

    var LIBRARIES = {
        // ~950 KB and ~2.2 MB: loaded only when an export is requested.
        xlsx: ['/assets/vendor/exceljs-4.4.0/exceljs.min.js'],
        pdf: ['/assets/vendor/pdfmake-0.2.14/pdfmake.min.js', '/assets/vendor/pdfmake-0.2.14/vfs_fonts.js']
    };

    function loadLibraries(format) {
        // In order: vfs_fonts.js needs pdfmake.
        return LIBRARIES[format].reduce(function (chain, path) {
            return chain.then(function () { return LT.loadScript(path); });
        }, Promise.resolve());
    }

    function saveBlob(blob, name) {
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
    }

    /** Query parameters for an export: filter form + current table search and sort. */
    function exportParams(button) {
        var params = {};
        var form = document.getElementById(button.getAttribute('data-filters') || '');
        if (form) {
            $(form).serializeArray().forEach(function (f) {
                if (f.name !== '_csrf') {
                    params[f.name] = f.value;
                }
            });
        }
        var table = document.querySelector(button.getAttribute('data-table') || '[data-none]');
        var instance = table ? $(table).data('ltTable') : null;
        if (instance && instance.ajax && instance.ajax.params()) {
            var server = LT.tables.toServerParams(instance.ajax.params());
            ['sort', 'dir', 'q'].forEach(function (k) {
                if (server[k] !== undefined) {
                    params[k] = server[k];
                }
            });
        }
        return params;
    }

    /**
     * Exports a list to Excel or PDF: fetches the rows (same filters as the screen),
     * loads the library on demand and saves the file.
     *
     * @param {{format: 'xlsx'|'pdf', source: string, set: string, params: Object, title: string, subtitle?: string}} options
     *        `set` (LT.export.COLUMN_SETS), `title` and `subtitle` may contain "{field}" placeholders filled from `params`.
     * @returns {Promise<void>} always resolved: failures are shown to the user
     */
    LT.ui.exportList = function (options) {
        var format = options.format;
        var params = options.params || {};
        var set = String(options.set || '').replace(/\{(\w+)\}/g, function (m, k) { return params[k] || ''; });
        var columns = LT.export.COLUMN_SETS[set];
        if (!columns || !LIBRARIES[format]) {
            return Promise.resolve();
        }
        // "{from}" → formatted date; "{direction}" → translated enum label (enums.direction.incoming).
        var fill = function (text) {
            return String(text || '').replace(/\{(\w+)\}/g, function (m, field) {
                var value = params[field] || '';
                if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    return LT.formatDate(value, LT.ctx);
                }
                var key = 'enums.' + field + '.' + value;
                var label = t(key);
                return label === key ? value : label;
            });
        };
        var ctx = Object.assign({}, LT.ctx, { now: new Date() });

        return Promise.all([LT.api.get(options.source, params), loadLibraries(format)])
            .then(function (results) {
                var json = results[0];
                var spec = {
                    title: fill(options.title),
                    subtitle: fill(options.subtitle),
                    columns: columns,
                    rows: json.data || [],
                    meta: json.meta
                };
                if (json.meta && json.meta.truncated) {
                    LT.ui.toast(t('js.export.truncated', { limit: json.meta.limit }), 'warning');
                }
                var name = LT.export.fileName(spec.title, format, ctx.now, ctx.timeZone);
                if (format === 'xlsx') {
                    return LT.export.writeWorkbook(window.ExcelJS, spec, ctx).xlsx.writeBuffer().then(function (buffer) {
                        saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), name);
                    });
                }
                window.pdfMake.createPdf(LT.export.pdfDefinition(spec, ctx)).download(name);
                return null;
            })
            .catch(function (error) {
                LT.ui.error(error && error.kind ? error : { message: t('js.export.failed') });
            });
    };

    /**
     * <button data-lt-export="xlsx|pdf" data-source="/mails/export" data-set="mails"
     *         data-title="…" [data-subtitle] [data-filters="form-id"] [data-table="#table"]>
     * data-set may contain "{field}" placeholders filled from the filter form (register_{direction}).
     * (List Report screens call LT.ui.exportList themselves: pages/list-report.js.)
     */
    $(document).on('click', 'button[data-lt-export]', function () {
        var button = this;
        $(button).prop('disabled', true).addClass('is-loading');
        LT.ui.exportList({
            format: button.getAttribute('data-lt-export'),
            source: button.getAttribute('data-source'),
            set: button.getAttribute('data-set'),
            params: exportParams(button),
            title: button.getAttribute('data-title'),
            subtitle: button.getAttribute('data-subtitle')
        }).then(function () {
            $(button).prop('disabled', false).removeClass('is-loading');
        });
    });

    /* Print buttons (registration slip). ?print=1 opens the dialog on load. */
    $(document).on('click', '[data-lt-print]', function () {
        window.print();
    });

    /* -------------------------------------------------------- notifications */

    var POLL_MS = 60000;

    /** Refreshes the bell badge now, then every minute while the tab is visible. */
    function watchNotifications(link) {
        var url = link.getAttribute('data-url');
        var timer = null;

        function refresh() {
            LT.api.get(url).then(function (json) {
                // ui5-shellbar shows the badge of its bell from this attribute (empty: no badge).
                link.setAttribute('notifications-count', LT.badgeText(json.unread));
            }, function () {
                // Silent: the next poll will try again (errors are shown by real actions).
            });
        }

        function start() {
            if (timer === null) {
                refresh();
                timer = window.setInterval(refresh, POLL_MS);
            }
        }

        function stop() {
            window.clearInterval(timer);
            timer = null;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                start();
            }
        });
        start();
    }

    /* ---------------------------------------------------------------- boot */

    $(function () {
        LT.ui.icons();
        if (document.querySelector('[data-lt-print-auto]') && /[?&]print=1\b/.test(window.location.search)) {
            window.setTimeout(function () { window.print(); }, 300);
        }
        $('[data-lt-notifications]').each(function () {
            watchNotifications(this);
        });
        restoreNav();
        $('[data-lt-nav-counts]').each(function () {
            selectNavShortcut(this);
            if (this.querySelector('[data-count]')) {
                watchNavCounts(this);
            }
        });
        $('table[data-lt-table][data-url]').each(function () {
            LT.initTable(this);
        });
        $('[data-lt-flash]').each(function () {
            LT.ui.toast($(this).text().trim(), this.getAttribute('data-lt-flash') || 'success');
        });
    });
})(window, document, window.jQuery);
