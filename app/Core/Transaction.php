<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use PDO;
use Throwable;

/** Used by Services only. Nested calls join the outer transaction. */
final class Transaction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
