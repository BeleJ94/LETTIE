<?php
/**
 * @var App\Core\View $this
 * @var App\Domain\Mail\Direction $direction
 * @var ?App\Domain\Mail\Mail $mail
 * @var ?App\Domain\Mail\Mail $replyTo incoming mail this outgoing mail answers
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var string $correspondentLabel
 * @var list<array{id: int, site_id: int, name: string}> $departments
 */
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Priority;

$this->layout('layouts/main');
$isIncoming = $direction === Direction::Incoming;
$title = $mail !== null ? __('mail.edit', ['reference' => $mail->reference]) : __($isIncoming ? 'mail.new_incoming' : 'mail.new_outgoing');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
$fieldErrors = fn (string $key): string => isset($errors[$key]) ? $this->partial('partials/field-errors', ['field' => $key, 'messages' => $errors[$key]]) : '';
$select = static function (string $name, array $cases, string $enum, string $current): string {
    $html = '';
    foreach ($cases as $case) {
        $html .= '<option value="' . e($case->value) . '"' . ($case->value === $current ? ' selected' : '') . '>'
            . e(__('enums.' . $enum . '.' . $case->value)) . '</option>';
    }
    return $html;
};
?>
<?php $this->start('title') ?><?= e($title) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e($title) ?></h1>
</div>

<?php if ($errors !== []): ?>
    <div class="lt-alert lt-alert--error" role="alert"><?= e(__('js.errors.validation')) ?></div>
<?php endif; ?>

