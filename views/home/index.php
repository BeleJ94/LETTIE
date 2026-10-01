<?php
/**
 * @var App\Core\View $this
 * @var string $today
 * @var array{overdue: int, today: int, week: int} $mine
 * @var ?array{overdue: int, today: int, week: int} $scope
 * @var list<array{id: int, reference: string, subject: string, due_date: string, status: string, priority: string}> $upcoming
 */
use App\Domain\Deadline\DueDatePolicy;

$this->layout('layouts/main');
$tiles = static function (array $counters, string $title): string {
    $html = '<section class="lt-card"><h2>' . e($title) . '</h2><div class="lt-stats">';
    foreach (['overdue' => 'alarm-clock', 'today' => 'calendar-clock', 'week' => 'calendar-range'] as $key => $icon) {
        $html .= '<div class="lt-stat lt-stat--' . e($key) . ($counters[$key] > 0 ? ' is-set' : '') . '">'
            . '<i data-lucide="' . e($icon) . '"></i>'
            . '<span class="lt-stat__value">' . e($counters[$key]) . '</span>'
            . '<span class="lt-stat__label">' . e(__('deadline.' . $key)) . '</span></div>';
    }
    return $html . '</div></section>';
};
?>
<?php $this->start('title') ?><?= e(__('nav.dashboard')) ?><?php $this->stop() ?>
<div class="lt-page-header">
    <h1><?= e(__('nav.dashboard')) ?></h1>
</div>

<div class="lt-columns">
    <?= $tiles($mine, __('deadline.mine')) ?>
    <?php if ($scope !== null): ?>
        <?= $tiles($scope, __('deadline.scope')) ?>
    <?php endif; ?>
</div>

<section class="lt-card" aria-labelledby="upcoming-title">
    <h2 id="upcoming-title"><?= e(__('deadline.upcoming')) ?></h2>
    <?php if ($upcoming === []): ?>
        <p class="lt-muted"><?= e(__('deadline.none')) ?></p>
    <?php else: ?>
        <ul class="lt-list">
            <?php foreach ($upcoming as $item): ?>
                <?php $due = DueDatePolicy::status($item['due_date'], $today, false); ?>
                <li>
                    <div>
                        <span class="lt-due lt-due--<?= e($due->value) ?>"><?= e(local_date($item['due_date'])) ?></span>
                        · <a href="<?= e($basePath) ?>/mails/<?= e($item['id']) ?>"><?= e($item['reference']) ?></a>
                        — <?= e($item['subject']) ?>
                        <span class="lt-badge lt-badge--<?= e($item['priority']) ?>"><?= e(__('enums.priority.' . $item['priority'])) ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
