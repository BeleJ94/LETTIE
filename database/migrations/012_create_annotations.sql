CREATE TABLE annotations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mail_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    -- Private: visible to its author only.
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_annotations_mail (mail_id, created_at),
    CONSTRAINT fk_annotations_mail FOREIGN KEY (mail_id) REFERENCES mails (id),
    CONSTRAINT fk_annotations_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
