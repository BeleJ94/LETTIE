<?php
/**
 * @var App\Core\View $this
 * @var ?App\Domain\Correspondent\Correspondent $correspondent
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
use App\Domain\Correspondent\CorrespondentType;

$this->layout('layouts/main');
$title = $correspondent !== null ? __('correspondent.edit', ['name' => $correspondent->name]) : __('correspondent.new');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
$fieldErrors = fn (string $key): string => isset($errors[$key]) ? $this->partial('partials/field-errors', ['field' => $key, 'messages' => $errors[$key]]) : '';
$text = static function (string $name, string $type, int $max, bool $required, string $value, string $attrs) : string {
    return '<input type="' . $type . '" id="' . e($name) . '" name="' . e($name) . '" maxlength="' . $max . '"'
        . ($required ? ' required' : '') . ' value="' . e($value) . '"' . $attrs . '>';
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
      action="<?= e($basePath) ?><?= $correspondent !== null ? '/correspondents/' . e($correspondent->id) : '/correspondents' ?>">
    <?= $csrf->field() ?>
    <?php if ($correspondent !== null): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <fieldset class="lt-field lt-span-2">
        <legend><?= e(__('correspondent.fields.type')) ?> *</legend>
        <?php foreach (CorrespondentType::cases() as $case): ?>
            <label class="lt-check">
                <input type="radio" name="type" value="<?= e($case->value) ?>"<?= $case->value === $v('type') ? ' checked' : '' ?>>
                <?= e(__('enums.correspondent_type.' . $case->value)) ?>
            </label>
        <?php endforeach; ?>
        <?= $fieldErrors('type') ?>
    </fieldset>

    <?php foreach ([
        ['name', 'text', 190, true, 'lt-span-2'],
        ['organization', 'text', 190, false, ''],
        ['email', 'email', 190, false, ''],
        ['phone', 'tel', 50, false, ''],
        ['address_line1', 'text', 190, false, 'lt-span-2'],
        ['address_line2', 'text', 190, false, 'lt-span-2'],
        ['postal_code', 'text', 20, false, ''],
        ['city', 'text', 100, false, ''],
        ['country', 'text', 2, true, ''],
    ] as [$name, $type, $max, $required, $class]): ?>
        <div class="lt-field <?= e($class) ?>">
            <label for="<?= e($name) ?>"><?= e(__('correspondent.fields.' . $name)) ?><?= $required ? ' *' : '' ?></label>
            <?= $text($name, $type, $max, $required, $v($name), $invalid($name)) ?>
            <?= $fieldErrors($name) ?>
        </div>
    <?php endforeach; ?>

    <div class="lt-field lt-span-2">
        <label for="notes"><?= e(__('correspondent.fields.notes')) ?></label>
        <textarea id="notes" name="notes" rows="3" maxlength="5000"<?= $invalid('notes') ?>><?= e($v('notes')) ?></textarea>
        <?= $fieldErrors('notes') ?>
    </div>

    <label class="lt-check lt-span-2">
        <input type="checkbox" name="is_active" value="1"<?= $v('is_active') === '1' ? ' checked' : '' ?>>
        <?= e(__('correspondent.fields.is_active')) ?>
    </label>

    <div class="lt-form__actions lt-span-2">
        <a class="lt-btn lt-btn--ghost" href="<?= e($basePath) ?>/correspondents"><?= e(__('common.cancel')) ?></a>
        <button type="submit"><i data-lucide="save"></i><?= e(__('common.save')) ?></button>
    </div>
</form>
