/**
 * Lettie Object Page (browser only): behaviour of the Fiori detail screens.
 * See docs/FIORI_DESIGN.md, "Modèle Object Page". Everything is driven by attributes:
 *
 *   <ui5-dynamic-page data-lt-object-page [data-editing]>
 *   anchor bar     <ui5-tabcontainer data-lt-anchor-bar> with <ui5-tab data-target="section-id">;
 *                  sections are <section class="lt-op-section" id="…">
 *   footer         <ui5-button data-lt-submit="form-id">           submits a form of the page (Save)
 *                  <ui5-button data-lt-action="form-id"            submits a hidden action form, after an optional
 *                       [data-confirm="message" data-confirm-title data-confirm-state="Critical|Negative"
 *                        data-confirm-input="field" data-confirm-input-label]>      confirmation in #lt-confirm
 *   messages       [data-lt-messages-button] opens the popover [data-lt-messages];
 *                  <ui5-li data-lt-focus="field-id"> focuses the field in error
 *   dialogs        <ui5-button data-lt-open-dialog="dialog-id">; <ui5-dialog data-lt-form-dialog> holding a <form>,
 *                  [data-lt-dialog-submit] and [data-lt-dialog-cancel]
 *   suggestions    <ui5-input data-lt-suggest data-url="/…/search" data-target="hidden-input-id">
 *                  (server: GET url?q=… → {"data": [{"id", "label", "detail"}]})
 *   new tab        <… data-lt-open="/path">
 */