<form method="post" class="lt-card lt-form lt-form--grid" novalidate
      action="<?= e($basePath) ?><?= $mail !== null ? '/mails/' . e($mail->id) : '/mails' ?>">
    <?= $csrf->field() ?>
    <?php if ($mail !== null): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php else: ?>
        <input type="hidden" name="direction" value="<?= e($direction->value) ?>">
        <?php if ($replyTo !== null): ?>
            <input type="hidden" name="reply_to" value="<?= e($replyTo->id) ?>">
            <div class="lt-alert lt-alert--info lt-span-2">
                <?= e(__('link.replying_to', ['reference' => $replyTo->reference, 'subject' => $replyTo->subject])) ?>
            </div>
        <?php endif; ?>
        <p class="lt-field__help lt-span-2"><?= e(__('mail.reference_auto')) ?></p>
    <?php endif; ?>

    <div class="lt-field lt-span-2">
        <label for="subject"><?= e(__('mail.fields.subject')) ?> *</label>
        <input type="text" id="subject" name="subject" maxlength="255" required value="<?= e($v('subject')) ?>"<?= $invalid('subject') ?>>
        <?= $fieldErrors('subject') ?>
    </div>

    <div class="lt-field lt-span-2" data-lt-picker data-url="/correspondents/search">
        <label for="correspondent"><?= e(__('mail.fields.correspondent_id')) ?> *</label>
        <input type="hidden" name="correspondent_id" value="<?= e($v('correspondent_id')) ?>" data-lt-picker-value>
        <input type="search" id="correspondent" autocomplete="off" value="<?= e($correspondentLabel) ?>"
               placeholder="<?= e(__('mail.correspondent_search')) ?>" data-lt-picker-input
               aria-autocomplete="list" aria-controls="correspondent-results"<?= $invalid('correspondent_id') ?>>
        <ul id="correspondent-results" class="lt-picker__results" data-lt-picker-results hidden></ul>
        <?= $fieldErrors('correspondent_id') ?>
        <a class="lt-field__help" href="<?= e($basePath) ?>/correspondents/new" target="_blank" rel="noopener"><?= e(__('mail.correspondent_new')) ?></a>
    </div>

    <?php if ($isIncoming): ?>
        <div class="lt-field">
            <label for="received_at"><?= e(__('mail.fields.received_at')) ?> *</label>
            <input type="datetime-local" id="received_at" name="received_at" required value="<?= e($v('received_at')) ?>"<?= $invalid('received_at') ?>>
            <?= $fieldErrors('received_at') ?>
        </div>
    <?php else: ?>
        <div class="lt-field">
            <label for="sent_at"><?= e(__('mail.fields.sent_at')) ?></label>
            <input type="datetime-local" id="sent_at" name="sent_at" value="<?= e($v('sent_at')) ?>"<?= $invalid('sent_at') ?>>
            <?= $fieldErrors('sent_at') ?>
        </div>
    <?php endif; ?>

    <div class="lt-field">
        <label for="document_date"><?= e(__('mail.fields.document_date')) ?></label>
        <input type="date" id="document_date" name="document_date" value="<?= e($v('document_date')) ?>"<?= $invalid('document_date') ?>>
        <?= $fieldErrors('document_date') ?>
    </div>

    <div class="lt-field">
        <label for="channel"><?= e(__('mail.fields.channel')) ?> *</label>
        <select id="channel" name="channel"<?= $invalid('channel') ?>><?= $select('channel', Channel::cases(), 'channel', $v('channel')) ?></select>
        <?= $fieldErrors('channel') ?>
    </div>

    <div class="lt-field">
        <label for="department_id"><?= e(__('mail.fields.department_id')) ?></label>
        <select id="department_id" name="department_id"<?= $invalid('department_id') ?>>
            <option value=""><?= e(__('common.none')) ?></option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= e($department['id']) ?>"<?= (string) $department['id'] === $v('department_id') ? ' selected' : '' ?>><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $fieldErrors('department_id') ?>
    </div>

    <div class="lt-field">
        <label for="priority"><?= e(__('mail.fields.priority')) ?> *</label>
        <select id="priority" name="priority"<?= $invalid('priority') ?>><?= $select('priority', Priority::cases(), 'priority', $v('priority')) ?></select>
        <?= $fieldErrors('priority') ?>
    </div>

    <div class="lt-field">
        <label for="confidentiality"><?= e(__('mail.fields.confidentiality')) ?> *</label>
        <select id="confidentiality" name="confidentiality"<?= $invalid('confidentiality') ?>><?= $select('confidentiality', Confidentiality::cases(), 'confidentiality', $v('confidentiality')) ?></select>
        <?= $fieldErrors('confidentiality') ?>
    </div>

    <div class="lt-field">
        <label for="due_date"><?= e(__('mail.fields.due_date')) ?></label>
        <input type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>"<?= $invalid('due_date') ?> aria-describedby="due_date-help">
        <?php if ($mail === null && $isIncoming): ?>
            <span class="lt-field__help" id="due_date-help"><?= e(__('deadline.default_help', App\Domain\Deadline\DueDatePolicy::DEFAULT_WORKING_DAYS)) ?></span>
        <?php endif; ?>
        <?= $fieldErrors('due_date') ?>
    </div>

    <div class="lt-field">
        <label for="external_reference"><?= e(__('mail.fields.external_reference')) ?></label>
        <input type="text" id="external_reference" name="external_reference" maxlength="100" value="<?= e($v('external_reference')) ?>"<?= $invalid('external_reference') ?>>
        <?= $fieldErrors('external_reference') ?>
    </div>

    <div class="lt-field lt-span-2">
        <label for="summary"><?= e(__('mail.fields.summary')) ?></label>
        <textarea id="summary" name="summary" maxlength="5000" rows="5"<?= $invalid('summary') ?>><?= e($v('summary')) ?></textarea>
        <?= $fieldErrors('summary') ?>
    </div>

    <div class="lt-form__actions lt-span-2">
        <a class="lt-btn lt-btn--ghost" href="<?= e($basePath) ?><?= $mail !== null ? '/mails/' . e($mail->id) : '/mails' ?>"><?= e(__('common.cancel')) ?></a>
        <button type="submit"><i data-lucide="save"></i><?= e(__('common.save')) ?></button>
    </div>
</form>

<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/correspondent-picker.js"></script>
<?php $this->stop() ?>
