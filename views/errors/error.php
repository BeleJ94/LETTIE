<?php /** @var App\Core\View $this */ ?>
<?php $key = 'errors.' . $status; $text = __($key); ?>
<!doctype html>
<html lang="<?= e($locale ?? 'fr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('errors.title', ['status' => $status])) ?></title>
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
</body>
</html>
