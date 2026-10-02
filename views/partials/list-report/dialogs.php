<?php
/**
 * List Report: saved views (per device) and table settings (sort, columns).
 * Placed after the ui5-dynamic-page of the screen.
 *
 * @var App\Core\View $this
 * @var string $id prefix of the element ids (the data-key of the screen)
 */
?>
<ui5-popover id="<?= e($id) ?>-report-variants" data-lt-variant-popover header-text="<?= e(__('list.views')) ?>" placement="Bottom" horizontal-align="Start">
    <ui5-list data-lt-variant-standard separators="None" accessible-name="<?= e(__('list.standard')) ?>"></ui5-list>
    <ui5-list data-lt-variant-list selection-mode="Delete" separators="None" accessible-name="<?= e(__('list.views')) ?>"></ui5-list>
    <div slot="footer" class="lt-popover-footer">
        <ui5-button design="Emphasized" data-lt-variant-save><?= e(__('list.save')) ?></ui5-button>
        <ui5-button design="Transparent" data-lt-variant-save-as><?= e(__('list.save_as')) ?></ui5-button>
    </div>
</ui5-popover>

<ui5-dialog id="<?= e($id) ?>-report-save" data-lt-variant-dialog header-text="<?= e(__('list.save_title')) ?>">
    <div class="lt-dialog-form">
        <ui5-label for="<?= e($id) ?>-view-name" required show-colon><?= e(__('list.view_name')) ?></ui5-label>
        <ui5-input id="<?= e($id) ?>-view-name" data-lt-variant-name maxlength="60" required></ui5-input>
        <ui5-checkbox data-lt-variant-default text="<?= e(__('list.view_default')) ?>"></ui5-checkbox>
        <ui5-text><?= e(__('list.view_help')) ?></ui5-text>
    </div>
    <div slot="footer" class="lt-dialog-footer">
        <ui5-button design="Emphasized" data-lt-dialog-confirm><?= e(__('list.save')) ?></ui5-button>
        <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('list.cancel')) ?></ui5-button>
    </div>
</ui5-dialog>

<ui5-dialog id="<?= e($id) ?>-report-settings" data-lt-settings-dialog header-text="<?= e(__('list.settings')) ?>">
    <div class="lt-dialog-form">
        <ui5-label for="<?= e($id) ?>-sort" show-colon><?= e(__('list.sort_by')) ?></ui5-label>
        <ui5-select id="<?= e($id) ?>-sort" data-lt-sort-column></ui5-select>
        <ui5-label for="<?= e($id) ?>-dir" show-colon><?= e(__('list.order')) ?></ui5-label>
        <ui5-select id="<?= e($id) ?>-dir" data-lt-sort-dir>
            <ui5-option value="asc"><?= e(__('list.asc')) ?></ui5-option>
            <ui5-option value="desc"><?= e(__('list.desc')) ?></ui5-option>
        </ui5-select>
        <ui5-title level="H3" size="H6"><?= e(__('list.columns')) ?></ui5-title>
        <ui5-list data-lt-column-list selection-mode="Multiple" separators="None" accessible-name="<?= e(__('list.columns')) ?>"></ui5-list>
    </div>
    <div slot="footer" class="lt-dialog-footer">
        <ui5-button design="Emphasized" data-lt-dialog-confirm><?= e(__('list.apply')) ?></ui5-button>
        <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('list.cancel')) ?></ui5-button>
    </div>
</ui5-dialog>
