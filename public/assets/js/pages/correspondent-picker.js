/**
 * Correspondent autocomplete (mail form).
 *
 *   <div data-lt-picker data-url="/correspondents/search">
 *     <input type="hidden" data-lt-picker-value>     selected id (submitted)
 *     <input type="search" data-lt-picker-input>     text typed by the user
 *     <ul data-lt-picker-results hidden></ul>        suggestions
 *
 * Server: GET url?q=… → {"data": [{"id", "label", "detail"}]}
 */
(function (window, document, $) {
    'use strict';

    var LT = window.LT;

    function init(root) {
        var $root = $(root);
        var $value = $root.find('[data-lt-picker-value]');
        var $input = $root.find('[data-lt-picker-input]');
        var $results = $root.find('[data-lt-picker-results]');
        var url = root.getAttribute('data-url');
        var lastQuery = null;

        function close() {
            $results.prop('hidden', true).empty();
            $input.attr('aria-expanded', 'false');
        }

        function message(text) {
            $results.empty().append($('<li class="lt-picker__empty">').text(text)).prop('hidden', false);
        }

        function render(items) {
            $results.empty();
            if (!items.length) {
                message(LT.t('js.picker.none'));
                return;
            }
            items.forEach(function (item) {
                var $button = $('<button type="button" class="lt-picker__option">')
                    .attr('data-id', item.id)
                    .append($('<span class="lt-picker__label">').text(item.label));
                if (item.detail) {
                    $button.append($('<span class="lt-picker__detail">').text(item.detail));
                }
                $results.append($('<li>').append($button));
            });
            $results.prop('hidden', false);
            $input.attr('aria-expanded', 'true');
        }

        var search = LT.debounce(function (query) {
            lastQuery = query;
            LT.api.get(url, { q: query }).then(function (json) {
                if (query === lastQuery) {
                    render(Array.isArray(json.data) ? json.data : []);
                }
            }, LT.ui.error);
        }, 250);

        $input.on('input', function () {
            // Typing invalidates the previous choice.
            $value.val('');
            var query = $input.val().trim();
            if (query.length < 2) {
                message(LT.t('js.picker.min'));
                return;
            }
            search(query);
        });

        $results.on('click', '.lt-picker__option', function () {
            $value.val(this.getAttribute('data-id'));
            $input.val($(this).find('.lt-picker__label').text());
            $input.removeAttr('aria-invalid');
            close();
            $input.trigger('focus');
        });

        $input.on('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                $results.find('.lt-picker__option').first().trigger('focus');
            } else if (e.key === 'Escape') {
                close();
            }
        });
        $results.on('keydown', '.lt-picker__option', function (e) {
            var $items = $results.find('.lt-picker__option');
            var index = $items.index(this);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                $items.eq(Math.max(0, index + (e.key === 'ArrowDown' ? 1 : -1))).trigger('focus');
            } else if (e.key === 'Escape') {
                close();
                $input.trigger('focus');
            }
        });

        $(document).on('click', function (e) {
            if (!root.contains(e.target)) {
                close();
            }
        });
    }

    $(function () {
        $('[data-lt-picker]').each(function () {
            init(this);
        });
    });
})(window, document, window.jQuery);
