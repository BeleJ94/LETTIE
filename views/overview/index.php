<?php
/**
 * @floorplan OverviewPage
 *
 * Overview of the steering roles: KPI cards, short work lists, simple charts.
 * Every card opens the detailed application. Card types and their rules: docs/FIORI_DESIGN.md §15.
 *
 * @var App\Core\View $this
 * @var string $today
 * @var list<array{key: string, value: int|float|null, unit: ?string, state: string, href: string}> $kpis
 * @var list<array{key: string, href: string, total: int, rows: list<array<string, mixed>>}> $lists
 * @var array{volumes: array{labels: list<string>, incoming: list<int>, outgoing: list<int>}, overdue: list<array{name: ?string, count: int}>} $charts
 */
use App\Core\Ui5;
use App\Domain\Deadline\DueDatePolicy;

$this->layout('layouts/main');
$fr = ($locale ?? 'fr') === 'fr';
$number = static fn (int|float $value): string => number_format($value, is_float($value) ? 1 : 0, $fr ? ',' : '.', $fr ? "\u{202F}" : ',');
$json = static fn (array $data): string => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$hasVolumes = array_sum($charts['volumes']['incoming']) + array_sum($charts['volumes']['outgoing']) > 0;
?>
<?php $this->start('title') ?><?= e(__('overview.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/vendor/chartjs-4.4.7/chart.umd.js"></script>
<script src="<?= e($basePath) ?>/assets/js/pages/overview.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="overview-page" class="lt-overview-page" data-lt-overview>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-title slot="heading" level="H1" size="H3"><?= e(__('overview.title')) ?></ui5-title>
        <ui5-label slot="subheading" wrapping-type="Normal"><?= e(__('overview.subtitle')) ?></ui5-label>
    </ui5-dynamic-page-title>

    <div class="lt-ov-grid" data-kpis="<?= e(count($kpis)) ?>" data-lists="<?= e(count($lists)) ?>" role="list" aria-label="<?= e(__('overview.cards')) ?>">
        <?php /* 1. KPI cards: one figure, its unit, its semantic state. */ ?>
        <?php foreach ($kpis as $kpi): ?>
            <?php $title = __('overview.kpi.' . $kpi['key'] . '.title'); ?>
            <ui5-card class="lt-ov-card lt-ov-card--kpi" role="listitem" data-card="kpi" data-key="<?= e($kpi['key']) ?>" data-state="<?= e($kpi['state']) ?>"
                      accessible-name="<?= e($title) ?>">
                <ui5-card-header slot="header" interactive data-lt-href="<?= e($kpi['href']) ?>"
                                 title-text="<?= e($title) ?>" subtitle-text="<?= e(__('overview.kpi.' . $kpi['key'] . '.subtitle')) ?>"></ui5-card-header>
                <div class="lt-ov-kpi">
                    <ui5-title level="H2" size="H1" class="lt-ov-kpi__value" wrapping-type="None"><?= e($kpi['value'] === null ? __('overview.no_value') : $number($kpi['value'])) ?></ui5-title>
                    <ui5-label><?= e(__('overview.kpi.' . $kpi['key'] . '.unit')) ?></ui5-label>
                    <?php /* The state is written, never carried by the colour alone. */ ?>
                    <ui5-tag class="lt-ov-kpi__state" design="<?= e($kpi['state']) ?>"><?= e(__('overview.states.' . $kpi['state'])) ?></ui5-tag>
                </div>
            </ui5-card>
        <?php endforeach; ?>

        <?php /* 2. List cards: the first rows of a work list, and how many there are in all. */ ?>
        <?php foreach ($lists as $list): ?>
            <?php $title = __('overview.list.' . $list['key'] . '.title'); ?>
            <ui5-card class="lt-ov-card lt-ov-card--list" role="listitem" data-card="list" data-key="<?= e($list['key']) ?>" accessible-name="<?= e($title) ?>">
                <ui5-card-header slot="header" interactive data-lt-href="<?= e($list['href']) ?>"
                                 title-text="<?= e($title) ?>" subtitle-text="<?= e(__('overview.list.' . $list['key'] . '.subtitle')) ?>"
                                 additional-text="<?= e(__('overview.shown', ['shown' => count($list['rows']), 'total' => $list['total']])) ?>"></ui5-card-header>
                <?php if ($list['rows'] === []): ?>
                    <ui5-illustrated-message name="NoEntries" design="Dot" title-text="<?= e(__('overview.list.' . $list['key'] . '.empty')) ?>">
                        <span slot="subtitle"></span>
                    </ui5-illustrated-message>
                <?php else: ?>
                    <ui5-list separators="Inner" accessible-name="<?= e($title) ?>" data-lt-links>
                        <?php foreach ($list['rows'] as $row): ?>
                            <?php
                            $due = $row['due_date'] !== null ? DueDatePolicy::status((string) $row['due_date'], $today, false) : null;
                            $text = $list['key'] === 'overdue'
                                ? __('overview.list.due', ['date' => local_date((string) $row['due_date'])])
                                : __('overview.list.received', ['date' => local_datetime(new DateTimeImmutable((string) $row['mail_date'], new DateTimeZone('UTC')), 'd/m/Y')]);
                            ?>
                            <ui5-li type="Navigation" data-lt-href="/mails/<?= e($row['id']) ?>" description="<?= e($row['subject']) ?>"
                                    additional-text="<?= e($text) ?>"
                                    additional-text-state="<?= e($list['key'] === 'overdue' && $due !== null ? (Ui5::DUE_DESIGNS[$due->value] ?? 'None') : 'None') ?>"
                                    <?= isset(Ui5::TAG_DESIGNS['priority'][$row['priority']]) && in_array(Ui5::TAG_DESIGNS['priority'][$row['priority']], ['Negative', 'Critical'], true)
                                        ? 'highlight="' . e(Ui5::TAG_DESIGNS['priority'][$row['priority']]) . '"' : '' ?>><?= e($row['reference']) ?></ui5-li>
                        <?php endforeach; ?>
                    </ui5-list>
                <?php endif; ?>
            </ui5-card>
        <?php endforeach; ?>

        <?php /* 3. Chart cards: one simple chart, its data available as text. */ ?>
        <ui5-card class="lt-ov-card lt-ov-card--chart" role="listitem" data-card="chart" data-key="volumes" accessible-name="<?= e(__('overview.chart.volumes.title')) ?>">
            <ui5-card-header slot="header" interactive data-lt-href="/statistics"
                             title-text="<?= e(__('overview.chart.volumes.title')) ?>" subtitle-text="<?= e(__('overview.chart.volumes.subtitle')) ?>"></ui5-card-header>
            <?php if ($hasVolumes): ?>
                <div class="lt-ov-chart">
                    <canvas data-lt-ov-chart="volumes" data-chart="<?= e($json($charts['volumes'])) ?>" role="img"
                            aria-label="<?= e(__('overview.chart.volumes.title')) ?> — <?= e(__('overview.chart.volumes.subtitle')) ?>"></canvas>
                </div>
                <ui5-panel collapsed header-text="<?= e(__('overview.show_data')) ?>" class="lt-ov-data">
                    <ui5-list separators="None" accessible-name="<?= e(__('overview.chart.volumes.title')) ?>">
                        <?php foreach ($charts['volumes']['labels'] as $i => $label): ?>
                            <ui5-li type="Inactive" additional-text="<?= e(__('enums.direction.incoming')) ?> <?= e($charts['volumes']['incoming'][$i]) ?> · <?= e(__('enums.direction.outgoing')) ?> <?= e($charts['volumes']['outgoing'][$i]) ?>"><?= e($label) ?></ui5-li>
                        <?php endforeach; ?>
                    </ui5-list>
                </ui5-panel>
            <?php else: ?>
                <ui5-illustrated-message name="NoData" design="Dot"><span slot="subtitle"></span></ui5-illustrated-message>
            <?php endif; ?>
        </ui5-card>

        <ui5-card class="lt-ov-card lt-ov-card--chart" role="listitem" data-card="chart" data-key="overdue" accessible-name="<?= e(__('overview.chart.overdue.title')) ?>">
            <ui5-card-header slot="header" interactive data-lt-href="/statistics"
                             title-text="<?= e(__('overview.chart.overdue.title')) ?>" subtitle-text="<?= e(__('overview.chart.overdue.subtitle')) ?>"></ui5-card-header>
            <?php if ($charts['overdue'] !== []): ?>
                <div class="lt-ov-chart">
                    <canvas data-lt-ov-chart="overdue" data-chart="<?= e($json($charts['overdue'])) ?>" role="img"
                            aria-label="<?= e(__('overview.chart.overdue.title')) ?> — <?= e(__('overview.chart.overdue.subtitle')) ?>"></canvas>
                </div>
                <ui5-panel collapsed header-text="<?= e(__('overview.show_data')) ?>" class="lt-ov-data">
                    <ui5-list separators="None" accessible-name="<?= e(__('overview.chart.overdue.title')) ?>">
                        <?php foreach ($charts['overdue'] as $row): ?>
                            <ui5-li type="Inactive" additional-text="<?= e($row['count']) ?>" additional-text-state="Negative"><?= e($row['name'] ?? __('js.stats.no_department')) ?></ui5-li>
                        <?php endforeach; ?>
                    </ui5-list>
                </ui5-panel>
            <?php else: ?>
                <ui5-illustrated-message name="NoEntries" design="Dot" title-text="<?= e(__('overview.chart.overdue.empty')) ?>"><span slot="subtitle"></span></ui5-illustrated-message>
            <?php endif; ?>
        </ui5-card>
    </div>
</ui5-dynamic-page>
