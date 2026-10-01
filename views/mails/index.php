<?php
/**
 * @var App\Core\View $this
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var bool $canCreate
 */
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;

$this->layout('layouts/main');
?>
<?php $this->start('title') ?><?= e(__('mail.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('mail.title')) ?></h1>
    <?php if ($canCreate): ?>
        <div class="lt-actions">
            <a class="lt-btn lt-btn--primary" href="<?= e($basePath) ?>/mails/new?direction=incoming"><i data-lucide="mail-plus"></i><?= e(__('mail.new_incoming')) ?></a>
            <a class="lt-btn" href="<?= e($basePath) ?>/mails/new?direction=outgoing"><i data-lucide="send"></i><?= e(__('mail.new_outgoing')) ?></a>
        </div>
    <?php endif; ?>
</div>

<form id="mail-filters" class="lt-card lt-filters" role="search" aria-label="<?= e(__('mail.filters.title')) ?>">
    <div class="lt-field">
        <label for="f-direction"><?= e(__('mail.fields.direction')) ?></label>
        <select id="f-direction" name="direction">
            <option value=""><?= e(__('common.all')) ?></option>
            <?php foreach (Direction::cases() as $case): ?>
                <option value="<?= e($case->value) ?>"><?= e(__('enums.direction.' . $case->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="lt-field">
        <label for="f-status"><?= e(__('mail.fields.status')) ?></label>
        <select id="f-status" name="status">
            <option value=""><?= e(__('common.all')) ?></option>
            <?php foreach (MailStatus::cases() as $case): ?>
                <option value="<?= e($case->value) ?>"><?= e(__('enums.status.' . $case->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="lt-field">
        <label for="f-priority"><?= e(__('mail.fields.priority')) ?></label>
        <select id="f-priority" name="priority">
            <option value=""><?= e(__('common.all')) ?></option>
            <?php foreach (Priority::cases() as $case): ?>
                <option value="<?= e($case->value) ?>"><?= e(__('enums.priority.' . $case->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($departments !== []): ?>
        <div class="lt-field">
            <label for="f-department"><?= e(__('mail.fields.department_id')) ?></label>
            <select id="f-department" name="department_id">
                <option value=""><?= e(__('common.all')) ?></option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= e($department['id']) ?>"><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="lt-field">
        <label for="f-from"><?= e(__('mail.filters.date_from')) ?></label>
        <input type="date" id="f-from" name="date_from">
    </div>
    <div class="lt-field">
        <label for="f-to"><?= e(__('mail.filters.date_to')) ?></label>
        <input type="date" id="f-to" name="date_to">
    </div>
    <label class="lt-check"><input type="checkbox" name="mine" value="1"> <?= e(__('mail.filters.mine')) ?></label>
    <label class="lt-check"><input type="checkbox" name="overdue" value="1"> <?= e(__('mail.filters.overdue')) ?></label>
    <div class="lt-filters__actions">
        <button type="reset" class="lt-btn lt-btn--ghost"><i data-lucide="rotate-ccw"></i><?= e(__('mail.filters.reset')) ?></button>
    </div>
</form>

<div class="lt-card lt-table-wrap">
    <div class="lt-table-toolbar">
        <?php foreach (['xlsx' => ['file-spreadsheet', 'export.excel'], 'pdf' => ['file-text', 'export.pdf']] as $format => [$icon, $label]): ?>
            <button type="button" class="lt-btn lt-btn--ghost" data-lt-export="<?= e($format) ?>" data-source="/mails/export"
                    data-set="mails" data-filters="mail-filters" data-table="#mails-table" data-title="<?= e(__('export.mails_title')) ?>">
                <i data-lucide="<?= e($icon) ?>"></i><?= e(__($label)) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <table id="mails-table" class="lt-table" data-lt-table data-url="/mails/data" data-filters="mail-filters"
           data-order-column="4" data-order-dir="desc">
        <thead>
        <tr>
            <th data-lt-name="reference" data-lt-render="link:/mails/{id}"><?= e(__('mail.fields.reference')) ?></th>
            <th data-lt-name="direction" data-lt-render="badge:enums.direction"><?= e(__('mail.fields.direction')) ?></th>
            <th data-lt-name="subject"><?= e(__('mail.fields.subject')) ?></th>
            <th data-lt-name="correspondent_name"><?= e(__('mail.fields.correspondent_name')) ?></th>
            <th data-lt-name="mail_date" data-lt-render="datetime"><?= e(__('mail.fields.mail_date')) ?></th>
            <th data-lt-name="due_date" data-lt-render="due"><?= e(__('mail.fields.due_date')) ?></th>
            <th data-lt-name="priority" data-lt-render="badge:enums.priority"><?= e(__('mail.fields.priority')) ?></th>
            <th data-lt-name="status" data-lt-render="badge:enums.status"><?= e(__('mail.fields.status')) ?></th>
            <th data-lt-name="attachments_count" data-lt-render="number" data-lt-sortable="false" data-lt-class="lt-num"><?= e(__('mail.fields.attachments_count')) ?></th>
        </tr>
        </thead>
    </table>
</div>
