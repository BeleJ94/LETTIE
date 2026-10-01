-- One counter per site, direction and registration year (ENT-2026-00001, SOR-2026-00001).
CREATE TABLE mail_sequences (
    site_id INT UNSIGNED NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL,
    PRIMARY KEY (site_id, direction, year),
    CONSTRAINT fk_mail_sequences_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
