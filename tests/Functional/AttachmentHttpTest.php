<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** Upload through the form and download through the controller (headers, CSP, scope). */
final class AttachmentHttpTest extends FunctionalTestCase
{
    private int $mailId;
    private int $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $this->otherSite = TestDatabase::insertSite('B');
        $correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        $this->actingAs($this->user(TestDatabase::insertUser($site, 'sec@example.org', 'password-123456', 'secretariat')));
        $created = $this->post('/mails', [
            'direction' => 'incoming', 'subject' => 'Objet', 'correspondent_id' => (string) $correspondent,
            'received_at' => date('Y-m-d', strtotime('-1 day')) . 'T08:00', 'channel' => 'postal', 'priority' => 'normal', 'confidentiality' => 'internal',
        ]);
        $this->mailId = self::idFromLocation($created);
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int, type: string} */
    private static function file(string $content, string $name, string $type = 'application/pdf'): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => $type];
    }

    public function testUploadThenDownloadAndInlineView(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF\n";
        $upload = $this->post("/mails/{$this->mailId}/attachments", [], files: ['file' => self::file($pdf, 'Lettre "reçue".pdf')]);
        self::assertSame("/mails/{$this->mailId}#attachments", $upload->header('Location'));
        self::assertSame('Pièce jointe « Lettre "reçue".pdf » ajoutée.', $this->flash('flash.success'));

        $page = $this->get("/mails/{$this->mailId}")->body();
        self::assertStringContainsString('Lettre &quot;reçue&quot;.pdf', $page);
        $id = (int) $this->pdo->query('SELECT id FROM attachments')->fetchColumn();

        $download = $this->get("/attachments/{$id}");
        self::assertSame(200, $download->status());
        self::assertSame('application/pdf', $download->header('Content-Type'));
        self::assertSame('attachment; filename="Lettre _re_ue_.pdf"; filename*=UTF-8\'\'Lettre%20%22re%C3%A7ue%22.pdf', $download->header('Content-Disposition'));
        self::assertSame("default-src 'none'; img-src 'self'; object-src 'self'; frame-ancestors 'none'", $download->header('Content-Security-Policy'), 'file-specific CSP is kept');
        self::assertSame('nosniff', $download->header('X-Content-Type-Options'));
        self::assertSame('private, no-store', $download->header('Cache-Control'));
        self::assertSame($pdf, file_get_contents((string) $download->filePath()));

        self::assertStringStartsWith('inline;', (string) $this->get("/attachments/{$id}", ['inline' => '1'])->header('Content-Disposition'));
    }

    public function testRejectedFileShowsAnErrorAndStoresNothing(): void
    {
        $response = $this->post("/mails/{$this->mailId}/attachments", [], files: ['file' => self::file('<html>hi</html>', 'fake.pdf')]);
        self::assertSame(302, $response->status());
        self::assertSame('Type de fichier non autorisé : PDF ou image uniquement.', $this->flash('flash.error'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn());

        $json = $this->post("/mails/{$this->mailId}/attachments", [], files: ['file' => self::file('', 'empty.pdf')], json: true);
        self::assertSame(422, $json->status());
        self::assertSame(['file' => ['Le fichier est vide.']], json_decode($json->body(), true)['errors']);
    }

    public function testOtherSiteCannotReachTheFile(): void
    {
        $this->post("/mails/{$this->mailId}/attachments", [], files: ['file' => self::file("%PDF-1.4\n%%EOF\n", 'a.pdf')]);
        $id = (int) $this->pdo->query('SELECT id FROM attachments')->fetchColumn();

        $this->actingAs($this->user(TestDatabase::insertUser($this->otherSite, 'b@example.org', 'password-123456', 'secretariat')));
        self::assertSame(404, $this->get("/attachments/{$id}")->status());
        self::assertSame(404, $this->post("/mails/{$this->mailId}/attachments", [], files: ['file' => self::file("%PDF-1.4\n%%EOF\n", 'b.pdf')])->status());
    }
}
