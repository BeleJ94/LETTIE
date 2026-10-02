<?php
/**
 * Editable fields of a mail (Object Page in edit or creation mode).
 * Field ids are the field names: the message popover focuses them (data-lt-focus).
 *
 * @var App\Core\View $this
 * @var App\Domain\Mail\Direction $direction
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var string $correspondentLabel
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var bool $isNew
 * @var ?list<string> $only fields to render (a wizard step); null: all of them
 * @var ?string $layout ui5-form layout
 */
use App\Core\Ui5;
use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Priority;

$isIncoming = $direction === Direction::Incoming;
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$departmentOptions = ['' => __('common.none')] + array_column($departments, 'name', 'id');
$dateField = $isIncoming ? 'received_at' : 'sent_at';
$only ??= null;
$layout ??= 'S1 M2 L3 XL3';
$show = static fn (string $field): bool => $only === null || in_array($field, $only, true);
?>
<ui5-form layout="<?= e($layout) ?>" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('object.general')) ?>">
    <?php if ($show('subject')): ?>
    <ui5-form-item column-span="2">
        <ui5-label slot="labelContent" for="subject" required show-colon><?= e(__('mail.fields.subject')) ?></ui5-label>
        <ui5-input id="subject" name="subject" maxlength="255" required value="<?= e($v('subject')) ?>"<?= Ui5::state($errors, 'subject') ?>><?= Ui5::stateMessage($errors, 'subject') ?></ui5-input>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('correspondent_id')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="correspondent_id" required show-colon><?= e(__('mail.fields.correspondent_id')) ?></ui5-label>
        <div class="lt-field-stack">
            <?php /* The text is the search; the chosen id travels in the hidden field. */ ?>
            <ui5-input id="correspondent_id" required show-suggestions no-typeahead data-lt-suggest data-url="/correspondents/search" data-target="correspondent-value" data-lt-requires="correspondent-value"
                       value="<?= e($correspondentLabel) ?>" placeholder="<?= e(__('mail.correspondent_search')) ?>"<?= Ui5::state($errors, 'correspondent_id') ?>>
                <ui5-icon slot="icon" name="search"></ui5-icon>
                <?= Ui5::stateMessage($errors, 'correspondent_id') ?>
            </ui5-input>
            <input type="hidden" id="correspondent-value" name="correspondent_id" value="<?= e($v('correspondent_id')) ?>">
            <ui5-link href="<?= e($basePath) ?>/correspondents/new" target="_blank" accessible-name="<?= e(__('object.new_tab')) ?>"><?= e(__('mail.correspondent_new')) ?></ui5-link>
        </div>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show($dateField)): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="<?= e($dateField) ?>" <?= $isIncoming ? 'required' : '' ?> show-colon><?= e(__('mail.fields.' . $dateField)) ?></ui5-label>
        <ui5-datetime-picker id="<?= e($dateField) ?>" name="<?= e($dateField) ?>" <?= $isIncoming ? 'required' : '' ?>
            value-format="yyyy-MM-dd'T'HH:mm" display-format="dd/MM/yyyy HH:mm" placeholder="<?= e(__('list.datetime_placeholder')) ?>"
            value="<?= e($v($dateField)) ?>"<?= Ui5::state($errors, $dateField) ?>><?= Ui5::stateMessage($errors, $dateField) ?></ui5-datetime-picker>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('document_date')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="document_date" show-colon><?= e(__('mail.fields.document_date')) ?></ui5-label>
        <ui5-date-picker id="document_date" name="document_date" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"
            value="<?= e($v('document_date')) ?>"<?= Ui5::state($errors, 'document_date') ?>><?= Ui5::stateMessage($errors, 'document_date') ?></ui5-date-picker>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('due_date')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="due_date" show-colon><?= e(__('mail.fields.due_date')) ?></ui5-label>
        <div class="lt-field-stack">
            <ui5-date-picker id="due_date" name="due_date" value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"
                value="<?= e($v('due_date')) ?>"<?= Ui5::state($errors, 'due_date') ?>><?= Ui5::stateMessage($errors, 'due_date') ?></ui5-date-picker>
            <?php if ($isNew && $isIncoming): ?>
                <ui5-label wrapping-type="Normal"><?= e(__('deadline.default_help', DueDatePolicy::DEFAULT_WORKING_DAYS)) ?></ui5-label>
            <?php endif; ?>
        </div>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('channel')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="channel" required show-colon><?= e(__('mail.fields.channel')) ?></ui5-label>
        <ui5-select id="channel" name="channel"<?= Ui5::state($errors, 'channel') ?>><?= Ui5::enumOptions(Channel::cases(), 'channel', $v('channel')) ?><?= Ui5::stateMessage($errors, 'channel') ?></ui5-select>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('department_id')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="department_id" show-colon><?= e(__('mail.fields.department_id')) ?></ui5-label>
        <ui5-select id="department_id" name="department_id"<?= Ui5::state($errors, 'department_id') ?>><?= Ui5::options($departmentOptions, $v('department_id')) ?><?= Ui5::stateMessage($errors, 'department_id') ?></ui5-select>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('priority')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="priority" required show-colon><?= e(__('mail.fields.priority')) ?></ui5-label>
        <ui5-select id="priority" name="priority"<?= Ui5::state($errors, 'priority') ?>><?= Ui5::enumOptions(Priority::cases(), 'priority', $v('priority')) ?><?= Ui5::stateMessage($errors, 'priority') ?></ui5-select>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('confidentiality')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="confidentiality" required show-colon><?= e(__('mail.fields.confidentiality')) ?></ui5-label>
        <ui5-select id="confidentiality" name="confidentiality"<?= Ui5::state($errors, 'confidentiality') ?>><?= Ui5::enumOptions(Confidentiality::cases(), 'confidentiality', $v('confidentiality')) ?><?= Ui5::stateMessage($errors, 'confidentiality') ?></ui5-select>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('external_reference')): ?>
    <ui5-form-item>
        <ui5-label slot="labelContent" for="external_reference" show-colon><?= e(__('mail.fields.external_reference')) ?></ui5-label>
        <ui5-input id="external_reference" name="external_reference" maxlength="100" value="<?= e($v('external_reference')) ?>"<?= Ui5::state($errors, 'external_reference') ?>><?= Ui5::stateMessage($errors, 'external_reference') ?></ui5-input>
    </ui5-form-item>
    <?php endif; ?>

    <?php if ($show('summary')): ?>
    <ui5-form-item column-span="3">
        <ui5-label slot="labelContent" for="summary" show-colon><?= e(__('mail.fields.summary')) ?></ui5-label>
        <ui5-textarea id="summary" name="summary" maxlength="5000" rows="5" growing growing-max-rows="12" value="<?= e($v('summary')) ?>"<?= Ui5::state($errors, 'summary') ?>><?= Ui5::stateMessage($errors, 'summary') ?></ui5-textarea>
    </ui5-form-item>
    <?php endif; ?>
</ui5-form>
