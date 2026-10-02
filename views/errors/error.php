<?php
/**
 * @floorplan None — page d'erreur (403, 404, 500), sans navigation
 *
 * @var App\Core\View $this
 */
?>
<?php $key = 'errors.' . $status; $text = __($key); ?>
<!doctype html>
<html lang="<?= e($locale ?? 'fr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('errors.title', ['status' => $status])) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($basePath ?? '') ?>/assets/img/logo.svg">
    <script src="<?= e($basePath ?? '') ?>/assets/js/lt-theme.js"></script>
    <link rel="stylesheet" href="<?= e($basePath ?? '') ?>/assets/vendor/ui5-webcomponents-2.27.2/ui5-fonts.css">
    <link rel="stylesheet" href="<?= e($basePath ?? '') ?>/assets/css/app.css">
</head>
<body class="lt-guest">
<main class="lt-main">
    <section class="lt-login lt-card">
        <h1><?= e(__('errors.title', ['status' => $status])) ?></h1>
        <p><?= e($text !== $key ? $text : $message) ?></p>
        <p><a href="<?= e($basePath ?? '') ?>/"><?= e(__('errors.back_home')) ?></a></p>
    </section>
</main>
<script type="module" src="<?= e($basePath ?? '') ?>/assets/vendor/ui5-webcomponents-2.27.2/ui5.js"></script>
</body>
</html>
