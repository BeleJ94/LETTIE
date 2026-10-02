<?php
/**
 * Minimal layout for printable documents (no navigation).
 *
 * @var App\Core\View $this
 * @var string $basePath
 */
$assets = $basePath . '/assets';
$vendor = $assets . '/vendor';
?>
<!doctype html>
<html lang="<?= e($locale ?? 'fr') ?>" data-lt-theme-lock="sap_horizon">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <title><?= e($this->section('title', __('app.name'))) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($assets) ?>/img/logo.svg">
    <script src="<?= e($assets) ?>/js/lt-theme.js"></script>
    <link rel="stylesheet" href="<?= e($vendor) ?>/ui5-webcomponents-2.27.2/ui5-fonts.css">
    <link rel="stylesheet" href="<?= e($assets) ?>/css/app.css">
</head>
<body class="lt-print-page"
      data-base-path="<?= e($basePath) ?>"
      data-locale="<?= e($locale ?? 'fr') ?>"
      data-timezone="<?= e($timezone) ?>"
      data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<?= $this->section('content') ?>
<script src="<?= e($vendor) ?>/jquery-3.7.1/jquery.min.js"></script>
<script src="<?= e($vendor) ?>/lucide-0.460.0/lucide.min.js"></script>
<script src="<?= e($assets) ?>/js/lt-core.js"></script>
<script src="<?= e($assets) ?>/js/lt-tables.js"></script>
<script src="<?= e($assets) ?>/js/lt-export.js"></script>
<script src="<?= e($assets) ?>/js/app.js"></script>
<script type="module" src="<?= e($vendor) ?>/ui5-webcomponents-2.27.2/ui5.js"></script>
</body>
</html>
