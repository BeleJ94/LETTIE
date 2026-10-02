<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** docs/FIORI_DESIGN.md "Formulaires": wizard for the incoming mail, simple UI5 forms elsewhere. */
final class FormsHttpTest extends FunctionalTestCase
{
    private int $correspondent;
    private int $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        $this->agent = TestDatabase::insertUser($site, 'agent@example.org', 'password-123456', 'agent');
        $this->actingAs($this->user(TestDatabase::insertUser($site, 'sec@example.org', 'password-123456', 'secretariat')));
    }

    /** @return array<string, string> */
    private function mail(array $over = []): array
    {
        return array_merge([
            'direction' => 'incoming', 'subject' => 'Objet', 'correspondent_id' => (string) $this->correspondent,
            'received_at' => '2026-09-01T09:00', 'channel' => 'postal', 'priority' => 'normal', 'confidentiality' => 'internal',
        ], $over);
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int, type: string} */
    private static function file(string $content, string $name): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'lt');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => 'application/pdf'];
    }

    /** @return list<array{0: string, 1: bool, 2: bool}> step id, selected, disabled */
    private static function steps(string $html): array
    {
        preg_match_all('/<ui5-wizard-step title-text="[^"]*" data-step="([a-z]+)"\s*([^>]*)>/', $html, $m, PREG_SET_ORDER);
        return array_map(static fn (array $s): array => [$s[1], str_contains($s[2], 'selected'), str_contains($s[2], 'disabled')], $m);
    }

    public function testIncomingMailIsRegisteredWithAWizardAndOutgoingWithASimpleForm(): void
    {
        $incoming = $this->get('/mails/new', ['direction' => 'incoming'])->body();
        self::assertStringContainsString('@floorplan Wizard', (string) file_get_contents(dirname(__DIR__, 2) . '/views/mails/wizard.php'));
        self::assertSame([
            ['identification', true, false],
            ['correspondent', false, true],
            ['processing', false, true],
            ['attachment', false, true],
            ['review', false, true],
        ], self::steps($incoming), 'first step open, the others locked until the previous one is valid');
        self::assertStringContainsString('enctype="multipart/form-data"', $incoming);
        self::assertStringContainsString('data-lt-wizard-next', $incoming);
        self::assertMatchesRegularExpression('#data-lt-submit="mail-form" hidden#', $incoming, 'Save only on the review step');
        self::assertStringContainsString('data-lt-summary="subject"', $incoming);
        self::assertMatchesRegularExpression('#<ui5-label slot="labelContent" for="subject" required show-colon>#', $incoming, 'required fields are marked on their label');

        $outgoing = $this->get('/mails/new', ['direction' => 'outgoing'])->body();
        self::assertStringNotContainsString('<ui5-wizard', $outgoing);
        self::assertStringContainsString('<ui5-form ', $outgoing);
    }

    public function testServerErrorsReopenTheWizardOnTheFirstStepInError(): void
    {
        $response = $this->post('/mails', $this->mail(['correspondent_id' => '', 'priority' => 'nope']));
        self::assertSame(422, $response->status());
        self::assertSame([
            ['identification', false, false],
            ['correspondent', true, false],
            ['processing', false, false],
            ['attachment', false, false],
            ['review', false, false],
        ], self::steps($response->body()), 'every step reachable, the first one in error shown');
        self::assertMatchesRegularExpression('#<ui5-input id="correspondent_id"[^>]*value-state="Negative"#', $response->body());
        self::assertStringContainsString('data-lt-focus="correspondent_id"', $response->body());
        self::assertStringContainsString('data-lt-focus="priority"', $response->body());
        self::assertMatchesRegularExpression('#<ui5-input id="subject"[^>]*value="Objet"#', $response->body(), 'entered values are kept');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn());
    }

    public function testTheScanChosenInTheWizardIsAttachedToTheNewMail(): void
    {
        $created = $this->post('/mails', $this->mail(), files: ['file' => self::file("%PDF-1.4\n%%EOF", 'scan.pdf')]);
        self::assertSame(302, $created->status(), $created->body());
        $id = self::idFromLocation($created);
        self::assertSame(['scan.pdf'], $this->pdo->query("SELECT original_name FROM attachments WHERE mail_id = {$id}")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertNull($this->flash('flash.error'));
    }

    public function testARefusedScanDoesNotCancelTheRegistration(): void
    {
        $created = $this->post('/mails', $this->mail(), files: ['file' => self::file('<html>not a pdf</html>', 'fake.pdf')]);
        self::assertSame(302, $created->status());
        $id = self::idFromLocation($created);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn(), 'the mail is registered');
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM attachments WHERE mail_id = {$id}")->fetchColumn());
        self::assertStringContainsString('la pièce jointe a été refusée', (string) $this->flash('flash.error'));

        // No file at all is the normal case: no message.
        $plain = $this->post('/mails', $this->mail(), files: ['file' => ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => '']]);
        self::assertSame(302, $plain->status());
        self::assertNull($this->flash('flash.error'));
    }

    public function testSimpleFormsUseUi5FieldsWithInlineErrors(): void
    {
        $page = $this->get('/delegations')->body();
        self::assertStringContainsString('<ui5-form ', $page);
        self::assertMatchesRegularExpression('#<ui5-label slot="labelContent" for="delegate_id" required show-colon>#', $page);
        self::assertMatchesRegularExpression('#<ui5-combobox id="delegate_id" name="delegate_id" required#', $page, 'value help on a long list');
        self::assertStringContainsString('<ui5-cb-item text="', $page);
        self::assertStringContainsString('data-lt-validate', $page);

        $invalid = $this->post('/delegations', ['delegate_id' => '', 'starts_on' => '2026-10-01', 'ends_on' => 'nope']);
        self::assertSame(422, $invalid->status());
        self::assertMatchesRegularExpression('#<ui5-combobox id="delegate_id"[^>]*value-state="Negative"#', $invalid->body());
        self::assertMatchesRegularExpression('#<ui5-date-picker id="ends_on"[^>]*value-state="Negative"#', $invalid->body());
        self::assertStringContainsString('<div slot="valueStateMessage">Le champ Délégué est obligatoire.</div>', $invalid->body(), 'the message sits in the field');
        self::assertMatchesRegularExpression('#<ui5-date-picker id="starts_on"[^>]*value="2026-10-01"#', $invalid->body());

        $created = $this->post('/delegations', ['delegate_id' => (string) $this->agent, 'starts_on' => date('Y-m-d'), 'ends_on' => date('Y-m-d', strtotime('+3 days'))]);
        self::assertSame(302, $created->status(), $created->body());
    }
}
