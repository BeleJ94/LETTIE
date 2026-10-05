<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Env;
use App\Core\Kernel;
use App\Core\MailException;
use App\Core\Mailer;
use App\Core\Session;
use App\Core\SmtpMailer;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** "Mot de passe oublié" through the Kernel, with a mail server that only records what it is given. */
final class PasswordResetHttpTest extends FunctionalTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $userId;
    private bool $configured = true;
    private bool $failing = false;
    /** @var list<array{to: string, subject: string, text: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $this->userId = TestDatabase::insertUser($site, 'ana@example.org', self::PASSWORD, 'agent');
        TestDatabase::insertUser($site, 'gone@example.org', self::PASSWORD, 'agent', active: false);
    }

    protected function kernel(): Kernel
    {
        $kernel = parent::kernel();
        $test = $this;
        $kernel->container()->instance(Mailer::class, new class ($test) implements Mailer {
            public function __construct(private readonly PasswordResetHttpTest $test)
            {
            }

            public function isConfigured(): bool
            {
                return $this->test->mailerConfigured();
            }

            public function send(string $to, string $subject, string $text): void
            {
                $this->test->record($to, $subject, $text);
            }
        });
        $kernel->container()->instance(Env::class, new Env([
            'APP_TIMEZONE' => 'Europe/Paris', 'APP_LOCALE' => 'fr', 'DB_NAME' => 'unused', 'SESSION_SECURE' => 'false', 'APP_URL' => 'https://lettie.example.org/',
        ]));
        return $kernel;
    }

    public function mailerConfigured(): bool
    {
        return $this->configured;
    }

    public function record(string $to, string $subject, string $text): void
    {
        if ($this->failing) {
            throw new MailException('server down');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
    }

    /** Token of the link in the last e-mail. */
    private function lastToken(): string
    {
        preg_match('#https://lettie\.example\.org/password/reset/([a-f0-9]{64})#', $this->sent[array_key_last($this->sent)]['text'], $m);
        self::assertNotEmpty($m, 'the e-mail holds an absolute link');
        return $m[1];
    }

    public function testTheFeatureHidesWithoutAMailServer(): void
    {
        $this->configured = false;
        self::assertStringNotContainsString('/password/forgot', $this->get('/login')->body());
        self::assertSame(404, $this->get('/password/forgot')->status());
        self::assertSame(404, $this->post('/password/forgot', ['email' => 'ana@example.org'])->status());

        $this->configured = true;
        self::assertStringContainsString('href="/password/forgot"', $this->get('/login')->body());
        self::assertSame(200, $this->get('/password/forgot')->status());
    }

    public function testTheAnswerIsTheSameForKnownAndUnknownAddresses(): void
    {
        foreach (['nobody@example.org', 'gone@example.org', 'ANA@example.org '] as $email) {
            self::assertSame('/password/forgot', $this->post('/password/forgot', ['email' => $email])->header('Location'));
            self::assertStringContainsString('Si un compte correspond', $this->get('/password/forgot')->body());
        }
        self::assertCount(1, $this->sent, 'only the active account receives a link');
        self::assertSame('ana@example.org', $this->sent[0]['to']);
        self::assertStringContainsString('Lettie', $this->sent[0]['subject']);

        $stored = $this->pdo->query('SELECT token_hash FROM password_resets')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([hash('sha256', $this->lastToken())], $stored, 'only the hash of the token is stored');
    }

    public function testRequestsAreLimitedAndAMailFailureDoesNotShow(): void
    {
        for ($i = 0; $i < PasswordResetService::MAX_PER_HOUR + 2; $i++) {
            $this->post('/password/forgot', ['email' => 'ana@example.org']);
        }
        self::assertCount(PasswordResetService::MAX_PER_HOUR, $this->sent);

        $this->pdo->exec('DELETE FROM password_resets');
        $this->failing = true;
        $log = (string) ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            self::assertSame('/password/forgot', $this->post('/password/forgot', ['email' => 'ana@example.org'])->header('Location'));
        } finally {
            ini_set('error_log', $log);
        }
    }

    public function testTheLinkSetsANewPasswordOnce(): void
    {
        // An open session and a locked account: both are cleared by the recovery.
        $this->post('/login', ['email' => 'ana@example.org', 'password' => self::PASSWORD]);
        $openSession = $this->session->all();
        $this->session = Session::inMemory();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'ana@example.org', 'password' => 'wrong']);
        }

        $this->post('/password/forgot', ['email' => 'ana@example.org']);
        $token = $this->lastToken();
        self::assertStringContainsString('name="new_password"', $this->get("/password/reset/{$token}")->body());
        self::assertSame(422, $this->get('/password/reset/' . str_repeat('a', 64))->status(), 'unknown link');

        $mismatch = $this->post("/password/reset/{$token}", ['new_password' => 'un autre mot de passe', 'new_password_confirmation' => 'different']);
        self::assertSame(422, $mismatch->status());
        self::assertSame(422, $this->post("/password/reset/{$token}", ['new_password' => 'court', 'new_password_confirmation' => 'court'])->status());

        $done = $this->post("/password/reset/{$token}", ['new_password' => 'un autre mot de passe', 'new_password_confirmation' => 'un autre mot de passe']);
        self::assertSame('/login', $done->header('Location'));
        self::assertStringContainsString('Mot de passe changé', $this->get('/login')->body());
        self::assertTrue(password_verify('un autre mot de passe', (string) $this->pdo->query("SELECT password_hash FROM users WHERE id = {$this->userId}")->fetchColumn()));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'user' AND action = 'password_recovered' AND user_id = {$this->userId}")->fetchColumn());

        self::assertSame(422, $this->get("/password/reset/{$token}")->status(), 'a link is used once');
        self::assertSame(422, $this->post("/password/reset/{$token}", ['new_password' => 'encore un mot de passe', 'new_password_confirmation' => 'encore un mot de passe'])->status());

        self::assertSame('/', $this->post('/login', ['email' => 'ana@example.org', 'password' => 'un autre mot de passe'])->header('Location'), 'the lock is lifted');
        self::assertSame($this->userId, $this->session->get(AuthService::SESSION_KEY));
        $this->session = Session::inMemory($openSession);
        self::assertSame('/login', $this->get('/mails')->header('Location'), 'sessions opened with the former password are closed');
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $this->post('/password/forgot', ['email' => 'ana@example.org']);
        $token = $this->lastToken();
        $this->pdo->exec("UPDATE password_resets SET expires_at = '2020-01-01 00:00:00'");
        self::assertSame(422, $this->get("/password/reset/{$token}")->status());
        self::assertStringContainsString('plus valable', $this->get("/password/reset/{$token}")->body());
    }

    public function testSmtpMessageIsEncodedAndRefusesHeaderInjection(): void
    {
        $message = SmtpMailer::message('no-reply@example.org', 'Lettie — courrier', 'ana@example.org', 'Sujet accentué é', "Ligne 1\n.ligne commençant par un point\nFin");
        [$headers, $body] = explode("\r\n\r\n", $message, 2);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Sujet accentué é') . '?=', $headers);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $headers);
        self::assertSame("Ligne 1\n.ligne commençant par un point\nFin", base64_decode($body));
        self::assertDoesNotMatchRegularExpression('/^\./m', $body, 'no line starts with a dot');

        $mailer = new SmtpMailer('smtp.example.org', 587, 'tls', '', '', 'no-reply@example.org', 'Lettie');
        self::assertTrue($mailer->isConfigured());
        self::assertFalse((new SmtpMailer('', 587, 'tls', '', '', 'no-reply@example.org', 'Lettie'))->isConfigured());
        $this->expectException(MailException::class);
        $mailer->send('ana@example.org', "Sujet\r\nBcc: evil@example.org", 'texte');
    }
}
