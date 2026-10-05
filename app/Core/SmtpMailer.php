<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal SMTP client (no dependency): one plain-text UTF-8 message per connection,
 * STARTTLS or implicit TLS, AUTH LOGIN. Enough for transactional messages.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param string $encryption "tls" (STARTTLS, port 587), "ssl" (implicit TLS, port 465) or "none"
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly int $timeout = 10,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            trim((string) $env->get('MAIL_HOST', '')),
            $env->int('MAIL_PORT', 587),
            strtolower(trim((string) $env->get('MAIL_ENCRYPTION', 'tls'))),
            (string) $env->get('MAIL_USERNAME', ''),
            (string) $env->get('MAIL_PASSWORD', ''),
            trim((string) $env->get('MAIL_FROM', '')),
            trim((string) $env->get('MAIL_FROM_NAME', 'Lettie')),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && filter_var($this->from, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (!$this->isConfigured()) {
            throw new MailException('No mail server configured.');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $to . $subject) === 1) {
            throw new MailException('Invalid recipient or subject.');
        }

        try {
            $this->connect();
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235);
            }
            $this->command('MAIL FROM:<' . $this->from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250, 251);
            $this->command('DATA', 354);
            $this->command(self::message($this->from, $this->fromName, $to, $subject, $text) . "\r\n.", 250);
            $this->write('QUIT');
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /** The message as sent after DATA: headers, then the base64 body (no line can start with a dot). */
    public static function message(string $from, string $fromName, string $to, string $subject, string $text): string
    {
        $name = str_replace(["\r", "\n", '"'], '', $fromName);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . ($name !== '' ? self::encodeHeader($name) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($from, '@'), 1) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $text)), 76, "\r\n"));
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1 ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function connect(): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($remote, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new MailException("Cannot reach the mail server: {$error}");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->expect(220);

        $hello = 'EHLO ' . (gethostname() ?: 'localhost');
        $this->command($hello, 250);
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', 220);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new MailException('The mail server refused the encrypted connection.');
            }
            $this->command($hello, 250);
        }
    }

    private function command(string $line, int ...$expected): void
    {
        $this->write($line);
        $this->expect(...$expected);
    }

    private function write(string $line): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailException('Connection to the mail server lost.');
        }
    }

    private function expect(int ...$codes): void
    {
        $reply = '';
        // A reply may span several lines: "250-..." continues, "250 ..." ends.
        while (is_resource($this->socket) && ($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (!in_array((int) substr($reply, 0, 3), $codes, true)) {
            // Never the credentials: only the server's answer.
            throw new MailException('Unexpected answer from the mail server: ' . trim(mb_substr($reply, 0, 200)));
        }
    }
}
