<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Session wrapper with hardened cookie settings.
 * Session::inMemory() gives an array-backed session for CLI and tests.
 */
final class Session
{
    private const FLASH_KEY = '_flash';
    private const FLASH_OLD_KEY = '_flash_old';

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $started = false;

    public function __construct(
        private readonly string $name = 'lettie_session',
        private readonly bool $secure = true,
        private readonly int $lifetime = 7200,
        private readonly string $path = '/',
        private readonly bool $native = true,
        private readonly string $sameSite = 'Lax',
    ) {
        if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
            throw new \InvalidArgumentException('Session SameSite must be Lax or Strict.');
        }
    }

    /**
     * Session cookie: browser-session lifetime, HttpOnly, SameSite, Secure per SESSION_SECURE.
     *
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    public function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ];
    }

    /**
     * Array-backed session (CLI, tests). Passing the data of a previous
     * session simulates the next HTTP request (flash messages move on).
     *
     * @param array<string, mixed> $data
     */
    public static function inMemory(array $data = []): self
    {
        $session = new self(native: false);
        $session->data = $data;
        $session->start();
        return $session;
    }

    /** @return array<string, mixed> raw data (in-memory sessions carried between simulated requests) */
    public function all(): array
    {
        return $this->data;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->native) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                throw new RuntimeException('A session is already active.');
            }
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string) $this->lifetime);
            session_name($this->name);
            ini_set('session.cookie_secure', $this->secure ? '1' : '0');
            session_set_cookie_params($this->cookieParams());
            session_start();
            $this->data = &$_SESSION;
        }

        $this->started = true;
        $this->ageFlash();
        $this->enforceIdleTimeout();
    }

    private function enforceIdleTimeout(): void
    {
        $now = time();
        $last = $this->data['_last_activity'] ?? null;
        if (is_int($last) && $now - $last > $this->lifetime) {
            $this->clear();
            $this->regenerate();
        }
        $this->data['_last_activity'] = $now;
    }

    private function ageFlash(): void
    {
        $this->data[self::FLASH_OLD_KEY] = $this->data[self::FLASH_KEY] ?? [];
        $this->data[self::FLASH_KEY] = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Value available on the next request only. */
    public function flash(string $key, mixed $value): void
    {
        $this->data[self::FLASH_KEY][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->data[self::FLASH_OLD_KEY][$key] ?? $default;
    }

    /** Call after login / privilege change to prevent session fixation. */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        foreach (array_keys($this->data) as $key) {
            unset($this->data[$key]);
        }
    }

    public function destroy(): void
    {
        $this->clear();
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie($this->name, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
        $this->started = false;
    }
}
