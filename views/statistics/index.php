<?php
/**
 * @var App\Core\View $this
 * @var string $from
 * @var string $to
 * @var list<array{id: int, site_id: int, name: string}> $departments
 */
$this->layout('layouts/main');
$charts = [
    'volumes' => ['stats.charts.volumes', 'lt-chart-card--wide'],
    'departments' => ['stats.charts.departments', ''],
    'processing' => ['stats.charts.processing', ''],
    'overdue' => ['stats.charts.overdue', ''],
    'correspondents' => ['stats.charts.correspondents', ''],
];
?>
<?php $this->start('title') ?><?= e(__('stats.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('stats.title')) ?></h1>
</div>

<form id="stats-filters" class="lt-card lt-filters" aria-label="<?= e(__('mail.filters.title')) ?>">
    <div class="lt-field">
        <label for="s-from"><?= e(__('stats.from')) ?></label>
        <input type="date" id="s-from" name="from" value="<?= e($from) ?>" required>
    </div>
    <div class="lt-field">
        <label for="s-to"><?= e(__('stats.to')) ?></label>
        <input type="date" id="s-to" name="to" value="<?= e($to) ?>" required>
    </div>
    <div class="lt-field">
        <label for="s-granularity"><?= e(__('stats.granularity')) ?></label>
        <select id="s-granularity" name="granularity">
            <option value=""><?= e(__('stats.auto')) ?></option>
            <?php foreach (App\Domain\Stats\Granularity::cases() as $g): ?>
                <option value="<?= e($g->value) ?>"><?= e(__('stats.granularities.' . $g->value)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($departments !== []): ?>
        <div class="lt-field">
            <label for="s-dept"><?= e(__('mail.fields.department_id')) ?></label>
            <select id="s-dept" name="department_id">
                <option value=""><?= e(__('common.all')) ?></option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</form>

<div class="lt-stats-page" data-lt-stats data-url="/statistics/data" data-filters="stats-filters" aria-live="polite">
    <div class="lt-stats lt-stats--kpi" data-lt-kpis></div>

    <div class="lt-chart-grid">
        <?php foreach ($charts as $name => [$titleKey, $class]): ?>
            <section class="lt-card lt-chart-card <?= e($class) ?>" aria-labelledby="chart-<?= e($name) ?>">
                <h2 id="chart-<?= e($name) ?>"><?= e(__($titleKey)) ?></h2>
                <div class="lt-chart"><canvas data-chart="<?= e($name) ?>" role="img" aria-label="<?= e(__($titleKey)) ?>"></canvas></div>
                <p class="lt-chart-card__empty lt-muted"><?= e(__('stats.empty')) ?></p>
                <details class="lt-chart-card__data">
                    <summary><?= e(__('stats.show_data')) ?></summary>
                    <div class="lt-table-wrap" data-chart-table="<?= e($name) ?>"></div>
                </details>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/vendor/chartjs-4.4.7/chart.umd.js"></script>
<script src="<?= e($basePath) ?>/assets/js/pages/dashboard.js"></script>
<?php $this->stop() ?>
