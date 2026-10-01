-- Files live in STORAGE_PATH (outside public/); stored_path is relative to it.
CREATE TABLE attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mail_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_stored_path (stored_path),
    KEY idx_attachments_mail (mail_id),
    CONSTRAINT fk_attachments_mail FOREIGN KEY (mail_id) REFERENCES mails (id),
    CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
