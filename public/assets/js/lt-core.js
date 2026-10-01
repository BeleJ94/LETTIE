/**
 * Lettie core module: pure helpers (no DOM, no jQuery) usable in the browser
 * (global window.LT) and in Node (module.exports) for node:test.
 *
 * - createTranslator: same key/placeholder rules as App\Core\Translator
 * - createApi: AJAX wrapper around an injected transport ($.ajax) that adds
 *   the CSRF token and normalizes errors
 * - format*: dates (server sends UTC "YYYY-MM-DD HH:MM:SS"), numbers, sizes
 */
(function (root, factory) {
    'use strict';
    var LT = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = LT;
    } else {
        root.LT = Object.assign(root.LT || {}, LT);
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var INTL_LOCALES = { fr: 'fr-FR', en: 'en-GB' };
    var SIZE_UNITS = {
        fr: ['o', 'Ko', 'Mo', 'Go', 'To'],
        en: ['B', 'KB', 'MB', 'GB', 'TB']
    };
    var OVERRIDDEN_METHODS = ['PUT', 'PATCH', 'DELETE'];

    function intlLocale(locale) {
        return INTL_LOCALES[locale] || locale || 'fr-FR';
    }

    /* ---------------------------------------------------------------- text */

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function lookup(dict, key) {
        if (!dict) {
            return undefined;
        }
        if (Object.prototype.hasOwnProperty.call(dict, key)) {
            return dict[key];
        }
        var value = dict;
        var parts = key.split('.');
        for (var i = 0; i < parts.length; i++) {
            if (value === null || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, parts[i])) {
                return undefined;
            }
            value = value[parts[i]];
        }
        return value;
    }

    /** t('js.errors.validation', {count: 2}); missing keys return the key. */
    function createTranslator(dict) {
        return function t(key, replace) {
            var line = lookup(dict, key);
            if (typeof line !== 'string') {
                return key;
            }
            if (replace) {
                // Whole placeholder names only: ":page" must not eat the start of ":pages".
                line = line.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, function (match, name) {
                    return Object.prototype.hasOwnProperty.call(replace, name) ? String(replace[name]) : match;
                });
            }
            return line;
        };
    }

    /* --------------------------------------------------------------- dates */

    /**
     * "YYYY-MM-DD" -> local calendar date (no time zone shift).
     * "YYYY-MM-DD HH:MM[:SS]" or ISO -> instant, interpreted as UTC when no offset is given.
     */
    function parseServerDate(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        if (value instanceof Date) {
            return isNaN(value.getTime()) ? null : value;
        }
        var text = String(value).trim();
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/.exec(text);
        if (!m) {
            return null;
        }
        var date;
        if (m[4] === undefined) {
            date = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
            date.ltDateOnly = true;
        } else if (m[7]) {
            date = new Date(text.replace(' ', 'T'));
        } else {
            date = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)));
        }
        if (isNaN(date.getTime())) {
            return null;
        }
        // Reject impossible dates such as 2026-02-30.
        if (m[4] === undefined && date.getUTCDate() !== +m[3]) {
            return null;
        }
        return date;
    }

    function formatDate(value, options) {
        var date = parseServerDate(value);
        if (!date) {
            return '';
        }
        var opts = options || {};
        return new Intl.DateTimeFormat(intlLocale(opts.locale), {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            // A date-only value is a calendar day: never shift it.
            timeZone: date.ltDateOnly ? 'UTC' : (opts.timeZone || 'UTC')
        }).format(date);
    }

    function formatDateTime(value, options) {
        var date = parseServerDate(value);
        if (!date) {
            return '';
        }
        var opts = options || {};
        if (date.ltDateOnly) {
            return formatDate(value, opts);
        }
        return new Intl.DateTimeFormat(intlLocale(opts.locale), {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: opts.timeZone || 'UTC'
        }).format(date);
    }

    /** Calendar day "YYYY-MM-DD" of an instant in a time zone. */
    function calendarDay(date, timeZone) {
        var parts = new Intl.DateTimeFormat('en-CA', {
            year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timeZone || 'UTC'
        }).formatToParts(date);
        var get = function (type) {
            return parts.filter(function (p) { return p.type === type; })[0].value;
        };
        return get('year') + '-' + get('month') + '-' + get('day');
    }

    /** Whole days from today (in timeZone) to a due date; negative when overdue. */
    function daysUntil(dueValue, now, timeZone) {
        var due = parseServerDate(dueValue);
        if (!due) {
            return null;
        }
        var dueDay = due.ltDateOnly ? String(dueValue).slice(0, 10) : calendarDay(due, timeZone);
        var today = calendarDay(now || new Date(), timeZone);
        return Math.round((Date.parse(dueDay + 'T00:00:00Z') - Date.parse(today + 'T00:00:00Z')) / 86400000);
    }

    /** "overdue" | "soon" (0..soonDays) | "ok" | null */
    function dueStatus(dueValue, now, timeZone, soonDays) {
        var days = daysUntil(dueValue, now, timeZone);
        if (days === null) {
            return null;
        }
        if (days < 0) {
            return 'overdue';
        }
        return days <= (soonDays === undefined ? 2 : soonDays) ? 'soon' : 'ok';
    }

    /* ------------------------------------------------------------- numbers */

    function formatNumber(value, locale, options) {
        var n = typeof value === 'number' ? value : Number(value);
        if (value === null || value === '' || !isFinite(n)) {
            return '';
        }
        return new Intl.NumberFormat(intlLocale(locale), options || {}).format(n);
    }

    function formatFileSize(bytes, locale) {
        var n = Number(bytes);
        if (bytes === null || bytes === '' || !isFinite(n) || n < 0) {
            return '';
        }
        var units = SIZE_UNITS[locale] || SIZE_UNITS.en;
        var i = 0;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        return formatNumber(n, locale, { maximumFractionDigits: i === 0 ? 0 : 1 }) + ' ' + units[i];
    }

    /* ----------------------------------------------------------------- url */

    /** Query string without leading "?"; skips null/undefined/""; arrays as key[]=. */
    function buildQuery(params) {
        var pairs = [];
        Object.keys(params || {}).forEach(function (key) {
            var value = params[key];
            if (value === null || value === undefined || value === '') {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    pairs.push(encodeURIComponent(key + '[]') + '=' + encodeURIComponent(item));
                });
                return;
            }
            pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        });
        return pairs.join('&');
    }

    function joinUrl(basePath, path) {
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        return String(basePath || '').replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
    }

    /* ----------------------------------------------------------------- api */

    function ApiError(kind, status, message, errors) {
        this.name = 'ApiError';
        this.kind = kind;
        this.status = status;
        this.message = message;
        this.errors = errors || {};
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    var ERROR_KINDS = { 0: 'network', 401: 'unauthorized', 403: 'forbidden', 404: 'not_found', 419: 'session_expired', 422: 'validation' };

    /** Converts a failed jqXHR-like object ({status, responseJSON}) to an ApiError. */
    function normalizeError(xhr, t) {
        var status = xhr && typeof xhr.status === 'number' ? xhr.status : 0;
        var kind = ERROR_KINDS[status] || (status >= 500 ? 'server' : 'http');
        var body = xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object' ? xhr.responseJSON : {};
        var translate = t || function (key) { return key; };
        var message = translate('js.errors.' + kind);
        if (message === 'js.errors.' + kind) {
            message = typeof body.error === 'string' ? body.error : 'HTTP ' + status;
        }
        return new ApiError(kind, status, message, body.errors);
    }

    /**
     * @param {{transport: function(Object): {then: Function}, csrfToken: string|function(): string,
     *          basePath?: string, t?: Function, onUnauthorized?: Function, onSessionExpired?: Function}} config
     */
    function createApi(config) {
        if (!config || typeof config.transport !== 'function') {
            throw new TypeError('createApi needs a transport function');
        }

        function token() {
            return typeof config.csrfToken === 'function' ? config.csrfToken() : (config.csrfToken || '');
        }

        function request(method, path, data) {
            method = String(method || 'GET').toUpperCase();
            var payload = data ? Object.assign({}, data) : {};
            var httpMethod = method;
            // PHP only parses bodies of POST requests: tunnel other verbs (see App\Core\Request).
            if (OVERRIDDEN_METHODS.indexOf(method) !== -1) {
                payload._method = method;
                httpMethod = 'POST';
            }
            var url = joinUrl(config.basePath, path);
            if (httpMethod === 'GET' && data) {
                var query = buildQuery(payload);
                url += query ? (url.indexOf('?') === -1 ? '?' : '&') + query : '';
                payload = undefined;
            }

            var settings = {
                url: url,
                method: httpMethod,
                dataType: 'json',
                data: payload,
                headers: {
                    'X-CSRF-Token': token(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            };

            return new Promise(function (resolve, reject) {
                config.transport(settings).then(
                    function (body) {
                        resolve(body);
                    },
                    function (xhr) {
                        var error = normalizeError(xhr, config.t);
                        if (error.kind === 'unauthorized' && config.onUnauthorized) {
                            config.onUnauthorized(error);
                        } else if (error.kind === 'session_expired' && config.onSessionExpired) {
                            config.onSessionExpired(error);
                        }
                        reject(error);
                    }
                );
            });
        }

        return {
            request: request,
            get: function (path, query) { return request('GET', path, query); },
            post: function (path, data) { return request('POST', path, data); },
            put: function (path, data) { return request('PUT', path, data); },
            patch: function (path, data) { return request('PATCH', path, data); },
            'delete': function (path, data) { return request('DELETE', path, data); }
        };
    }

    /* ------------------------------------------------------- confirmations */

    /**
     * SweetAlert2 options for a confirmation, from a form's data attributes:
     *   data-lt-confirm="message"            (required)
     *   data-lt-confirm-title="…"            dialog title
     *   data-lt-confirm-button="…"           confirm button label
     *   data-lt-confirm-danger               red confirm button (irreversible action)
     *   data-lt-confirm-input="comment"      textarea copied into the form field "comment"
     *   data-lt-confirm-input-label="…"      its label
     *   data-lt-confirm-input-required       the textarea cannot be left empty
     *
     * @param {{message: string, title?: string, button?: string, danger?: boolean,
     *          input?: string, inputLabel?: string, inputRequired?: boolean}} attrs
     */
    function confirmOptions(attrs, t) {
        var translate = t || function (key) { return key; };
        var a = attrs || {};
        var options = {
            icon: a.danger ? 'warning' : 'question',
            title: a.title || translate('js.confirm.title'),
            text: a.message || '',
            showCancelButton: true,
            confirmButtonText: a.button || translate('js.confirm.yes'),
            cancelButtonText: translate('js.confirm.no'),
            reverseButtons: true,
            focusCancel: !!a.danger,
            customClass: { confirmButton: a.danger ? 'lt-swal-danger' : '' }
        };
        if (a.input) {
            options.input = 'textarea';
            options.inputLabel = a.inputLabel || '';
            options.inputAttributes = { maxlength: '1000', 'aria-label': a.inputLabel || a.input };
            if (a.inputRequired) {
                options.inputValidator = function (value) {
                    return String(value || '').trim() === '' ? translate('js.confirm.input_required') : undefined;
                };
            }
        }
        return options;
    }

    /** Reads confirmOptions() attributes from an element's dataset (DOMStringMap or plain object). */
    function confirmAttributes(dataset) {
        var d = dataset || {};
        var flag = function (v) { return v !== undefined && v !== null && v !== 'false'; };
        return {
            message: d.ltConfirm || '',
            title: d.ltConfirmTitle,
            button: d.ltConfirmButton,
            danger: flag(d.ltConfirmDanger),
            input: d.ltConfirmInput,
            inputLabel: d.ltConfirmInputLabel,
            inputRequired: flag(d.ltConfirmInputRequired)
        };
    }

    /* -------------------------------------------------------- notifications */

    /** Text of the bell badge: "" when nothing is unread, "99+" beyond 99. */
    function badgeText(count) {
        var n = Math.floor(Number(count));
        if (!isFinite(n) || n <= 0) {
            return '';
        }
        return n > 99 ? '99+' : String(n);
    }

    /* --------------------------------------------------------------- misc */

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            var context = this;
            clearTimeout(timer);
            timer = setTimeout(function () {
                timer = null;
                fn.apply(context, args);
            }, wait);
        };
    }

    /** Storage that never throws (private mode, blocked storage). */
    function safeStorage(storage) {
        return {
            get: function (key, fallback) {
                try {
                    var value = storage ? storage.getItem(key) : null;
                    return value === null ? fallback : value;
                } catch (e) {
                    return fallback;
                }
            },
            set: function (key, value) {
                try {
                    if (storage) {
                        storage.setItem(key, String(value));
                    }
                    return true;
                } catch (e) {
                    return false;
                }
            }
        };
    }

    return {
        version: '1.0.0',
        escapeHtml: escapeHtml,
        createTranslator: createTranslator,
        parseServerDate: parseServerDate,
        formatDate: formatDate,
        formatDateTime: formatDateTime,
        daysUntil: daysUntil,
        dueStatus: dueStatus,
        formatNumber: formatNumber,
        formatFileSize: formatFileSize,
        buildQuery: buildQuery,
        joinUrl: joinUrl,
        ApiError: ApiError,
        normalizeError: normalizeError,
        createApi: createApi,
        confirmOptions: confirmOptions,
        confirmAttributes: confirmAttributes,
        badgeText: badgeText,
        debounce: debounce,
        safeStorage: safeStorage
    };
});
