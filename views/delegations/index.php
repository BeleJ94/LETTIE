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
use App\Core\Ui5;

$this->layout('layouts/main');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
?>
<?php $this->start('title') ?><?= e(__('delegation.title')) ?><?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

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
        <form id="delegation-form" method="post" action="<?= e($basePath) ?>/delegations" novalidate data-lt-validate>
            <?= $csrf->field() ?>
            <ui5-form layout="S1 M1 L1 XL1" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('delegation.new')) ?>">
                <?php if ($canChooseDelegator): ?>
                    <ui5-form-item>
                        <ui5-label slot="labelContent" for="delegator_id" show-colon><?= e(__('delegation.fields.delegator_id')) ?></ui5-label>
                        <?php /* Long list: a ComboBox filters while typing; the id of the chosen person is what is submitted. */ ?>
                        <ui5-combobox id="delegator_id" name="delegator_id" selected-value="<?= e($v('delegator_id') ?: (string) $meId) ?>"<?= Ui5::state($errors, 'delegator_id') ?>>
                            <?php foreach ($users as $u): ?>
                                <ui5-cb-item text="<?= e($u['name']) ?>" value="<?= e($u['id']) ?>" additional-text="<?= e(__('roles.' . $u['role'])) ?>"></ui5-cb-item>
                            <?php endforeach; ?>
                            <?= Ui5::stateMessage($errors, 'delegator_id') ?>
                        </ui5-combobox>
                    </ui5-form-item>
                <?php endif; ?>
                <ui5-form-item>
                    <ui5-label slot="labelContent" for="delegate_id" required show-colon><?= e(__('delegation.fields.delegate_id')) ?></ui5-label>
                    <ui5-combobox id="delegate_id" name="delegate_id" required selected-value="<?= e($v('delegate_id')) ?>"<?= Ui5::state($errors, 'delegate_id') ?>>
                        <?php foreach ($users as $u): ?>
                            <ui5-cb-item text="<?= e($u['name']) ?>" value="<?= e($u['id']) ?>" additional-text="<?= e(__('roles.' . $u['role'])) ?>"></ui5-cb-item>
                        <?php endforeach; ?>
                        <?= Ui5::stateMessage($errors, 'delegate_id') ?>
                    </ui5-combobox>
                </ui5-form-item>
                <ui5-form-item>
                    <ui5-label slot="labelContent" for="starts_on" required show-colon><?= e(__('delegation.fields.starts_on')) ?></ui5-label>
                    <ui5-date-picker id="starts_on" name="starts_on" required value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" placeholder="<?= e(__('list.date_placeholder')) ?>"
                        value="<?= e($v('starts_on')) ?>"<?= Ui5::state($errors, 'starts_on') ?>><?= Ui5::stateMessage($errors, 'starts_on') ?></ui5-date-picker>
                </ui5-form-item>
                <ui5-form-item>
                    <ui5-label slot="labelContent" for="ends_on" required show-colon><?= e(__('delegation.fields.ends_on')) ?></ui5-label>
                    <ui5-date-picker id="ends_on" name="ends_on" required value-format="yyyy-MM-dd" display-format="dd/MM/yyyy" min-date="<?= e($today) ?>" placeholder="<?= e(__('list.date_placeholder')) ?>"
                        value="<?= e($v('ends_on')) ?>"<?= Ui5::state($errors, 'ends_on') ?>><?= Ui5::stateMessage($errors, 'ends_on') ?></ui5-date-picker>
                </ui5-form-item>
                <ui5-form-item>
                    <ui5-label slot="labelContent" for="reason" show-colon><?= e(__('delegation.fields.reason')) ?></ui5-label>
                    <ui5-input id="reason" name="reason" maxlength="255" value="<?= e($v('reason')) ?>"<?= Ui5::state($errors, 'reason') ?>><?= Ui5::stateMessage($errors, 'reason') ?></ui5-input>
                </ui5-form-item>
            </ui5-form>
            <div class="lt-form-actions">
                <ui5-button design="Emphasized" icon="add" data-lt-submit="delegation-form"><?= e(__('delegation.save')) ?></ui5-button>
            </div>
        </form>
    </section>
</div>
