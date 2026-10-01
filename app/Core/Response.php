<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use JsonException;

final class Response
{
    private const COOKIE_DEFAULTS = [
        'expires' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    /** @var array<string, array{value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @throws JsonException */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Streams a private file. $downloadName is sent RFC 6266-encoded
     * (ASCII fallback + UTF-8 filename*).
     */
    public static function file(string $path, string $mimeType, string $downloadName, bool $inline = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('File not readable.');
        }
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $downloadName) ?: 'download';
        $response = new self('', 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $inline ? 'inline' : 'attachment',
                $ascii,
                rawurlencode($downloadName),
            ),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->filePath = $path;
        return $response;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /**
     * Cookies default to Secure, HttpOnly and SameSite=Lax.
     *
     * @param array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $options
     */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $options = array_merge(self::COOKIE_DEFAULTS, $options);
        if (!in_array($options['samesite'], ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('SameSite must be Lax, Strict or None.');
        }
        if ($options['samesite'] === 'None' && $options['secure'] !== true) {
            throw new InvalidArgumentException('SameSite=None requires Secure.');
        }
        $clone = clone $this;
        $clone->cookies[$name] = ['value' => $value, 'options' => $options];
        return $clone;
    }

    public function withoutCookie(string $name, string $path = '/'): self
    {
        return $this->withCookie($name, '', ['expires' => time() - 3600, 'path' => $path]);
    }

    /** @return array<string, array{value: string, options: array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }
            foreach ($this->cookies as $name => $cookie) {
                setcookie($name, $cookie['value'], $cookie['options']);
            }
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