(function (window, document) {
    'use strict';

    var LT = window.LT;

    function all(root, selector) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function on(root, selector, eventName, handler) {
        all(root, selector).forEach(function (el) {
            el.addEventListener(eventName, function (e) {
                handler(el, e);
            });
        });
    }

    /** Submits like a click on a submit button would (submit event, then the browser posts the form). */
    function submit(form) {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    /* -------------------------------------------------------- anchor bar */

    function initAnchors(page) {
        var bar = page.querySelector('[data-lt-anchor-bar]');
        if (!bar) {
            return;
        }
        var tabs = all(bar, 'ui5-tab');
        var sections = tabs.map(function (tab) {
            return document.getElementById(tab.getAttribute('data-target'));
        });
        var scrolling = false;

        // The title of ui5-dynamic-page is sticky inside the same scroll container: the anchor bar
        // must stick just below it, whatever its height (wrapped title, snapped or not).
        var stickyTitle = null;
        function offset() {
            var height = stickyTitle ? Math.ceil(stickyTitle.getBoundingClientRect().height) : 0;
            page.style.setProperty('--lt-op-title', height + 'px');
            page.style.setProperty('--lt-op-bar', Math.ceil(bar.getBoundingClientRect().height) + 'px');
        }
        // The page renders its shadow DOM asynchronously: look for the title until it is there.
        (function watch(tries) {
            stickyTitle = page.shadowRoot ? page.shadowRoot.querySelector('[class*="title-header-wrapper"]') : null;
            if (!stickyTitle) {
                if (tries > 0) {
                    window.setTimeout(function () { watch(tries - 1); }, 50);
                }
                return;
            }
            offset();
            if ('ResizeObserver' in window) {
                var resize = new window.ResizeObserver(offset);
                resize.observe(stickyTitle);
                resize.observe(bar);
            } else {
                window.addEventListener('resize', offset);
            }
        })(60);

        function select(index) {
            tabs.forEach(function (tab, i) {
                tab.selected = i === index;
            });
        }

        function reveal(section) {
            // Programmatic scroll: the observer must not fight the tab the user chose.
            scrolling = true;
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            window.setTimeout(function () {
                scrolling = false;
            }, 700);
        }

        bar.addEventListener('tab-select', function (e) {
            var index = tabs.indexOf(e.detail.tab);
            if (index !== -1 && sections[index]) {
                reveal(sections[index]);
            }
        });

        // While scrolling, the tab of the section in view is selected.
        if ('IntersectionObserver' in window) {
            var visible = {};
            var observer = new window.IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    visible[entry.target.id] = entry.isIntersecting;
                });
                if (scrolling) {
                    return;
                }
                for (var i = 0; i < sections.length; i++) {
                    if (sections[i] && visible[sections[i].id]) {
                        select(i);
                        return;
                    }
                }
            }, { rootMargin: '-30% 0px -55% 0px' });
            sections.forEach(function (section) {
                if (section) {
                    observer.observe(section);
                }
            });
        }

        // Redirects after an action come back to their section (…#assignments).
        var target = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
        var targetIndex = sections.indexOf(target);
        if (targetIndex !== -1) {
            select(targetIndex);
            window.setTimeout(function () {
                reveal(target);
            }, 150);
        }
    }

    /* ------------------------------------------------- messages popover */

    function focusField(id) {
        var field = document.getElementById(id);
        if (!field) {
            return;
        }
        // A wizard shows the step that holds the field first (pages/wizard.js).
        document.dispatchEvent(new window.CustomEvent('lt:focus-field', { detail: { id: id } }));
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () {
            field.focus();
        }, 250);
    }

    function initMessages(page) {
        var popover = page.querySelector('[data-lt-messages]');
        var button = page.querySelector('[data-lt-messages-button]');
        if (!popover || !button) {
            return;
        }
        button.addEventListener('click', function () {
            popover.open = !popover.open;
        });
        on(popover, 'ui5-list', 'item-click', function (list, e) {
            popover.open = false;
            focusField(e.detail.item.getAttribute('data-lt-focus'));
        });
        // Errors are shown at once: the user sees what to correct without looking for it.
        window.setTimeout(function () {
            popover.open = true;
        }, 300);
    }

    /* ---------------------------------------------- actions and dialogs */

    /** Flags the empty required fields of a form (value state + message) and focuses the first one. */
    function requiredFilled(form) {
        var missing = all(form, '[required][name]').filter(function (field) {
            var empty = field.localName === 'ui5-file-uploader' ? !(field.files && field.files.length) : String(field.value || '').trim() === '';
            var slot = field.querySelector(':scope > [slot="valueStateMessage"]');
            if (empty && !slot) {
                slot = document.createElement('div');
                slot.setAttribute('slot', 'valueStateMessage');
                field.appendChild(slot);
            }
            if (empty) {
                slot.textContent = LT.t('js.form.required');
            }
            field.valueState = empty ? 'Negative' : 'None';
            return empty;
        });
        if (missing.length > 0) {
            missing[0].focus();
        }
        return missing.length === 0;
    }

    function initActions(scope) {
        var dialog = scope.querySelector('[data-lt-confirm-dialog]');
        var pending = null;

        on(scope, '[data-lt-submit]', 'click', function (button) {
            var form = document.getElementById(button.getAttribute('data-lt-submit'));
            if (!form) {
                return;
            }
            // Simple forms (data-lt-validate): empty required fields are flagged at once, inline.
            if (form.hasAttribute('data-lt-validate') && !requiredFilled(form)) {
                return;
            }
            // Submit first: a ui5-button that sits inside the form blocks the submission once it is loading.
            submit(form);
            button.loading = true;
            // A confirmation (data-lt-confirm) may be declined: the button must stay usable.
            window.setTimeout(function () {
                button.loading = false;
            }, 1500);
        });

        on(scope, '[data-lt-action]', 'click', function (button) {
            var form = document.getElementById(button.getAttribute('data-lt-action'));
            if (!form) {
                return;
            }
            if (!button.hasAttribute('data-confirm') || !dialog) {
                button.loading = true;
                submit(form);
                return;
            }
            pending = { form: form, input: button.getAttribute('data-confirm-input') };
            var label = dialog.querySelector('[data-lt-confirm-input-label]');
            var input = dialog.querySelector('[data-lt-confirm-input]');
            var ok = dialog.querySelector('[data-lt-confirm-ok]');
            dialog.setAttribute('header-text', button.getAttribute('data-confirm-title') || '');
            dialog.setAttribute('state', button.getAttribute('data-confirm-state') || 'Critical');
            dialog.querySelector('[data-lt-confirm-message]').textContent = button.getAttribute('data-confirm');
            label.textContent = button.getAttribute('data-confirm-input-label') || '';
            label.hidden = input.hidden = !pending.input;
            input.value = '';
            ok.textContent = button.getAttribute('data-confirm-title') || ok.textContent;
            ok.setAttribute('design', button.getAttribute('data-confirm-state') === 'Negative' ? 'Negative' : 'Emphasized');
            dialog.open = true;
        });

        if (dialog) {
            dialog.querySelector('[data-lt-confirm-ok]').addEventListener('click', function () {
                if (!pending) {
                    return;
                }
                if (pending.input && pending.form.elements[pending.input]) {
                    pending.form.elements[pending.input].value = dialog.querySelector('[data-lt-confirm-input]').value;
                }
                this.loading = true;
                submit(pending.form);
            });
        }

        on(scope, '[data-lt-open-dialog]', 'click', function (button) {
            var target = document.getElementById(button.getAttribute('data-lt-open-dialog'));
            if (target) {
                target.open = true;
            }
        });
        on(scope, '[data-lt-dialog-submit]', 'click', function (button) {
            var form = button.closest('ui5-dialog').querySelector('form');
            // Required fields: flagged in the dialog instead of a round trip to the server.
            if (!requiredFilled(form)) {
                return;
            }
            button.loading = true;
            submit(form);
        });
        on(scope, '[data-lt-dialog-cancel]', 'click', function (button) {
            button.closest('ui5-dialog').open = false;
        });
        on(scope, '[data-lt-open]', 'click', function (el) {
            window.open(LT.joinUrl(LT.config.basePath, el.getAttribute('data-lt-open')), '_blank', 'noopener');
        });
    }

    /* ------------------------------------------------------ suggestions */

    function initSuggest(input) {
        var target = document.getElementById(input.getAttribute('data-target'));
        var url = input.getAttribute('data-url');
        var chosen = input.value;
        var requestId = 0;

        var search = LT.debounce(function () {
            var term = String(input.value || '').trim();
            var id = ++requestId;
            if (term.length < 2) {
                input.replaceChildren.apply(input, all(input, ':scope > :not(ui5-suggestion-item)'));
                return;
            }
            LT.api.get(url, { q: term }).then(function (json) {
                if (id !== requestId) {
                    return;
                }
                all(input, 'ui5-suggestion-item').forEach(function (item) {
                    item.remove();
                });
                (json.data || []).forEach(function (row) {
                    var item = document.createElement('ui5-suggestion-item');
                    item.setAttribute('text', row.label);
                    if (row.detail) {
                        item.setAttribute('additional-text', row.detail);
                    }
                    item.setAttribute('data-id', row.id);
                    input.appendChild(item);
                });
            }, function () {
                // Silent: the field stays usable, the server validates the choice.
            });
        }, 250);

        input.addEventListener('input', function () {
            // Typing again drops the previous choice until a suggestion is picked.
            if (input.value !== chosen) {
                target.value = '';
            }
            search();
        });
        input.addEventListener('selection-change', function (e) {
            var item = e.detail && e.detail.item;
            if (item && item.getAttribute('data-id')) {
                target.value = item.getAttribute('data-id');
                chosen = item.getAttribute('text');
            }
        });
    }

    function start() {
        all(document, '[data-lt-object-page]').forEach(function (page) {
            initAnchors(page);
            initMessages(page);
        });
        initActions(document);
        all(document, '[data-lt-suggest]').forEach(initSuggest);
    }

    // UI5 elements must be defined before their properties are set (open, selected, value…).
    if (document.documentElement.classList.contains('lt-ui5-ready')) {
        start();
    } else {
        document.addEventListener('lt:ui5-ready', start, { once: true });
    }
})(window, document);
