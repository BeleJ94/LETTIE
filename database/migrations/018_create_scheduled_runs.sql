-- One row per execution of a scheduled task (bin/send-reminders.php), shown to administrators.
CREATE TABLE scheduled_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job VARCHAR(50) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    dry_run TINYINT(1) NOT NULL DEFAULT 0,
    summary JSON NULL,
    PRIMARY KEY (id),
    KEY idx_scheduled_runs_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
