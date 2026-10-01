/**
 * Lettie UI bootstrap (browser only): wires lt-core/lt-tables to the DOM,
 * jQuery, DataTables, SweetAlert2 and Lucide. No inline script is allowed by
 * the CSP: pages configure behaviour with data-* attributes.
 *
 *   <body data-base-path data-locale data-timezone data-i18n='{"js":…}'>
 *   <button data-lt-toggle="nav">             mobile navigation drawer
 *   <button data-lt-theme-toggle>             light ↔ dark theme (lt-theme.js, remembered)
 *   <form data-lt-confirm="message">          SweetAlert2 confirmation before submit
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

    var toast = window.Swal ? window.Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
    }) : null;

    LT.ui = {
        toast: function (message, icon) {
            if (toast) {
                toast.fire({ icon: icon || 'success', title: message });
            }
        },
        alert: function (message, icon) {
            if (!window.Swal) {
                window.alert(message);
                return Promise.resolve();
            }
            return window.Swal.fire({ icon: icon || 'info', text: message, confirmButtonText: t('js.ok') });
        },
        confirm: function (message) {
            if (!window.Swal) {
                return Promise.resolve(window.confirm(message));
            }
            return window.Swal.fire({
                icon: 'warning',
                title: t('js.confirm.title'),
                text: message,
                showCancelButton: true,
                confirmButtonText: t('js.confirm.yes'),
                cancelButtonText: t('js.confirm.no'),
                reverseButtons: true,
                focusCancel: true
            }).then(function (result) {
                return result.isConfirmed === true;
            });
        },
        /** Shows an LT.ApiError (validation errors are listed). */
        error: function (error) {
            var message = error && error.message ? error.message : t('js.errors.server');
            var details = error && error.errors ? Object.keys(error.errors).map(function (field) {
                return [].concat(error.errors[field]).join(' ');
            }) : [];
            if (details.length && window.Swal) {
                return window.Swal.fire({
                    icon: 'error',
                    title: message,
                    html: '<ul class="lt-error-list">' + details.map(function (d) {
                        return '<li>' + LT.escapeHtml(d) + '</li>';
                    }).join('') + '</ul>',
                    confirmButtonText: t('js.ok')
                });
            }
            return LT.ui.alert(message, 'error');
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

    /* ---------------------------------------------------------- navigation */

    function setNav(open) {
        body.classList.toggle('lt-nav-open', open);
        $('[data-lt-toggle="nav"]').attr('aria-expanded', open ? 'true' : 'false');
    }

    $(document).on('click', '[data-lt-toggle="nav"]', function () {
        setNav(!body.classList.contains('lt-nav-open'));
    });
    $(document).on('click', '.lt-backdrop', function () {
        setNav(false);
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && body.classList.contains('lt-nav-open')) {
            setNav(false);
        }
    });

    /* --------------------------------------------------------------- theme */

    function showTheme() {
        var dark = /_(dark|hcb)$/.test(LT.theme.current());
        $('[data-lt-theme-toggle]').attr('aria-pressed', dark ? 'true' : 'false')
            .attr('title', dark ? t('js.theme.light') : t('js.theme.dark'));
    }

    showTheme();
    document.addEventListener('lt:theme-change', showTheme);
    $(document).on('click', '[data-lt-theme-toggle]', function () {
        LT.theme.toggle();
    });

    function updateOnline() {
        body.classList.toggle('lt-offline', !window.navigator.onLine);
    }
    window.addEventListener('online', function () {
        updateOnline();
        LT.ui.toast(t('js.online'), 'success');
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
     * SweetAlert2 confirmation configured by the form's data-lt-confirm-* attributes
     * (see LT.confirmOptions). An optional textarea value is copied into the form.
     */
    LT.ui.confirmForm = function (form) {
        var attrs = LT.confirmAttributes(form.dataset);
        if (!window.Swal) {
            return Promise.resolve(window.confirm(attrs.message));
        }
        return window.Swal.fire(LT.confirmOptions(attrs, t)).then(function (result) {
            if (result.isConfirmed !== true) {
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

    LT.initTable = function (table) {
        if (!window.DataTable || $(table).data('ltTable')) {
            return $(table).data('ltTable');
        }
        var sortIndex = table.getAttribute('data-order-column');
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
     * <button data-lt-export="xlsx|pdf" data-source="/mails/export" data-set="mails"
     *         data-title="…" [data-subtitle] [data-filters="form-id"] [data-table="#table"]>
     * data-set may contain "{field}" placeholders filled from the filter form (register_{direction}).
     */
    $(document).on('click', '[data-lt-export]', function () {
        var button = this;
        var format = button.getAttribute('data-lt-export');
        var params = exportParams(button);
        var set = button.getAttribute('data-set').replace(/\{(\w+)\}/g, function (m, k) { return params[k] || ''; });
        var columns = LT.export.COLUMN_SETS[set];
        if (!columns || !LIBRARIES[format]) {
            return;
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
        $(button).prop('disabled', true).addClass('is-loading');

        Promise.all([LT.api.get(button.getAttribute('data-source'), params), loadLibraries(format)])
            .then(function (results) {
                var json = results[0];
                var spec = {
                    title: fill(button.getAttribute('data-title')),
                    subtitle: fill(button.getAttribute('data-subtitle')),
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
            })
            .then(function () {
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
        var badge = link.querySelector('[data-lt-notifications-count]');
        var url = link.getAttribute('data-url');
        var timer = null;

        function refresh() {
            LT.api.get(url).then(function (json) {
                var text = LT.badgeText(json.unread);
                badge.textContent = text;
                badge.hidden = text === '';
                link.setAttribute('aria-label', t('js.notifications', { count: json.unread || 0 }));
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
        $('table[data-lt-table]').each(function () {
            LT.initTable(this);
        });
        $('[data-lt-flash]').each(function () {
            LT.ui.toast($(this).text().trim(), this.getAttribute('data-lt-flash') || 'success');
        });
    });
})(window, document, window.jQuery);
