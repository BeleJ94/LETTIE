-- A purged attachment keeps its row (name, size, SHA-256) as proof; only the file is deleted.
ALTER TABLE attachments
    ADD COLUMN purged_at DATETIME NULL AFTER created_at,
    ADD COLUMN purged_by_rule_id INT UNSIGNED NULL AFTER purged_at,
    ADD CONSTRAINT fk_attachments_purge_rule FOREIGN KEY (purged_by_rule_id) REFERENCES retention_rules (id);
