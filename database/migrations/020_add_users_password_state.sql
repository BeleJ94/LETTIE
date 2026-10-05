-- Password life cycle: a password chosen by an administrator must be replaced at the next sign-in,
-- and every password change closes the sessions opened with the former one (session_version).
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
    ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password,
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_changed_at;
