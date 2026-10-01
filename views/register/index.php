<?php
/**
 * @var App\Core\View $this
 * @var string $from
 * @var string $to
 */
use App\Domain\Mail\Direction;

$this->layout('layouts/main');
?>
<?php $this->start('title') ?><?= e(__('register.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('register.title')) ?></h1>
</div>
<p class="lt-muted"><?= e(__('register.help')) ?></p>

<form id="register-form" class="lt-card lt-filters" aria-label="<?= e(__('register.title')) ?>">
    <div class="lt-field">
        <label for="r-direction"><?= e(__('mail.fields.direction')) ?></label>
        <select id="r-direction" name="direction">
            <?php foreach (Direction::cases() as $d): ?>
                <option value="<?= e($d->value) ?>"><?= e(__('enums.direction.' . $d->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="lt-field">
        <label for="r-from"><?= e(__('stats.from')) ?></label>
        <input type="date" id="r-from" name="from" value="<?= e($from) ?>" required>
    </div>
    <div class="lt-field">
        <label for="r-to"><?= e(__('stats.to')) ?></label>
        <input type="date" id="r-to" name="to" value="<?= e($to) ?>" required>
    </div>
    <div class="lt-filters__actions lt-actions">
        <?php foreach (['xlsx' => ['file-spreadsheet', 'export.excel'], 'pdf' => ['file-text', 'export.pdf']] as $format => [$icon, $label]): ?>
            <button type="button" class="lt-btn<?= $format === 'xlsx' ? ' lt-btn--primary' : '' ?>"
                    data-lt-export="<?= e($format) ?>" data-source="/register/data" data-set="register_{direction}"
                    data-filters="register-form"
                    data-title="<?= e(__('register.document_title')) ?>"
                    data-subtitle="<?= e(__('register.document_subtitle')) ?>">
                <i data-lucide="<?= e($icon) ?>"></i><?= e(__($label)) ?>
            </button>
        <?php endforeach; ?>
    </div>
</form>
