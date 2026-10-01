-- reply_to: source = outgoing reply, target = incoming mail it answers.
CREATE TABLE mail_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_mail_id INT UNSIGNED NOT NULL,
    target_mail_id INT UNSIGNED NOT NULL,
    type ENUM('reply_to', 'related') NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mail_links (source_mail_id, target_mail_id, type),
    KEY idx_mail_links_target (target_mail_id),
    CONSTRAINT chk_mail_links_distinct CHECK (source_mail_id <> target_mail_id),
    CONSTRAINT fk_mail_links_source FOREIGN KEY (source_mail_id) REFERENCES mails (id),
    CONSTRAINT fk_mail_links_target FOREIGN KEY (target_mail_id) REFERENCES mails (id),
    CONSTRAINT fk_mail_links_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
