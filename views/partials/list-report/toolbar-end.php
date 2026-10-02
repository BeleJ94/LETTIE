<?php
/**
 * List Report: end of the table toolbar — exports (when the screen has an export source) and table settings.
 *
 * @var App\Core\View $this
 * @var bool $export
 */
$export ??= false;
?>
<?php if ($export): ?>
    <ui5-toolbar-button icon="excel-attachment" text="<?= e(__('export.excel')) ?>" data-lt-export="xlsx"
                        tooltip="<?= e(__('list.export_excel')) ?>"></ui5-toolbar-button>
    <ui5-toolbar-button icon="pdf-attachment" text="<?= e(__('export.pdf')) ?>" data-lt-export="pdf"
                        tooltip="<?= e(__('list.export_pdf')) ?>"></ui5-toolbar-button>
<?php endif; ?>
<ui5-toolbar-button icon="action-settings" data-lt-settings tooltip="<?= e(__('list.settings')) ?>"
                    accessible-name="<?= e(__('list.settings')) ?>"></ui5-toolbar-button>
