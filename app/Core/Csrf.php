<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection. The token is read from the "_csrf"
 * form field or the "X-CSRF-Token" header (jQuery AJAX).
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        return is_string($expected) && $expected !== ''
            && is_string($submitted) && hash_equals($expected, $submitted);
    }

    /** @throws HttpException 419 when an unsafe request has no valid token */
    public function verify(Request $request): void
    {
        if ($request->isSafe()) {
            return;
        }
        $submitted = $request->post(self::FIELD) ?? $request->header(self::HEADER);
        if (!$this->isValid(is_string($submitted) ? $submitted : null)) {
            throw HttpException::csrfMismatch();
        }
    }

    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);
        return $this->token();
    }

    public function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e($this->token()) . '">';
    }
}
