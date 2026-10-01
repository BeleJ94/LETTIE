<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\FileStorage;
use App\Domain\Audit\Actor;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\Priority;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Domain\SiteScope;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\ServiceFactory;
use Tests\Support\TestDatabase;

final class AttachmentServiceTest extends TestCase
{
    /** 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private PDO $pdo;
    private ServiceFactory $factory;
    private FileStorage $storage;
    private string $root;
    private Actor $actor;
    private Mail $mail;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $this->pdo = TestDatabase::reset();
        $this->factory = new ServiceFactory($this->pdo, new FrozenClock('2026-09-30 10:00:00'));
        $this->root = sys_get_temp_dir() . '/lettie_att_' . bin2hex(random_bytes(4));
        $this->storage = new FileStorage($this->root, uploadsOnly: false);

        $site = TestDatabase::insertSite('A');
        $correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        $this->actor = $this->factory->actor(TestDatabase::insertUser($site, 'sa@example.org', 'password-123456', 'secretariat'));
        $this->mail = $this->factory->mail(SiteScope::forUser($this->actor->user))->create($this->actor, Direction::Incoming, new MailInput(
            'Objet', null, $correspondent, null, Channel::Postal, Priority::Normal, Confidentiality::Internal,
            null, new DateTimeImmutable('2026-09-30 08:00:00', new \DateTimeZone('UTC')), null, null, null,
        ));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int, type: string} */
    private static function upload(string $content, string $name, string $declaredType = 'application/pdf'): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => $declaredType];
    }

    private function service(int $maxBytes = 1024 * 1024)
    {
        return $this->factory->attachment(SiteScope::forUser($this->actor->user), $this->storage, $maxBytes);
    }

    private static function violation(callable $fn): string
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return $e->violations()['file'][0][0];
        }
        self::fail('Expected RuleViolation');
    }

    public function testStoresPdfOutsidePublicWithHashAndAudit(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $attachment = $this->service()->upload($this->actor, $this->mail->id, self::upload($pdf, '../Courrier été.pdf'));

        self::assertSame('Courrier été.pdf', $attachment->originalName);
        self::assertSame('application/pdf', $attachment->mimeType);
        self::assertSame(hash('sha256', $pdf), $attachment->sha256);
        self::assertMatchesRegularExpression('#^\d+/2026/09/[a-f0-9]{32}\.pdf$#', $attachment->storedPath);
        self::assertSame($pdf, file_get_contents($this->storage->path($attachment->storedPath)));

        [$found, $path] = $this->service()->forDownload($attachment->id);
        self::assertSame($attachment->id, $found->id);
        self::assertFileExists($path);

        $log = $this->pdo->query("SELECT * FROM activity_log WHERE action = 'attachment_added'")->fetch();
        self::assertSame($this->mail->id, (int) $log['entity_id']);
        self::assertSame('Courrier été.pdf', json_decode($log['new_values'], true)['original_name']);
    }

    public function testAcceptsRealImage(): void
    {
        $attachment = $this->service()->upload($this->actor, $this->mail->id, self::upload(base64_decode(self::PNG), 'scan.png', 'image/png'));
        self::assertSame('image/png', $attachment->mimeType);
        self::assertStringEndsWith('.png', $attachment->storedPath);
    }

    public function testRejectsDisguisedFilesUsingContentNotNameOrBrowserType(): void
    {
        $html = self::upload('<html><script>alert(1)</script></html>', 'facture.pdf', 'application/pdf');
        self::assertSame('rules.attachment.type', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, $html)));

        $php = self::upload("<?php echo 'x';", 'image.png', 'image/png');
        self::assertSame('rules.attachment.type', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, $php)));

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn());
        self::assertDirectoryDoesNotExist($this->root);
    }

    public function testRejectsOversizedEmptyAndFailedUploads(): void
    {
        $big = self::upload('%PDF-1.4' . str_repeat('x', 2048), 'big.pdf');
        self::assertSame('rules.attachment.too_large', self::violation(fn () => $this->service(1024)->upload($this->actor, $this->mail->id, $big)));

        self::assertSame('rules.attachment.empty', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, self::upload('', 'e.pdf'))));
        self::assertSame('rules.attachment.required', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, null)));
        self::assertSame('rules.attachment.too_large', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, ['error' => UPLOAD_ERR_INI_SIZE])));
        self::assertSame('rules.attachment.failed', self::violation(fn () => $this->service()->upload($this->actor, $this->mail->id, ['error' => UPLOAD_ERR_PARTIAL])));
    }

    public function testOtherSiteCannotUploadOrDownload(): void
    {
        $pdf = "%PDF-1.4\n%%EOF\n";
        $attachment = $this->service()->upload($this->actor, $this->mail->id, self::upload($pdf, 'a.pdf'));

        $otherSite = TestDatabase::insertSite('B');
        $other = $this->factory->actor(TestDatabase::insertUser($otherSite, 'b@example.org', 'password-123456', 'secretariat'));
        $otherService = $this->factory->attachment(SiteScope::forUser($other->user), $this->storage);

        try {
            $otherService->forDownload($attachment->id);
            self::fail('Download across sites must fail');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFoundException::class);
        $otherService->upload($other, $this->mail->id, self::upload($pdf, 'b.pdf'));
    }

    public function testCorrespondentChangesAreAudited(): void
    {
        $service = $this->factory->correspondent(SiteScope::forUser($this->actor->user));
        $input = static fn (string $city): CorrespondentInput => new CorrespondentInput(
            CorrespondentType::Organization, 'Conseil régional', null, 'contact@region.example', null,
            null, null, null, $city, 'FR', null,
        );
        $created = $service->create($this->actor, $input('Lyon'));
        $service->update($this->actor, $created->id, $input('Grenoble'));

        $rows = $this->pdo->query("SELECT action, old_values, new_values FROM activity_log WHERE entity_type = 'correspondent' ORDER BY id")->fetchAll();
        self::assertSame(['create', 'update'], array_column($rows, 'action'));
        self::assertSame(['city' => 'Lyon'], json_decode($rows[1]['old_values'], true));
        self::assertSame(['city' => 'Grenoble'], json_decode($rows[1]['new_values'], true));
    }
}
