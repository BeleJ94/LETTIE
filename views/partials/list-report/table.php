<?php
/**
 * List Report: compact server-side DataTable. Header cells use data-lt-*
 * (DataTables reads plain data-* attributes of <th> as column options).
 *
 * @var App\Core\View $this
 * @var string $label accessible name of the table
 * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}> $columns name, label key, renderer, sortable, required (cannot be hidden)
 * @var bool $selectable adds the selection column (bulk actions, export of a selection)
 */
$selectable ??= false;
?>
<div class="lt-dt-block">
    <table class="lt-table lt-dt" data-lt-table aria-label="<?= e($label) ?>"
           data-empty-title="<?= e(__('list.empty_title')) ?>" data-empty-subtitle="<?= e(__('list.empty_subtitle')) ?>"
           data-select-label="<?= e(__('list.select_row')) ?>">
        <thead>
        <tr>
            <?php if ($selectable): ?>
                <th data-lt-select class="lt-dt__select"><input type="checkbox" class="lt-dt__check" data-lt-select-all aria-label="<?= e(__('list.select_all')) ?>"></th>
            <?php endif; ?>
            <?php foreach ($columns as [$name, $labelKey, $render, $sortable, $required]): ?>
                <th data-lt-name="<?= e($name) ?>" data-lt-render="<?= e($render) ?>" data-lt-label="<?= e(__($labelKey)) ?>"
                    <?= $sortable ? '' : 'data-lt-sortable="false"' ?> <?= $required ? 'data-lt-required' : '' ?>
                    <?= $render === 'number' ? 'class="lt-num"' : '' ?>><?= e(__($labelKey)) ?></th>
            <?php endforeach; ?>
        </tr>
        </thead>
    </table>
</div>
