-- Two-factor authentication with an authenticator application (TOTP, RFC 6238).
-- totp_last_counter is the period of the last accepted code: a code is never accepted twice.
ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(64) NULL AFTER session_version,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN totp_last_counter BIGINT UNSIGNED NULL AFTER totp_enabled_at;
