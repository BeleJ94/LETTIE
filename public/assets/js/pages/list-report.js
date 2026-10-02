/**
 * Lettie List Report (browser only): wires a Fiori list screen to its server table.
 * Pure logic is in lt-listreport.js (tested); this file only manipulates the page.
 * See docs/FIORI_DESIGN.md, "Modèle List Report".
 *
 * The filter bar, the saved views and the toolbar are UI5 components. The table itself is a
 * compact DataTable (server-side: sort by a click on a header, paging, page length), with the
 * statuses rendered as ui5-tag.
 *
 * The markup comes from views/partials/list-report/ (assets, title, table, toolbar-end, dialogs).
 *
 *   <ui5-dynamic-page data-lt-list-report data-key="mails" data-url="/mails/data"
 *        data-row-href="/mails/{id}" data-row-label="reference" data-sort="mail_date" data-dir="desc"
 *        data-per-page="25" data-title="Courriers"
 *        [data-default-filters='{"direction":"incoming"}']   filters of the standard view (and of "Reset")
 *        [data-export-source="/mails/export" data-export-set="mails" data-export-title="…" data-export-subtitle="…"]
 *        [data-export-map="date_from:from,date_to:to"]       the export source takes these filters only, under
 *                                                           these names (a document with its own parameters)>
 *     filter bar   <… data-lt-filter="status">  <ui5-input data-lt-search>
 *                  <ui5-button data-lt-go>  <ui5-button data-lt-reset>
 *     variants     <ui5-button data-lt-variant-button> + [data-lt-variant-popover], [data-lt-variant-dialog]
 *     table        <table data-lt-table data-empty-title data-empty-subtitle data-select-label> whose header cells are
 *                  <th data-lt-select> (selection column) and
 *                  <th data-lt-name data-lt-render="text|strong|tag:<enum>|due|date|datetime|number"
 *                      data-lt-label [data-lt-sortable="false"] [data-lt-required]>
 *                  (always data-lt-*: DataTables reads plain data-* attributes of <th> as column options)
 *     toolbar      <ui5-title data-lt-count>  <… data-lt-export="xlsx|pdf">  <… data-lt-settings>
 *                  <… data-lt-bulk="name" data-dialog="dialog-id">  (enabled while rows are selected)
 *     dialogs      [data-lt-settings-dialog]; <ui5-dialog data-lt-bulk-dialog data-url="/…"> with
 *                  [data-lt-field="name"] inputs, [data-lt-dialog-confirm], [data-lt-dialog-cancel]
 *
 * Bulk endpoints receive ids="1,2,3" plus the dialog fields and answer {done: [ids], failed: [{id, message}]}.
 */
