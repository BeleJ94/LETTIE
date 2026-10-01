<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Core\Container;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Role;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;
use App\Repositories\UserRepository;
use App\Services\CorrespondentService;
use App\Services\UserService;
use PDO;

/** Sites, departments, users and correspondents of the demo, with lookups for the generators. */
final class Organisation
{
    /** @var array<string, int> site code => id */
    public array $sites = [];
    /** @var array<string, array<string, int>> site code => department code => id */
    public array $departments = [];
    /** @var array<string, array<string, float>> site code => department code => weight */
    public array $departmentWeights = [];
    /** @var array<string, int> profile key => user id */
    public array $users = [];
    /** @var array<string, array{role: string, site: string, department: ?string, name: string}> */
    public array $userInfo = [];
    /** @var array<string, list<int>> site code => correspondent ids, most frequent first */
    public array $correspondents = [];
    /** @var array<string, Actor> */
    private array $actors = [];

    private function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $profile */
    public static function create(Container $container, PDO $pdo, DemoClock $clock, array $profile): self
    {
        $org = new self($pdo);

        foreach ($profile['organisation'] as $code => $site) {
            $pdo->prepare('INSERT INTO sites (code, name, address) VALUES (?, ?, ?)')->execute([$code, $site['name'], $site['address']]);
            $org->sites[$code] = (int) $pdo->lastInsertId();
            foreach ($site['departments'] as $deptCode => [$name, $weight]) {
                $pdo->prepare('INSERT INTO departments (site_id, code, name) VALUES (?, ?, ?)')->execute([$org->sites[$code], $deptCode, $name]);
                $org->departments[$code][$deptCode] = (int) $pdo->lastInsertId();
                $org->departmentWeights[$code][$deptCode] = $weight;
            }
        }

        $users = $container->get(UserService::class);
        foreach ($profile['users'] as $key => [$login, $role, $first, $last, $site, $dept]) {
            $id = $users->create($site, null, Role::from($role), "{$login}@{$profile['email_domain']}", $profile['password'], $first, $last);
            if ($dept !== null) {
                $pdo->prepare('UPDATE users SET department_id = ? WHERE id = ?')->execute([$org->departments[$site][$dept], $id]);
            }
            $org->users[$key] = $id;
            $org->userInfo[$key] = ['role' => $role, 'site' => $site, 'department' => $dept, 'name' => "{$first} {$last}"];
        }

        $correspondents = $container->get(CorrespondentService::class);
        foreach ($profile['correspondents'] as [$name, $type, $organisation, $address, $postal, $city, $site]) {
            $created = $correspondents->create($org->actor('admin'), new CorrespondentInput(
                CorrespondentType::from($type), $name, $organisation, null, null, $address, null, $postal, $city, 'FR', null,
            ), $org->sites[$site]);
            $org->correspondents[$site][] = $created->id;
        }
        return $org;
    }

    public function actor(string $key): Actor
    {
        return $this->actors[$key] ??= new Actor(
            (new UserRepository($this->pdo, \App\Domain\SiteScope::system()))->findById($this->users[$key]) ?? throw new \RuntimeException("No user {$key}"),
            '192.0.2.10',
            'Lettie demo generator',
        );
    }

    /** First secretariat user of a site (registers the mail). */
    public function secretariatOf(string $site): string
    {
        foreach ($this->userInfo as $key => $info) {
            if ($info['site'] === $site && $info['role'] === 'secretariat') {
                return $key;
            }
        }
        throw new \RuntimeException("No secretariat on site {$site}");
    }

    /** @return list<string> profile keys of the people who handle mail in a department */
    public function handlersOf(string $site, string $department): array
    {
        $keys = [];
        foreach ($this->userInfo as $key => $info) {
            if ($info['site'] === $site && $info['department'] === $department && in_array($info['role'], ['agent', 'head_of_department'], true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** @return list<string> */
    public function agentsOf(string $site): array
    {
        return array_keys(array_filter($this->userInfo, static fn (array $i): bool => $i['site'] === $site && $i['role'] === 'agent'));
    }
}
