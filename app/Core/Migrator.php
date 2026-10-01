<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Runs database/migrations/NNN_description.sql files in order and records
 * them in the "migrations" table. MariaDB auto-commits DDL, so a failing file
 * stops the run and must be fixed by hand.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^\d{3,}_[a-z0-9_]+\.sql$/';

    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = [];
        foreach (scandir($this->directory) ?: [] as $file) {
            if (preg_match(self::FILE_PATTERN, $file)) {
                $files[] = $file;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();
        return array_map('strval', $this->pdo->query('SELECT filename FROM migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /**
     * @param (callable(string): void)|null $onApplied
     * @return list<string> applied files
     */
    public function migrate(?callable $onApplied = null): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $record = $this->pdo->prepare('INSERT INTO migrations (filename, batch) VALUES (:filename, :batch)');

        foreach ($pending as $file) {
            try {
                foreach (self::splitStatements((string) file_get_contents($this->directory . '/' . $file)) as $sql) {
                    $this->pdo->exec($sql);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Migration {$file} failed: " . $e->getMessage(), 0, $e);
            }
            $record->execute(['filename' => $file, 'batch' => $batch]);
            if ($onApplied !== null) {
                $onApplied($file);
            }
        }
        return $pending;
    }

    /**
     * Statements end with ";" at end of line. "--" comment lines are dropped.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $statements = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $statements), static fn (string $s): bool => $s !== ''));
    }
}
