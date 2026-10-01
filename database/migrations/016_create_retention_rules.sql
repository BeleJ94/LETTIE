-- Retention: after retention_months counted from closure, apply action.
-- site_id / direction NULL = applies to every site / both directions; the most specific rule wins.
CREATE TABLE retention_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    direction ENUM('incoming', 'outgoing') NULL,
    retention_months SMALLINT UNSIGNED NOT NULL,
    action ENUM('archive', 'purge_attachments', 'review') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_retention_rules_action (action, is_active),
    CONSTRAINT chk_retention_months CHECK (retention_months BETWEEN 1 AND 1200),
    CONSTRAINT fk_retention_rules_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_retention_rules_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
