<?php
/**
 * @var App\Core\View $this
 * @var list<App\Domain\Delegation\Delegation> $delegations
 * @var array<int, bool> $canManage
 * @var list<array{id: int, site_id: int, name: string, role: string}> $users
 * @var bool $canChooseDelegator
 * @var int $meId
 * @var string $today
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 */
$this->layout('layouts/main');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
$fieldErrors = fn (string $key): string => isset($errors[$key]) ? $this->partial('partials/field-errors', ['field' => $key, 'messages' => $errors[$key]]) : '';
?>
<?php $this->start('title') ?><?= e(__('delegation.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('delegation.title')) ?></h1>
</div>
<p class="lt-muted"><?= e(__('delegation.help')) ?></p>

<div class="lt-columns">
    <section class="lt-card" aria-labelledby="current-title">
        <h2 id="current-title"><?= e(__('delegation.current')) ?></h2>
        <?php if ($delegations === []): ?>
            <p class="lt-muted"><?= e(__('delegation.none')) ?></p>
        <?php else: ?>
            <ul class="lt-list">
                <?php foreach ($delegations as $d): ?>
                    <li>
                        <div>
                            <strong><?= e($d->delegatorName) ?></strong>
                            <i data-lucide="arrow-right"></i>
                            <strong><?= e($d->delegateName) ?></strong>
                            <span class="lt-badge lt-badge--<?= $d->isActiveOn($today) ? 'in_progress' : 'registered' ?>">
                                <?= e(__($d->isActiveOn($today) ? 'delegation.active' : 'delegation.upcoming')) ?>
                            </span>
                        </div>
                        <div class="lt-muted"><?= e(__('delegation.period', ['from' => local_date($d->startsOn), 'to' => local_date($d->endsOn)])) ?></div>
                        <?php if ($d->reason !== null): ?><div><?= e($d->reason) ?></div><?php endif; ?>
                        <?php if ($canManage[$d->id] ?? false): ?>
                            <form method="post" action="<?= e($basePath) ?>/delegations/<?= e($d->id) ?>/cancel" class="lt-inline-form"
                                  data-lt-confirm="<?= e(__('delegation.confirm_cancel', ['name' => $d->delegatorName])) ?>"
                                  data-lt-confirm-button="<?= e(__('delegation.cancel')) ?>" data-lt-confirm-danger>
                                <?= $csrf->field() ?>
                                <button type="submit" class="lt-btn lt-btn--ghost"><i data-lucide="x"></i><?= e(__('delegation.cancel')) ?></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lt-card" aria-labelledby="new-title">
        <h2 id="new-title"><?= e(__('delegation.new')) ?></h2>
        <?php if ($errors !== []): ?>
            <div class="lt-alert lt-alert--error" role="alert"><?= e(__('js.errors.validation')) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e($basePath) ?>/delegations" class="lt-form" novalidate>
            <?= $csrf->field() ?>
            <?php if ($canChooseDelegator): ?>
                <div class="lt-field">
                    <label for="delegator_id"><?= e(__('delegation.fields.delegator_id')) ?></label>
                    <select id="delegator_id" name="delegator_id"<?= $invalid('delegator_id') ?>>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= e($u['id']) ?>"<?= (string) $u['id'] === ($v('delegator_id') ?: (string) $meId) ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fieldErrors('delegator_id') ?>
                </div>
            <?php endif; ?>
            <div class="lt-field">
                <label for="delegate_id"><?= e(__('delegation.fields.delegate_id')) ?> *</label>
                <select id="delegate_id" name="delegate_id" required<?= $invalid('delegate_id') ?>>
                    <option value="">—</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= e($u['id']) ?>"<?= (string) $u['id'] === $v('delegate_id') ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $fieldErrors('delegate_id') ?>
            </div>
            <div class="lt-field">
                <label for="starts_on"><?= e(__('delegation.fields.starts_on')) ?> *</label>
                <input type="date" id="starts_on" name="starts_on" required value="<?= e($v('starts_on')) ?>"<?= $invalid('starts_on') ?>>
                <?= $fieldErrors('starts_on') ?>
            </div>
            <div class="lt-field">
                <label for="ends_on"><?= e(__('delegation.fields.ends_on')) ?> *</label>
                <input type="date" id="ends_on" name="ends_on" required min="<?= e($today) ?>" value="<?= e($v('ends_on')) ?>"<?= $invalid('ends_on') ?>>
                <?= $fieldErrors('ends_on') ?>
            </div>
            <div class="lt-field">
                <label for="reason"><?= e(__('delegation.fields.reason')) ?></label>
                <input type="text" id="reason" name="reason" maxlength="255" value="<?= e($v('reason')) ?>"<?= $invalid('reason') ?>>
                <?= $fieldErrors('reason') ?>
            </div>
            <button type="submit"><i data-lucide="calendar-plus"></i><?= e(__('delegation.save')) ?></button>
        </form>
    </section>
</div>
