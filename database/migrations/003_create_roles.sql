-- Codes must match App\Domain\Auth\Role; permissions are defined in code.
CREATE TABLE roles (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    name_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code, name_key) VALUES
    ('admin', 'roles.admin'),
    ('secretariat', 'roles.secretariat'),
    ('head_of_department', 'roles.head_of_department'),
    ('agent', 'roles.agent'),
    ('management', 'roles.management');
