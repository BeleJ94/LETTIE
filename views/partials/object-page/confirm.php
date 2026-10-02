<?php
/**
 * Object Page: the confirmation dialog shared by the actions of the page
 * (docs/FIORI_DESIGN.md §5: destructive actions are confirmed in a ui5-dialog).
 * Filled and opened by pages/object-page.js from the data-confirm-* attributes of the
 * button that triggers the action. Initial focus on Cancel.
 *
 * @var App\Core\View $this
 */
?>
<ui5-dialog id="lt-confirm" data-lt-confirm-dialog state="Critical" initial-focus="lt-confirm-cancel" header-text="<?= e(__('object.confirm')) ?>">
    <div class="lt-dialog-form">
        <ui5-text data-lt-confirm-message></ui5-text>
        <ui5-label for="lt-confirm-input" show-colon data-lt-confirm-input-label hidden></ui5-label>
        <ui5-textarea id="lt-confirm-input" data-lt-confirm-input maxlength="1000" rows="3" hidden></ui5-textarea>
    </div>
    <div slot="footer" class="lt-dialog-footer">
        <ui5-button design="Emphasized" data-lt-confirm-ok><?= e(__('object.confirm')) ?></ui5-button>
        <ui5-button id="lt-confirm-cancel" design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
    </div>
</ui5-dialog>
