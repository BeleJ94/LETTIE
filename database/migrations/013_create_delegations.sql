-- Absence: from starts_on to ends_on (inclusive), new assignments for delegator_id go to delegate_id.
CREATE TABLE delegations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    delegator_id INT UNSIGNED NOT NULL,
    delegate_id INT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_delegations_delegator (delegator_id, starts_on, ends_on),
    KEY idx_delegations_site (site_id, ends_on),
    CONSTRAINT chk_delegations_users CHECK (delegator_id <> delegate_id),
    CONSTRAINT chk_delegations_dates CHECK (ends_on >= starts_on),
    CONSTRAINT fk_delegations_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_delegations_delegator FOREIGN KEY (delegator_id) REFERENCES users (id),
    CONSTRAINT fk_delegations_delegate FOREIGN KEY (delegate_id) REFERENCES users (id),
    CONSTRAINT fk_delegations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_delegations_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
