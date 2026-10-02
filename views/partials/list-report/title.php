<?php
/**
 * List Report: title area. The saved views button sits on the title line (compact header),
 * the summary of the active filters shows when the header is snapped.
 *
 * @var App\Core\View $this
 * @var string $title
 */
?>
<ui5-dynamic-page-title slot="titleArea">
    <div slot="heading" class="lt-lr-heading">
        <ui5-title level="H1" size="H4"><?= e($title) ?></ui5-title>
        <ui5-button design="Transparent" end-icon="slim-arrow-down" data-lt-variant-button
                    accessible-name="<?= e(__('list.views')) ?>" tooltip="<?= e(__('list.views')) ?>"><?= e(__('list.standard')) ?></ui5-button>
    </div>
    <ui5-title slot="snappedHeading" level="H1" size="H5"><?= e($title) ?></ui5-title>
    <ui5-label slot="snappedSubheading" data-lt-filter-summary></ui5-label>
</ui5-dynamic-page-title>
