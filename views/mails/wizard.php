<?php
/**
 * @floorplan Wizard
 *
 * Registration of an incoming mail, step by step (docs/FIORI_DESIGN.md, "Formulaires").
 * One <form> around the wizard: the steps only split the entry, the server validates everything
 * at the end exactly as for the simple form. Behaviour: pages/wizard.js (+ pages/object-page.js
 * for the suggestions, the footer and the message popover).
 *
 * @var App\Core\View $this
 * @var App\Domain\Mail\Direction $direction
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var string $correspondentLabel
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var int $maxUploadMb
 * @var string $accept
 */
use App\Core\Ui5;

$this->layout('layouts/main');
$title = __('mail.new_incoming');
// Step id => [title, help, fields]. The field ids are the server field names.
$steps = [
    'identification' => [__('wizard.mail.identification'), __('wizard.mail.identification_help'), ['subject', 'received_at', 'document_date', 'channel', 'external_reference', 'summary']],
    'correspondent' => [__('wizard.mail.correspondent'), __('wizard.mail.correspondent_help'), ['correspondent_id']],
    'processing' => [__('wizard.mail.processing'), __('wizard.mail.processing_help'), ['department_id', 'priority', 'confidentiality', 'due_date']],
];
$fieldLabels = [];
foreach ($steps as [, , $fields]) {
    foreach ($fields as $field) {
        $fieldLabels[$field] = __('mail.fields.' . $field);
    }
}
// After a refusal by the server, every step is reachable and the first one in error is shown.
$firstInError = null;
foreach ($steps as $id => [, , $fields]) {
    if ($firstInError === null && array_intersect($fields, array_keys($errors)) !== []) {
        $firstInError = $id;
    }
}
$selected = $firstInError ?? 'identification';
$unlocked = $errors !== [];
$partial = fn (array $only): string => $this->partial('mails/_fields', [
    'direction' => $direction, 'values' => $values, 'errors' => $errors, 'correspondentLabel' => $correspondentLabel,
    'departments' => $departments, 'isNew' => true, 'only' => $only, 'layout' => 'S1 M2 L2 XL2',
]);
?>
<?php $this->start('title') ?><?= e($title) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<script src="<?= e($basePath) ?>/assets/js/pages/wizard.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="mail-page" class="lt-object-page lt-wizard-page" data-lt-object-page data-editing show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('mail.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/mails"><?= e(__('mail.title')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e(__('object.creating')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($title) ?></ui5-title>
        <div slot="subheading" class="lt-tags"><?= Ui5::tag('direction', $direction->value) ?></div>
    </ui5-dynamic-page-title>

    <form id="mail-form" method="post" action="<?= e($basePath) ?>/mails" enctype="multipart/form-data" novalidate class="lt-wizard-form">
        <?= $csrf->field() ?>
        <input type="hidden" name="direction" value="<?= e($direction->value) ?>">

        <ui5-wizard data-lt-wizard content-layout="SingleStep" accessible-name="<?= e(__('wizard.steps')) ?>">
            <?php $index = 0; ?>
            <?php foreach ($steps as $id => [$label, $help, $fields]): ?>
                <ui5-wizard-step title-text="<?= e($label) ?>" data-step="<?= e($id) ?>"
                    <?= $id === $selected ? 'selected' : '' ?> <?= ($index > 0 && !$unlocked && $id !== $selected) ? 'disabled' : '' ?>>
                    <div class="lt-wizard-step">
                        <ui5-title level="H2" size="H4"><?= e(($index + 1) . '. ' . $label) ?></ui5-title>
                        <ui5-label wrapping-type="Normal"><?= e($help) ?></ui5-label>
                        <?= $partial($fields) ?>
                    </div>
                </ui5-wizard-step>
                <?php $index++; ?>
            <?php endforeach; ?>

            <ui5-wizard-step title-text="<?= e(__('wizard.mail.attachment')) ?>" data-step="attachment" <?= $unlocked ? '' : 'disabled' ?>>
                <div class="lt-wizard-step">
                    <ui5-title level="H2" size="H4">4. <?= e(__('wizard.mail.attachment')) ?></ui5-title>
                    <ui5-label wrapping-type="Normal"><?= e(__('wizard.mail.attachment_help')) ?></ui5-label>
                    <?php if ($errors !== []): ?>
                        <ui5-message-strip design="Information" hide-close-button><?= e(__('wizard.mail.attachment_lost')) ?></ui5-message-strip>
                    <?php endif; ?>
                    <ui5-form layout="S1 M1 L1 XL1" label-span="S12 M12 L12 XL12" accessible-name="<?= e(__('wizard.mail.attachment')) ?>">
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="file" show-colon><?= e(__('attachment.file')) ?></ui5-label>
                            <div class="lt-field-stack">
                                <ui5-file-uploader id="file" name="file" accept="<?= e($accept) ?>" placeholder="<?= e(__('attachment.file')) ?>"></ui5-file-uploader>
                                <ui5-label wrapping-type="Normal"><?= e(__('attachment.help', ['max' => $maxUploadMb])) ?></ui5-label>
                            </div>
                        </ui5-form-item>
                    </ui5-form>
                </div>
            </ui5-wizard-step>

            <ui5-wizard-step title-text="<?= e(__('wizard.review')) ?>" data-step="review" <?= $unlocked ? '' : 'disabled' ?>>
                <div class="lt-wizard-step">
                    <ui5-title level="H2" size="H4">5. <?= e(__('wizard.review')) ?></ui5-title>
                    <ui5-label wrapping-type="Normal"><?= e(__('wizard.review_help')) ?></ui5-label>
                    <?php /* Filled by pages/wizard.js from the fields, as the user sees them. */ ?>
                    <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Normal" accessible-name="<?= e(__('wizard.review')) ?>">
                        <?php foreach ($fieldLabels as $field => $label): ?>
                            <ui5-form-item <?= $field === 'summary' ? 'column-span="3"' : '' ?>>
                                <ui5-label slot="labelContent" show-colon><?= e($label) ?></ui5-label>
                                <ui5-text class="lt-prewrap" data-lt-summary="<?= e($field) ?>"><?= e(__('wizard.empty')) ?></ui5-text>
                            </ui5-form-item>
                        <?php endforeach; ?>
                        <ui5-form-item>
                            <ui5-label slot="labelContent" show-colon><?= e(__('wizard.mail.attachment')) ?></ui5-label>
                            <ui5-text data-lt-summary="file"><?= e(__('wizard.empty')) ?></ui5-text>
                        </ui5-form-item>
                    </ui5-form>
                </div>
            </ui5-wizard-step>
        </ui5-wizard>
    </form>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?= $this->partial('partials/object-page/messages', ['errors' => $errors, 'labels' => $fieldLabels]) ?>
        <ui5-button slot="endContent" design="Transparent" data-lt-wizard-previous hidden><?= e(__('wizard.previous')) ?></ui5-button>
        <ui5-button slot="endContent" design="Emphasized" data-lt-wizard-next><?= e(__('wizard.next')) ?></ui5-button>
        <ui5-button slot="endContent" design="Emphasized" data-lt-submit="mail-form" hidden><?= e(__('common.save')) ?></ui5-button>
        <ui5-button slot="endContent" design="Transparent" data-lt-href="/mails"><?= e(__('common.cancel')) ?></ui5-button>
    </ui5-bar>
</ui5-dynamic-page>
