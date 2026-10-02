<?php
/**
 * @floorplan Launchpad
 *
 * Home page: groups of tiles by role, exceptions first (App\Domain\Launchpad\Launchpad),
 * then the upcoming deadlines. UI5 components only (docs/FIORI_DESIGN.md §11).
 *
 * @var App\Core\View $this
 * @var App\Domain\Auth\User $currentUser
 * @var string $today
 * @var list<array{key: string, tiles: list<array{key: string, icon: string, href: string, value: int|float|null, unit: ?string, state: string, dynamic: bool}>}> $groups
 * @var list<array{id: int, reference: string, subject: string, due_date: string, status: string, priority: string}> $upcoming
 */
use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Deadline\DueStatus;
use App\Domain\Launchpad\Launchpad;

$this->layout('layouts/main');
$number = static fn (int|float $value): string => number_format(
    $value,
    is_float($value) ? 1 : 0,
    ($locale ?? 'fr') === 'fr' ? ',' : '.',
    ($locale ?? 'fr') === 'fr' ? "\u{202F}" : ','
);
$dueDesign = [DueStatus::Overdue->value => 'Negative', DueStatus::Today->value => 'Critical'];
$priorityDesign = ['urgent' => 'Negative', 'high' => 'Critical'];
?>
<?php $this->start('title') ?><?= e(__('launchpad.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-launchpad<?php $this->stop() ?>

<ui5-title level="H1" size="H3" class="lt-launchpad__title"><?= e(__('launchpad.welcome', ['name' => $currentUser->firstName])) ?></ui5-title>

<?php foreach ($groups as $group): ?>
    <section class="lt-launchpad__group" data-group="<?= e($group['key']) ?>" aria-labelledby="group-<?= e($group['key']) ?>">
        <ui5-title level="H2" size="H5" id="group-<?= e($group['key']) ?>"><?= e(__('launchpad.groups.' . $group['key'])) ?></ui5-title>
        <div class="lt-tiles" role="list">
            <?php foreach ($group['tiles'] as $tile): ?>
                <?php
                $title = __('launchpad.tiles.' . $tile['key'] . '.title');
                $value = $tile['value'] === null ? null : $number($tile['value']);
                $unit = $tile['unit'] !== null ? __('launchpad.units.' . $tile['unit']) : '';
                $alert = $tile['state'] !== Launchpad::STATE_NONE;
                $label = $tile['dynamic']
                    ? __('launchpad.tile_label', ['title' => $title, 'value' => trim(($value ?? __('launchpad.no_data')) . ' ' . $unit)])
                    : $title;
                ?>
                <ui5-card class="lt-tile" role="listitem" data-tile="<?= e($tile['key']) ?>" data-state="<?= e($tile['state']) ?>"
                          accessible-name="<?= e($label) ?>">
                    <ui5-card-header slot="header" interactive data-lt-href="<?= e($tile['href']) ?>"
                                     title-text="<?= e($title) ?>"
                                     subtitle-text="<?= e(__('launchpad.tiles.' . $tile['key'] . '.subtitle')) ?>">
                        <ui5-icon slot="avatar" name="<?= e($tile['icon']) ?>"></ui5-icon>
                    </ui5-card-header>
                    <?php if ($tile['dynamic']): ?>
                        <div class="lt-tile__content">
                            <ui5-title level="H3" size="H1" class="lt-tile__value" wrapping-type="None"><?= e($value ?? '–') ?></ui5-title>
                            <?php if ($unit !== ''): ?>
                                <ui5-label class="lt-tile__unit"><?= e($unit) ?></ui5-label>
                            <?php endif; ?>
                            <?php if ($alert): ?>
                                <?php /* The state is never carried by colour alone (WCAG 1.4.1). */ ?>
                                <ui5-tag class="lt-tile__state" design="<?= e($tile['state']) ?>"><?= e(__('launchpad.action_needed')) ?></ui5-tag>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </ui5-card>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<section class="lt-launchpad__group" aria-labelledby="upcoming-title">
    <ui5-title level="H2" size="H5" id="upcoming-title"><?= e(__('deadline.upcoming')) ?></ui5-title>
    <ui5-card class="lt-launchpad__upcoming" accessible-name="<?= e(__('deadline.upcoming')) ?>">
        <?php if ($upcoming === []): ?>
            <ui5-illustrated-message name="NoActivities" design="Dot" title-text="<?= e(__('deadline.none')) ?>">
                <span slot="subtitle"></span>
            </ui5-illustrated-message>
        <?php else: ?>
            <ui5-list separators="Inner" accessible-name="<?= e(__('deadline.upcoming')) ?>" data-lt-links>
                <?php foreach ($upcoming as $item): ?>
                    <?php $due = DueDatePolicy::status($item['due_date'], $today, false); ?>
                    <ui5-li type="Navigation" data-lt-href="/mails/<?= e($item['id']) ?>"
                            description="<?= e($item['subject']) ?>"
                            additional-text="<?= e(local_date($item['due_date'])) ?>"
                            additional-text-state="<?= e($dueDesign[$due->value] ?? 'None') ?>"
                            <?php if (isset($priorityDesign[$item['priority']])): ?>
                                highlight="<?= e($priorityDesign[$item['priority']]) ?>"
                            <?php endif; ?>><?= e($item['reference']) ?> · <?= e(__('enums.priority.' . $item['priority'])) ?></ui5-li>
                <?php endforeach; ?>
            </ui5-list>
        <?php endif; ?>
    </ui5-card>
</section>
