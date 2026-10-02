/**
 * Lettie Wizard (browser only): step-by-step entry in a <ui5-wizard>.
 * See docs/FIORI_DESIGN.md, "Formulaires". Loaded with pages/object-page.js, which
 * provides the footer submit button, the suggestions and the message popover.
 *
 *   <form> <ui5-wizard data-lt-wizard content-layout="SingleStep">
 *            <ui5-wizard-step title-text="…" [selected] [disabled]>   fields with name / id
 *            …last step: the review, <ui5-text data-lt-summary="field-id">
 *   footer   <ui5-button data-lt-wizard-previous>  <ui5-button data-lt-wizard-next>
 *            <ui5-button data-lt-submit="form-id">   shown on the last step only
 *
 * A step is validated before the next one opens: required fields ([required][name]) must be
 * filled, and a field with data-lt-requires="hidden-input-id" must have set that hidden input
 * (a choice in a suggestion list). The server validates everything again on submission.
 */
(function (window, document) {
    'use strict';

    var LT = window.LT;
    var t = LT.t;

    function all(root, selector) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function setState(field, message) {
        var slot = field.querySelector(':scope > [slot="valueStateMessage"]');
        if (message) {
            if (!slot) {
                slot = document.createElement('div');
                slot.setAttribute('slot', 'valueStateMessage');
                field.appendChild(slot);
            }
            slot.textContent = message;
            field.valueState = 'Negative';
        } else if (field.valueState === 'Negative') {
            field.valueState = 'None';
            if (slot) {
                slot.remove();
            }
        }
    }

    /** What the user sees in a field, for the review step. */
    function display(field) {
        if (!field) {
            return '';
        }
        switch (field.localName) {
            case 'ui5-select':
                var option = field.selectedOption;
                return option && option.getAttribute('value') !== '' ? option.textContent.trim() : '';
            case 'ui5-date-picker':
            case 'ui5-datetime-picker':
                var inner = field.shadowRoot ? field.shadowRoot.querySelector('ui5-datetime-input, ui5-input') : null;
                return (inner && inner.value) || field.value || '';
            case 'ui5-file-uploader':
                return Array.prototype.map.call(field.files || [], function (file) {
                    return file.name;
                }).join(', ');
            case 'ui5-checkbox':
                return field.checked ? (field.getAttribute('text') || '✓') : '';
            default:
                return String(field.value || '').trim();
        }
    }

    function init(wizard) {
        var page = wizard.closest('[data-lt-object-page]') || document;
        var steps = all(wizard, 'ui5-wizard-step');
        var previous = page.querySelector('[data-lt-wizard-previous]');
        var next = page.querySelector('[data-lt-wizard-next]');
        var save = page.querySelector('[data-lt-submit]');
        var empty = '—';

        function current() {
            for (var i = 0; i < steps.length; i++) {
                if (steps[i].selected) {
                    return i;
                }
            }
            return 0;
        }

        function validate(step) {
            var invalid = [];
            all(step, '[required][name], [data-lt-requires]').forEach(function (field) {
                var message = '';
                var requires = field.getAttribute('data-lt-requires');
                if (requires) {
                    var hidden = document.getElementById(requires);
                    if (String(field.value || '').trim() === '') {
                        message = t('js.form.required');
                    } else if (!hidden || hidden.value === '') {
                        message = t('js.form.choose');
                    }
                } else if (String(field.value || '').trim() === '') {
                    message = t('js.form.required');
                }
                setState(field, message);
                if (message) {
                    invalid.push(field);
                }
            });
            if (invalid.length > 0) {
                invalid[0].focus();
            }
            return invalid.length === 0;
        }

        function review() {
            all(wizard, '[data-lt-summary]').forEach(function (target) {
                var text = display(document.getElementById(target.getAttribute('data-lt-summary')));
                target.textContent = text !== '' ? text : empty;
            });
        }

        function render() {
            var index = current();
            var last = index === steps.length - 1;
            previous.hidden = index === 0;
            next.hidden = last;
            save.hidden = !last;
            if (last) {
                review();
            }
        }

        function show(index) {
            steps.forEach(function (step, i) {
                if (i === index) {
                    step.disabled = false;
                }
                step.selected = i === index;
            });
            render();
            window.setTimeout(function () {
                var first = steps[index].querySelector('ui5-input, ui5-select, ui5-date-picker, ui5-datetime-picker, ui5-textarea, ui5-file-uploader');
                if (first) {
                    first.focus();
                }
            }, 150);
        }

        next.addEventListener('click', function () {
            var index = current();
            if (validate(steps[index]) && index < steps.length - 1) {
                show(index + 1);
            }
        });
        previous.addEventListener('click', function () {
            show(Math.max(0, current() - 1));
        });
        // Steps already reached can be reopened from the header of the wizard.
        wizard.addEventListener('step-change', function () {
            window.setTimeout(render, 0);
        });
        // A message of the popover may point to a field of another step.
        document.addEventListener('lt:focus-field', function (e) {
            var field = document.getElementById(e.detail.id);
            var step = field ? field.closest('ui5-wizard-step') : null;
            var index = steps.indexOf(step);
            if (index !== -1 && index !== current()) {
                show(index);
            }
        });
        // Correcting a field clears its error.
        wizard.addEventListener('input', function (e) {
            if (e.target && e.target.valueState === 'Negative') {
                setState(e.target, '');
            }
        });

        empty = (wizard.querySelector('[data-lt-summary]') || { textContent: empty }).textContent;
        render();
    }

    function start() {
        all(document, '[data-lt-wizard]').forEach(init);
    }

    if (document.documentElement.classList.contains('lt-ui5-ready')) {
        start();
    } else {
        document.addEventListener('lt:ui5-ready', start, { once: true });
    }
})(window, document);
