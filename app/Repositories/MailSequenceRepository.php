<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Mail\Direction;
use LogicException;

final class MailSequenceRepository extends Repository
{
    /**
     * Reserves the next number for (site, direction, year).
     *
     * Atomic: the row lock taken by INSERT … ON DUPLICATE KEY UPDATE is held
     * until the surrounding transaction ends, so concurrent registrations
     * wait, and a rollback gives the number back (no gaps, no duplicates).
     */
    public function next(int $siteId, Direction $direction, int $year): int
    {
        $this->assertInScope($siteId);
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Mail numbers must be reserved inside a transaction.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_sequences (site_id, direction, year, last_number)
             VALUES (:site_id, :direction, :year, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $stmt->execute(['site_id' => $siteId, 'direction' => $direction->value, 'year' => $year]);

        return (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
}
