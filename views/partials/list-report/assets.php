<?php
/**
 * List Report: libraries and scripts of the screen (docs/FIORI_DESIGN.md §12).
 * DataTables is only loaded by the list screens.
 *
 * @var App\Core\View $this
 */
?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('styles') ?>
<link rel="stylesheet" href="<?= e($basePath) ?>/assets/vendor/datatables-2.1.8/dataTables.dataTables.min.css">
<?php $this->stop() ?>
<?php $this->start('libraries') ?>
<script src="<?= e($basePath) ?>/assets/vendor/datatables-2.1.8/dataTables.min.js"></script>
<script src="<?= e($basePath) ?>/assets/js/lt-tables.js"></script>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/lt-listreport.js"></script>
<script src="<?= e($basePath) ?>/assets/js/pages/list-report.js"></script>
<?php $this->stop() ?>
