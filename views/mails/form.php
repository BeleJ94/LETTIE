<?php
/**
 * @floorplan ObjectPage
 *
 * New mail: Object Page in creation mode (fields shared with the edit mode of mails/show.php).
 *
 * @var App\Core\View $this
 * @var App\Domain\Mail\Direction $direction
 * @var ?App\Domain\Mail\Mail $replyTo incoming mail this outgoing mail answers
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var string $correspondentLabel
 * @var list<array{id: int, site_id: int, name: string}> $departments
 */
use App\Core\Ui5;
use App\Domain\Mail\Direction;

$this->layout('layouts/main');
$title = __($direction === Direction::Incoming ? 'mail.new_incoming' : 'mail.new_outgoing');
$fieldLabels = [];
foreach (['subject', 'correspondent_id', 'received_at', 'sent_at', 'document_date', 'due_date', 'channel', 'department_id', 'priority', 'confidentiality', 'external_reference', 'summary'] as $field) {
    $fieldLabels[$field] = __('mail.fields.' . $field);
}
?>
<?php $this->start('title') ?><?= e($title) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="mail-page" class="lt-object-page" data-lt-object-page data-editing show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('mail.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/mails"><?= e(__('mail.title')) ?></ui5-breadcrumbs-item>
            <?php if ($replyTo !== null): ?>
                <ui5-breadcrumbs-item href="<?= e($basePath) ?>/mails/<?= e($replyTo->id) ?>"><?= e($replyTo->reference) ?></ui5-breadcrumbs-item>
            <?php endif; ?>
            <ui5-breadcrumbs-item><?= e(__('object.creating')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($title) ?></ui5-title>
        <div slot="subheading" class="lt-tags"><?= Ui5::tag('direction', $direction->value) ?></div>
    </ui5-dynamic-page-title>

    <div class="lt-object-page__content">
        <?php if ($replyTo !== null): ?>
            <ui5-message-strip design="Information" hide-close-button class="lt-op-strip"><?= e(__('link.replying_to', ['reference' => $replyTo->reference, 'subject' => $replyTo->subject])) ?></ui5-message-strip>
        <?php endif; ?>
        <section class="lt-op-section" id="general" aria-labelledby="general-title">
            <ui5-title level="H2" size="H4" id="general-title"><?= e(__('object.general')) ?></ui5-title>
            <ui5-label wrapping-type="Normal"><?= e(__('mail.reference_auto')) ?></ui5-label>
            <form id="mail-form" method="post" action="<?= e($basePath) ?>/mails" novalidate>
                <?= $csrf->field() ?>
                <input type="hidden" name="direction" value="<?= e($direction->value) ?>">
                <?php if ($replyTo !== null): ?>
                    <input type="hidden" name="reply_to" value="<?= e($replyTo->id) ?>">
                <?php endif; ?>
                <?= $this->partial('mails/_fields', [
                    'direction' => $direction, 'values' => $values, 'errors' => $errors,
                    'correspondentLabel' => $correspondentLabel, 'departments' => $departments, 'isNew' => true,
                ]) ?>
            </form>
        </section>
    </div>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?= $this->partial('partials/object-page/messages', ['errors' => $errors, 'labels' => $fieldLabels]) ?>
        <ui5-button slot="endContent" design="Emphasized" data-lt-submit="mail-form"><?= e(__('common.save')) ?></ui5-button>
        <ui5-button slot="endContent" design="Transparent" data-lt-href="<?= $replyTo !== null ? '/mails/' . e($replyTo->id) : '/mails' ?>"><?= e(__('common.cancel')) ?></ui5-button>
    </ui5-bar>
</ui5-dynamic-page>
