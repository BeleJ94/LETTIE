<?php
/**
 * @var App\Core\View $this
 * @var list<App\Domain\Retention\RetentionRule> $rules
 * @var list<array{started_at: DateTimeImmutable, finished_at: ?DateTimeImmutable, status: string, dry_run: bool, summary: array<string, mixed>}> $runs
 * @var bool $canGlobal
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
use App\Domain\Mail\Direction;
use App\Domain\Retention\RetentionAction;

$this->layout('layouts/main');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
$fieldErrors = fn (string $key): string => isset($errors[$key]) ? $this->partial('partials/field-errors', ['field' => $key, 'messages' => $errors[$key]]) : '';
?>
<?php $this->start('title') ?><?= e(__('retention.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('retention.title')) ?></h1>
</div>
<p class="lt-muted"><?= e(__('retention.help')) ?></p>

<section class="lt-card lt-table-wrap" aria-labelledby="rules-title">
    <h2 id="rules-title"><?= e(__('retention.rules')) ?></h2>
    <?php if ($rules === []): ?>
        <p class="lt-muted"><?= e(__('retention.none')) ?></p>
    <?php else: ?>
        <table class="lt-table">
            <thead>
            <tr>
                <th><?= e(__('retention.fields.name')) ?></th>
                <th><?= e(__('retention.fields.action')) ?></th>
                <th><?= e(__('retention.fields.retention_months')) ?></th>
                <th><?= e(__('retention.fields.direction')) ?></th>
                <th><?= e(__('retention.fields.scope')) ?></th>
                <th><?= e(__('retention.fields.is_active')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td data-label="<?= e(__('retention.fields.name')) ?>"><?= e($rule->name) ?></td>
                    <td data-label="<?= e(__('retention.fields.action')) ?>"><?= e(__('enums.retention_action.' . $rule->action->value)) ?></td>
                    <td data-label="<?= e(__('retention.fields.retention_months')) ?>" class="lt-num"><?= e($rule->retentionMonths) ?></td>
                    <td data-label="<?= e(__('retention.fields.direction')) ?>"><?= e($rule->direction !== null ? __('enums.direction.' . $rule->direction->value) : __('common.all')) ?></td>
                    <td data-label="<?= e(__('retention.fields.scope')) ?>"><?= e(__($rule->siteId === null ? 'retention.all_sites' : 'retention.this_site')) ?></td>
                    <td data-label="<?= e(__('retention.fields.is_active')) ?>">
                        <form method="post" action="<?= e($basePath) ?>/retention-rules/<?= e($rule->id) ?>/toggle" class="lt-inline-form"
                              data-lt-confirm="<?= e(__($rule->isActive ? 'retention.confirm_disable' : 'retention.confirm_enable', ['name' => $rule->name])) ?>"
                              <?= $rule->isActive ? '' : 'data-lt-confirm-danger' ?>>
                            <?= $csrf->field() ?>
                            <input type="hidden" name="active" value="<?= $rule->isActive ? '0' : '1' ?>">
                            <span class="lt-badge lt-badge--<?= $rule->isActive ? 'answered' : 'closed' ?>"><?= e(__($rule->isActive ? 'common.yes' : 'common.no')) ?></span>
                            <button type="submit" class="lt-btn lt-btn--ghost"><?= e(__($rule->isActive ? 'retention.disable' : 'retention.enable')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<div class="lt-columns">
    <section class="lt-card" aria-labelledby="new-rule-title">
        <h2 id="new-rule-title"><?= e(__('retention.new')) ?></h2>
        <?php if ($errors !== []): ?>
            <div class="lt-alert lt-alert--error" role="alert"><?= e(__('js.errors.validation')) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e($basePath) ?>/retention-rules" class="lt-form" novalidate
              data-lt-confirm="<?= e(__('retention.confirm_create')) ?>" data-lt-confirm-danger>
            <?= $csrf->field() ?>
            <div class="lt-field">
                <label for="name"><?= e(__('retention.fields.name')) ?> *</label>
                <input type="text" id="name" name="name" maxlength="150" required value="<?= e($v('name')) ?>"<?= $invalid('name') ?>>
                <?= $fieldErrors('name') ?>
            </div>
            <div class="lt-field">
                <label for="action"><?= e(__('retention.fields.action')) ?> *</label>
                <select id="action" name="action" aria-describedby="action-help"<?= $invalid('action') ?>>
                    <?php foreach (RetentionAction::cases() as $case): ?>
                        <option value="<?= e($case->value) ?>"<?= $case->value === $v('action') ? ' selected' : '' ?>><?= e(__('enums.retention_action.' . $case->value)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="lt-field__help" id="action-help"><?= e(__('retention.action_help')) ?></span>
                <?= $fieldErrors('action') ?>
            </div>
            <div class="lt-field">
                <label for="retention_months"><?= e(__('retention.fields.retention_months')) ?> *</label>
                <input type="number" id="retention_months" name="retention_months" min="1" max="1200" required value="<?= e($v('retention_months')) ?>"<?= $invalid('retention_months') ?>>
                <?= $fieldErrors('retention_months') ?>
            </div>
            <div class="lt-field">
                <label for="direction"><?= e(__('retention.fields.direction')) ?></label>
                <select id="direction" name="direction">
                    <option value=""><?= e(__('common.all')) ?></option>
                    <?php foreach (Direction::cases() as $case): ?>
                        <option value="<?= e($case->value) ?>"<?= $case->value === $v('direction') ? ' selected' : '' ?>><?= e(__('enums.direction.' . $case->value)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($canGlobal): ?>
                <label class="lt-check"><input type="checkbox" name="all_sites" value="1"<?= $v('all_sites') === '1' ? ' checked' : '' ?>> <?= e(__('retention.all_sites')) ?></label>
            <?php endif; ?>
            <button type="submit"><i data-lucide="plus"></i><?= e(__('retention.save')) ?></button>
        </form>
    </section>

    <section class="lt-card" aria-labelledby="runs-title">
        <h2 id="runs-title"><?= e(__('retention.runs')) ?></h2>
        <?php if ($runs === []): ?>
            <p class="lt-alert lt-alert--warning"><?= e(__('retention.never_run')) ?></p>
        <?php else: ?>
            <ul class="lt-list">
                <?php foreach ($runs as $run): ?>
                    <li>
                        <div>
                            <span class="lt-badge lt-badge--<?= $run['status'] === 'success' ? 'answered' : ($run['status'] === 'failed' ? 'urgent' : 'registered') ?>"><?= e(__('retention.run_status.' . $run['status'])) ?></span>
                            <?= e(local_datetime($run['started_at'])) ?>
                            <?php if ($run['dry_run']): ?><span class="lt-muted">(<?= e(__('retention.dry_run')) ?>)</span><?php endif; ?>
                        </div>
                        <?php $s = $run['summary']; ?>
                        <?php if (isset($s['reminders'], $s['retention'])): ?>
                            <div class="lt-muted"><?= e(__('retention.run_summary', [
                                'notifications' => $s['reminders']['created'] ?? 0,
                                'archived' => $s['retention']['archived'] ?? 0,
                                'purged' => $s['retention']['purged_files'] ?? 0,
                            ])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($s['error'])): ?><div class="lt-field__error"><?= e($s['error']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