(function (window, document) {
    'use strict';

    var LT = window.LT;
    var LR = LT.listReport;
    var t = LT.t;

    function all(root, selector) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function element(tag, properties, text) {
        var el = document.createElement(tag);
        Object.keys(properties || {}).forEach(function (name) {
            if (properties[name] !== null && properties[name] !== undefined && properties[name] !== false) {
                el.setAttribute(name, properties[name] === true ? '' : properties[name]);
            }
        });
        if (text !== undefined && text !== null) {
            el.textContent = String(text);
        }
        return el;
    }

    /** Escaped HTML of one element: DataTables renderers return strings. */
    function html(tag, properties, text) {
        return element(tag, properties, text).outerHTML;
    }

    function readField(el) {
        return el.localName === 'ui5-checkbox' ? el.checked === true : (el.value || '');
    }

    function writeField(el, value) {
        if (el.localName === 'ui5-checkbox') {
            el.checked = value === true || (typeof value === 'string' && value !== '' && value !== '0');
        } else {
            el.value = value === undefined || value === null || value === false ? '' : String(value);
        }
    }

    function init(root) {
        var key = root.getAttribute('data-key');
        var table = root.querySelector('[data-lt-table]');
        var filterFields = all(root, '[data-lt-filter]');
        var searchField = root.querySelector('[data-lt-search]');
        var countTitle = root.querySelector('[data-lt-count]');
        var resultStrip = root.querySelector('[data-lt-result]');
        var summary = root.querySelector('[data-lt-filter-summary]');
        var variantButton = root.querySelector('[data-lt-variant-button]');
        var page = root.parentNode;
        var variantPopover = page.querySelector('[data-lt-variant-popover]');
        var variantDialog = page.querySelector('[data-lt-variant-dialog]');
        var settingsDialog = page.querySelector('[data-lt-settings-dialog]');
        var store = LR.createVariantStore(LT.ui.storage, key);
        var columnsKey = 'lt.columns.' + key;
        var hasSelection = table.querySelector('th[data-lt-select]') !== null;
        var offset = hasSelection ? 1 : 0; // index of the first data column in the DataTable

        var columns = all(table, 'thead th[data-lt-name]').map(function (cell) {
            return {
                name: cell.getAttribute('data-lt-name'),
                label: cell.getAttribute('data-lt-label') || cell.textContent.trim(),
                render: cell.getAttribute('data-lt-render') || 'text',
                sortable: cell.getAttribute('data-lt-sortable') !== 'false',
                required: cell.hasAttribute('data-lt-required')
            };
        });
        var defaults = {
            sort: root.getAttribute('data-sort') || '',
            dir: root.getAttribute('data-dir') || 'asc',
            perPage: Number(root.getAttribute('data-per-page')) || 25,
            filters: {}
        };
        try {
            defaults.filters = LR.cleanFilters(JSON.parse(root.getAttribute('data-default-filters') || '{}'));
        } catch (e) {
            defaults.filters = {};
        }
        var rowHref = root.getAttribute('data-row-href');
        table.classList.toggle('lt-dt--static', !rowHref);

        // Applied state: what the table currently shows (the filter fields may be ahead of it until "Go").
        var state = { page: 1, perPage: defaults.perPage, sort: defaults.sort, dir: defaults.dir, search: '', filters: {}, hidden: [] };
        var rows = [];
        var total = null;
        var selected = {}; // id → true, kept while paging; cleared when the list is filtered again
        var currentVariant = LR.STANDARD;
        var instance = null;
        var deviceHidden = [];
        try {
            deviceHidden = JSON.parse(LT.ui.storage.get(columnsKey, '[]')) || [];
        } catch (e) {
            deviceHidden = [];
        }

        function columnIndex(name) {
            for (var i = 0; i < columns.length; i++) {
                if (columns[i].name === name) {
                    return i + offset;
                }
            }
            return -1;
        }

        /* ------------------------------------------------------- rendering */

        function tag(kind, value) {
            var look = LR.tagDesign(kind, value);
            var key2 = 'enums.' + kind + '.' + value;
            var label = t(key2);
            return html('ui5-tag', { design: look.design, 'color-scheme': look.colorScheme, 'hide-state-icon': look.design === 'Set2' || look.design === 'Neutral' },
                label === key2 ? value : label);
        }

        function cellHtml(column, value, row) {
            if (value === null || value === undefined || value === '') {
                return '';
            }
            var parts = column.render.split(':');
            switch (parts[0]) {
                case 'tag':
                    return tag(parts[1], value);
                case 'due':
                    var design = LR.dueDesign(value, row.status, new Date(), LT.ctx.timeZone);
                    var date = LT.formatDate(value, LT.ctx);
                    return design ? html('ui5-tag', { design: design }, date) : LT.escapeHtml(date);
                case 'date':
                    return LT.escapeHtml(LT.formatDate(value, LT.ctx));
                case 'datetime':
                    return LT.escapeHtml(LT.formatDateTime(value, LT.ctx));
                case 'number':
                    return LT.escapeHtml(LT.formatNumber(value, LT.ctx.locale));
                case 'strong':
                    return html('span', { 'class': 'lt-cell-strong lt-dt__text', title: value }, value);
                default:
                    // One line per row: the full text stays available in the tooltip.
                    return html('span', { 'class': 'lt-dt__text', title: value }, value);
            }
        }

        function renderCount() {
            countTitle.textContent = LR.countTitle(root.getAttribute('data-title'), total, LT.ctx.locale);
        }

        function renderSummary() {
            var count = Object.keys(LR.cleanFilters(state.filters)).length + (state.search !== '' ? 1 : 0);
            if (summary) {
                summary.textContent = count === 0 ? t('js.list.filters_none') : t('js.list.filters_active', { count: count });
            }
        }

        function snapshot() {
            return { filters: LR.cleanFilters(state.filters), search: state.search, sort: state.sort, dir: state.dir, hidden: state.hidden.slice() };
        }

        /** Standard view: the default filters (usually none), default sort, and the columns last chosen on this device. */
        function standardState() {
            return { filters: Object.assign({}, defaults.filters), search: '', sort: defaults.sort, dir: defaults.dir, hidden: deviceHidden.slice() };
        }

        function savedState(name) {
            var variant = name === LR.STANDARD ? null : store.get(name);
            return variant ? variant.state : standardState();
        }

        function renderVariant() {
            if (!variantButton) {
                return;
            }
            var name = currentVariant === LR.STANDARD ? t('js.list.standard') : currentVariant;
            variantButton.textContent = LR.isModified(savedState(currentVariant), snapshot()) ? t('js.list.modified', { name: name }) : name;
        }

        /* ------------------------------------------------------- selection */

        function selectedIds() {
            return Object.keys(selected);
        }

        function renderSelection() {
            var none = selectedIds().length === 0;
            all(root, '[data-lt-bulk]').forEach(function (button) {
                button.disabled = none;
            });
            var boxes = all(table, 'tbody [data-lt-row-select]');
            boxes.forEach(function (box) {
                var on = selected[box.getAttribute('data-id')] === true;
                box.checked = on;
                box.closest('tr').classList.toggle('selected', on);
            });
            var selectAll = table.querySelector('[data-lt-select-all]');
            if (selectAll) {
                var checked = boxes.filter(function (box) { return box.checked; }).length;
                selectAll.checked = boxes.length > 0 && checked === boxes.length;
                selectAll.indeterminate = checked > 0 && checked < boxes.length;
            }
        }

        function clearSelection() {
            selected = {};
            renderSelection();
        }

        /* ------------------------------------------------------- DataTable */

        function applyColumns() {
            var visible = LR.visibleColumns(columns, state.hidden).map(function (c) { return c.name; });
            columns.forEach(function (column, i) {
                instance.column(i + offset).visible(visible.indexOf(column.name) !== -1, false);
            });
            instance.columns.adjust();
        }

        function createTable() {
            var selectLabel = table.getAttribute('data-select-label') || '';
            var definitions = columns.map(function (column) {
                return {
                    data: column.name,
                    name: column.name,
                    title: column.label,
                    orderable: column.sortable,
                    searchable: false,
                    defaultContent: '',
                    className: column.render === 'number' ? 'lt-num' : '',
                    render: function (value, type, row) {
                        return type === 'display' ? cellHtml(column, value, row) : value;
                    }
                };
            });
            if (hasSelection) {
                definitions.unshift({
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    className: 'lt-dt__select',
                    render: function (id, type, row) {
                        return type === 'display'
                            // Native checkbox: it keeps the rows compact (a ui5-checkbox is 2rem high at least).
                            ? html('input', { type: 'checkbox', 'class': 'lt-dt__check', 'data-lt-row-select': true, 'data-id': id, 'aria-label': selectLabel + ' ' + (row[root.getAttribute('data-row-label')] || id) })
                            : id;
                    }
                });
            }
            var language = LT.tables.languageFor(t);
            language.zeroRecords = language.emptyTable = html('ui5-illustrated-message', {
                name: 'NoSearchResults', design: 'Dot',
                'title-text': table.getAttribute('data-empty-title'), 'subtitle-text': table.getAttribute('data-empty-subtitle')
            });

            instance = new window.DataTable(table, {
                serverSide: true,
                processing: true,
                searching: false, // the search field is in the filter bar
                autoWidth: false,
                pageLength: state.perPage,
                lengthMenu: [10, 25, 50, 100],
                order: columnIndex(state.sort) === -1 ? [] : [[columnIndex(state.sort), state.dir]],
                columns: definitions,
                language: language,
                layout: { topStart: null, topEnd: null, bottomStart: ['pageLength', 'info'], bottomEnd: 'paging' },
                ajax: function (data, callback) {
                    // DataTables owns paging and sorting: the list state follows it.
                    state.perPage = data.length;
                    state.page = Math.floor(data.start / data.length) + 1;
                    var order = data.order && data.order[0];
                    if (order && definitions[order.column] && definitions[order.column].name) {
                        state.sort = definitions[order.column].name;
                        state.dir = order.dir === 'desc' ? 'desc' : 'asc';
                    }
                    LT.api.get(root.getAttribute('data-url'), LR.buildQuery(state)).then(function (json) {
                        rows = json.data || [];
                        total = json.meta && typeof json.meta.filtered === 'number' ? json.meta.filtered : rows.length;
                        renderCount();
                        renderVariant();
                        callback(LT.tables.toDataTablesResult(json, data.draw));
                    }, function (error) {
                        LT.ui.error(error && error.kind ? error : { message: t('js.list.load_failed') });
                        callback(LT.tables.emptyResult(data.draw));
                    });
                },
                createdRow: function (tr, row) {
                    tr.setAttribute('data-id', row.id);
                    if (rowHref) {
                        tr.tabIndex = 0; // a row opens with Enter, like with a click
                    }
                },
                drawCallback: function () {
                    // Labels used by the stacked (card) layout on small screens.
                    var titles = all(table, 'thead th').map(function (th) { return th.textContent.trim(); });
                    all(table, 'tbody tr[data-id]').forEach(function (tr) {
                        all(tr, 'td').forEach(function (td, i) {
                            if (titles[i]) {
                                td.setAttribute('data-label', titles[i]);
                            }
                        });
                    });
                    renderSelection();
                }
            });
            applyColumns();
        }

        /** Reloads the first page with the current filters (the selection no longer applies). */
        function load() {
            clearSelection();
            if (!instance) {
                createTable();
                return;
            }
            instance.ajax.reload();
        }

        /** Shows a state (variant, deep link, reset): fields, columns, sort, then the data. */
        function show(next) {
            state.filters = LR.cleanFilters(next.filters);
            state.search = String(next.search || '').trim();
            state.sort = next.sort || defaults.sort;
            state.dir = next.dir || defaults.dir;
            state.hidden = (next.hidden || []).slice();
            filterFields.forEach(function (field) {
                writeField(field, state.filters[field.getAttribute('data-lt-filter')]);
            });
            if (searchField) {
                searchField.value = state.search;
            }
            renderSummary();
            if (instance) {
                applyColumns();
                instance.order(columnIndex(state.sort) === -1 ? [] : [[columnIndex(state.sort), state.dir]]);
            }
            load();
        }

        function go() {
            var filters = {};
            filterFields.forEach(function (field) {
                filters[field.getAttribute('data-lt-filter')] = readField(field);
            });
            state.filters = LR.cleanFilters(filters);
            state.search = searchField ? String(searchField.value || '').trim() : '';
            // The address no longer describes the list once the user filters by hand.
            if (window.location.search !== '' && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
            renderSummary();
            load();
        }

        /* ---------------------------------------------------------- events */

        function on(selector, eventName, handler, scope) {
            all(scope || root, selector).forEach(function (el) {
                el.addEventListener(eventName, function (e) {
                    handler(el, e);
                });
            });
        }

        on('[data-lt-go]', 'click', go);
        on('[data-lt-reset]', 'click', function () {
            applyVariant(LR.STANDARD);
        });
        all(root, '.lt-filter-bar ui5-input, .lt-filter-bar ui5-date-picker').forEach(function (field) {
            field.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    // Let the component commit the typed value first.
                    window.setTimeout(go, 0);
                }
            });
        });

        function openRow(tr) {
            var id = tr && tr.getAttribute('data-id');
            if (id && rowHref) {
                LT.ui.go(rowHref.replace('{id}', encodeURIComponent(id)));
            }
        }

        // A click on a row opens the object; the selection column and its checkbox do not.
        table.addEventListener('click', function (e) {
            if (e.target.closest('.lt-dt__select')) {
                return;
            }
            openRow(e.target.closest('tbody tr[data-id]'));
        });
        table.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches && e.target.matches('tbody tr[data-id]')) {
                openRow(e.target);
            }
        });
        table.addEventListener('change', function (e) {
            var box = e.target;
            if (box.hasAttribute && box.hasAttribute('data-lt-row-select')) {
                if (box.checked) {
                    selected[box.getAttribute('data-id')] = true;
                } else {
                    delete selected[box.getAttribute('data-id')];
                }
                renderSelection();
            } else if (box.hasAttribute && box.hasAttribute('data-lt-select-all')) {
                all(table, 'tbody [data-lt-row-select]').forEach(function (rowBox) {
                    if (box.checked) {
                        selected[rowBox.getAttribute('data-id')] = true;
                    } else {
                        delete selected[rowBox.getAttribute('data-id')];
                    }
                });
                renderSelection();
            }
        });

        /* Export: the whole filtered list, or the selected rows only. */
        function exportParams() {
            var map = root.getAttribute('data-export-map');
            var params = {};
            if (map) {
                // A document with its own parameters (the register): only the mapped filters, renamed.
                map.split(',').forEach(function (pair) {
                    var names = pair.split(':');
                    var value = state.filters[names[0].trim()];
                    if (value !== undefined) {
                        params[(names[1] || names[0]).trim()] = value;
                    }
                });
                return params;
            }
            params = LR.buildQuery(state);
            delete params.page;
            delete params.per_page;
            var ids = selectedIds();
            if (ids.length > 0) {
                params.ids = ids.join(',');
            }
            return params;
        }

        on('[data-lt-export]', 'click', function (button) {
            var params = exportParams();
            button.disabled = true;
            LT.ui.exportList({
                format: button.getAttribute('data-lt-export'),
                source: root.getAttribute('data-export-source'),
                set: root.getAttribute('data-export-set'),
                params: params,
                title: root.getAttribute('data-export-title'),
                subtitle: root.getAttribute('data-export-subtitle')
            }).then(function () {
                button.disabled = false;
            });
        });

        /* ------------------------------------------------------- variants */

        function variantItem(name, label, isDefault) {
            return element('ui5-li', {
                'data-name': name,
                icon: name === currentVariant ? 'accept' : null,
                'additional-text': isDefault ? t('js.list.view_default') : null
            }, label);
        }

        function renderVariantList() {
            var standardList = variantPopover.querySelector('[data-lt-variant-standard]');
            var list = variantPopover.querySelector('[data-lt-variant-list]');
            var defaultName = store.defaultName();
            standardList.replaceChildren(variantItem(LR.STANDARD, t('js.list.standard'), defaultName === LR.STANDARD));
            list.replaceChildren.apply(list, store.list().map(function (variant) {
                return variantItem(variant.name, variant.name, defaultName === variant.name);
            }));
        }

        function applyVariant(name) {
            currentVariant = name;
            if (variantPopover) {
                variantPopover.open = false;
            }
            show(savedState(name));
        }

        function openSaveDialog() {
            var input = variantDialog.querySelector('[data-lt-variant-name]');
            input.value = currentVariant === LR.STANDARD ? '' : currentVariant;
            input.valueState = 'None';
            variantDialog.querySelector('[data-lt-variant-default]').checked = false;
            variantPopover.open = false;
            variantDialog.open = true;
        }

        if (variantButton && variantPopover && variantDialog) {
            variantButton.addEventListener('click', function () {
                renderVariantList();
                variantPopover.opener = variantButton;
                variantPopover.open = true;
            });
            on('ui5-list', 'item-click', function (list, e) {
                applyVariant(e.detail.item.getAttribute('data-name'));
            }, variantPopover);
            on('[data-lt-variant-list]', 'item-delete', function (list, e) {
                var name = e.detail.item.getAttribute('data-name');
                store.remove(name);
                if (currentVariant === name) {
                    currentVariant = LR.STANDARD;
                }
                renderVariantList();
                renderVariant();
                LT.ui.toast(t('js.list.view_deleted', { name: name }));
            }, variantPopover);
            on('[data-lt-variant-save]', 'click', function () {
                if (currentVariant === LR.STANDARD) {
                    openSaveDialog();
                    return;
                }
                store.save(currentVariant, snapshot(), store.defaultName() === currentVariant);
                variantPopover.open = false;
                renderVariant();
                LT.ui.toast(t('js.list.view_saved', { name: currentVariant }));
            }, variantPopover);
            on('[data-lt-variant-save-as]', 'click', openSaveDialog, variantPopover);
            on('[data-lt-dialog-confirm]', 'click', function () {
                var input = variantDialog.querySelector('[data-lt-variant-name]');
                var result = store.save(input.value, snapshot(), variantDialog.querySelector('[data-lt-variant-default]').checked);
                if (!result.ok) {
                    var message = input.querySelector('[slot="valueStateMessage"]') || input.appendChild(element('div', { slot: 'valueStateMessage' }));
                    message.textContent = t('js.list.view_error.' + result.error);
                    input.valueState = 'Negative';
                    input.focus();
                    return;
                }
                currentVariant = result.name;
                variantDialog.open = false;
                renderVariant();
                LT.ui.toast(t('js.list.view_saved', { name: result.name }));
            }, variantDialog);
        }

        /* -------------------------------------------- table settings dialog */

        if (settingsDialog) {
            var sortColumn = settingsDialog.querySelector('[data-lt-sort-column]');
            var sortDir = settingsDialog.querySelector('[data-lt-sort-dir]');
            var columnList = settingsDialog.querySelector('[data-lt-column-list]');
            columns.filter(function (c) { return c.sortable; }).forEach(function (column) {
                sortColumn.appendChild(element('ui5-option', { value: column.name }, column.label));
            });

            on('[data-lt-settings]', 'click', function () {
                sortColumn.value = state.sort;
                sortDir.value = state.dir;
                columnList.replaceChildren.apply(columnList, columns.filter(function (c) { return !c.required; }).map(function (column) {
                    return element('ui5-li', { 'data-name': column.name, selected: state.hidden.indexOf(column.name) === -1 }, column.label);
                }));
                settingsDialog.open = true;
            });
            on('[data-lt-dialog-confirm]', 'click', function () {
                var sort = sortColumn.value || defaults.sort;
                var dir = sortDir.value === 'asc' ? 'asc' : 'desc';
                var sortChanged = sort !== state.sort || dir !== state.dir;
                state.hidden = all(columnList, 'ui5-li').filter(function (item) {
                    return !item.selected;
                }).map(function (item) {
                    return item.getAttribute('data-name');
                });
                deviceHidden = state.hidden.slice();
                LT.ui.storage.set(columnsKey, JSON.stringify(deviceHidden));
                settingsDialog.open = false;
                applyColumns();
                if (sortChanged) {
                    state.sort = sort;
                    state.dir = dir;
                    instance.order([[columnIndex(sort), dir]]).draw();
                } else {
                    instance.draw(false);
                    renderVariant();
                }
            }, settingsDialog);
        }

        /* ---------------------------------------------------- bulk actions */

        function showResult(result) {
            var references = {};
            rows.forEach(function (row) {
                references[row.id] = row[root.getAttribute('data-row-label') || 'id'];
            });
            var outcome = LR.bulkSummary(result, references, function (reference, message) {
                return t('js.list.bulk_item', { reference: reference, message: message });
            });
            var headline = outcome.failed === 0
                ? t('js.list.bulk_done', { count: outcome.done })
                : (outcome.done === 0 ? t('js.list.bulk_failed') : t('js.list.bulk_partial', { done: outcome.done, failed: outcome.failed }));
            resultStrip.setAttribute('design', outcome.state);
            resultStrip.textContent = [headline].concat(outcome.details).join('\n');
            resultStrip.hidden = false;
        }

        if (resultStrip) {
            resultStrip.addEventListener('close', function () {
                resultStrip.hidden = true;
            });
        }

        on('[data-lt-bulk]', 'click', function (button) {
            var dialog = document.getElementById(button.getAttribute('data-dialog'));
            var count = selectedIds().length;
            if (!dialog || count === 0) {
                return;
            }
            all(dialog, '[data-label]').forEach(function (el) {
                el.textContent = t(el.getAttribute('data-label'), { count: count });
            });
            all(dialog, '[data-lt-field]').forEach(function (field) {
                writeField(field, '');
            });
            // A select has no empty state: back to its first option.
            all(dialog, 'ui5-select[data-lt-field]').forEach(function (select) {
                var first = select.querySelector('ui5-option');
                select.value = first ? first.getAttribute('value') || '' : '';
            });
            dialog.querySelector('[data-lt-dialog-error]').hidden = true;
            dialog.open = true;
        });

        all(page, '[data-lt-bulk-dialog]').forEach(function (dialog) {
            var confirm = dialog.querySelector('[data-lt-dialog-confirm]');
            var errorStrip = dialog.querySelector('[data-lt-dialog-error]');
            confirm.addEventListener('click', function () {
                var payload = { ids: selectedIds().join(',') };
                all(dialog, '[data-lt-field]').forEach(function (field) {
                    payload[field.getAttribute('data-lt-field')] = readField(field);
                });
                confirm.loading = true;
                LT.api.post(dialog.getAttribute('data-url'), payload).then(function (json) {
                    dialog.open = false;
                    showResult(json);
                    load();
                }, function (error) {
                    var details = error && error.errors ? Object.keys(error.errors).map(function (field) {
                        return [].concat(error.errors[field]).join(' ');
                    }) : [];
                    errorStrip.textContent = details.length > 0 ? details.join(' ') : (error && error.message) || t('js.errors.http');
                    errorStrip.hidden = false;
                }).then(function () {
                    confirm.loading = false;
                });
            });
        });

        on('[data-lt-dialog-cancel]', 'click', function (button) {
            button.closest('ui5-dialog').open = false;
        }, page);

        /* ------------------------------------------------------------ start */

        // On a phone the filter bar starts collapsed: the table comes first, the summary shows the active filters.
        if (window.matchMedia && window.matchMedia('(max-width: 599px)').matches) {
            root.headerSnapped = true;
        }
        var link = LR.filtersFromSearch(window.location.search, filterFields.map(function (field) {
            return field.getAttribute('data-lt-filter');
        }));
        if (link.any) {
            // Deep link (home tile, global search): it wins over the default variant.
            show(Object.assign(standardState(), { filters: link.filters, search: link.search }));
        } else if (store.defaultName() !== LR.STANDARD) {
            applyVariant(store.defaultName());
        } else {
            show(standardState());
        }
    }

    function start() {
        if (!window.DataTable) {
            return;
        }
        all(document, '[data-lt-list-report]').forEach(init);
    }

    // UI5 elements must be defined before their properties are set (value, checked, selected…).
    if (document.documentElement.classList.contains('lt-ui5-ready')) {
        start();
    } else {
        document.addEventListener('lt:ui5-ready', start, { once: true });
    }
})(window, document);
