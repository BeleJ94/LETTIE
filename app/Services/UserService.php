<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Transaction;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Role;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use DomainException;

final class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SiteRepository $sites,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * Creates a user; creates the site when $siteName is given and the code is unknown.
     *
     * @throws DomainException
     */
    public function create(
        string $siteCode,
        ?string $siteName,
        Role $role,
        string $email,
        string $password,
        string $firstName,
        string $lastName,
    ): int {
        PasswordPolicy::assertAcceptable($password);
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException("Invalid email: {$email}");
        }

        return $this->transaction->run(function () use ($siteCode, $siteName, $role, $email, $password, $firstName, $lastName): int {
            $siteId = $this->sites->findIdByCode($siteCode);
            if ($siteId === null) {
                if ($siteName === null || $siteName === '') {
                    throw new DomainException("Unknown site: {$siteCode}");
                }
                $siteId = $this->sites->create($siteCode, $siteName);
            }
            if ($this->users->findByEmail($email) !== null) {
                throw new DomainException("Email already used: {$email}");
            }
            return $this->users->create($siteId, null, $role, $email, AuthService::hashPassword($password), $firstName, $lastName);
        });
    }
}
