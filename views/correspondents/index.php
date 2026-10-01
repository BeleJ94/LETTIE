<?php /** @var App\Core\View $this */ ?>
<?php $this->layout('layouts/main') ?>
<?php $this->start('title') ?><?= e(__('correspondent.title')) ?><?php $this->stop() ?>

<div class="lt-page-header">
    <h1><?= e(__('correspondent.title')) ?></h1>
    <a class="lt-btn lt-btn--primary" href="<?= e($basePath) ?>/correspondents/new"><i data-lucide="user-plus"></i><?= e(__('correspondent.new')) ?></a>
</div>

<form id="correspondent-filters" class="lt-card lt-filters">
    <label class="lt-check"><input type="checkbox" name="inactive" value="1"> <?= e(__('correspondent.show_inactive')) ?></label>
</form>

<div class="lt-card lt-table-wrap">
    <table class="lt-table" data-lt-table data-url="/correspondents/data" data-filters="correspondent-filters">
        <thead>
        <tr>
            <th data-lt-name="name" data-lt-render="link:/correspondents/{id}/edit"><?= e(__('correspondent.fields.name')) ?></th>
            <th data-lt-name="type" data-lt-render="badge:enums.correspondent_type"><?= e(__('correspondent.fields.type')) ?></th>
            <th data-lt-name="organization"><?= e(__('correspondent.fields.organization')) ?></th>
            <th data-lt-name="email"><?= e(__('correspondent.fields.email')) ?></th>
            <th data-lt-name="city"><?= e(__('correspondent.fields.city')) ?></th>
            <th data-lt-name="mails_count" data-lt-render="number" data-lt-class="lt-num"><?= e(__('correspondent.fields.mails_count')) ?></th>
        </tr>
        </thead>
    </table>
</div>
