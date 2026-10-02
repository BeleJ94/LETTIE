<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** Overview Page (docs/FIORI_DESIGN.md §15): cards by role, semantic states, links to the applications. */
final class OverviewHttpTest extends FunctionalTestCase
{
    private int $site;
    private int $correspondent;
    private int $secretary;
    private int $head;
    private int $management;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($this->site, 'Mairie');
        $this->secretary = TestDatabase::insertUser($this->site, 'sec@example.org', 'password-123456', 'secretariat');
        $this->head = TestDatabase::insertUser($this->site, 'head@example.org', 'password-123456', 'head_of_department');
        $this->management = TestDatabase::insertUser($this->site, 'dir@example.org', 'password-123456', 'management');
    }

    private function mail(string $subject, ?string $dueDate): int
    {
        $this->actingAs($this->user($this->secretary));
        $created = $this->post('/mails', [
            'direction' => 'incoming', 'subject' => $subject, 'correspondent_id' => (string) $this->correspondent,
            'received_at' => date('Y-m-d', strtotime('-20 days')) . 'T09:00', 'channel' => 'postal', 'priority' => 'high',
            'confidentiality' => 'internal', 'due_date' => $dueDate ?? '',
        ]);
        self::assertSame(302, $created->status(), $created->body());
        return self::idFromLocation($created);
    }

    /** @return array<string, string> card key => state, for the KPI cards */
    private static function kpis(string $html): array
    {
        preg_match_all('/data-card="kpi" data-key="([a-z_]+)" data-state="([A-Za-z]+)"/', $html, $m);
        return array_combine($m[1], $m[2]);
    }

    public function testCardsShowTheStateOfTheIndicatorsAndLinkToTheApplications(): void
    {
        $late = $this->mail('En retard <b>', date('Y-m-d', strtotime('-5 days')));
        $this->mail('Dans les temps', date('Y-m-d', strtotime('+20 days')));

        $this->actingAs($this->user($this->head));
        $response = $this->get('/overview');
        self::assertSame(200, $response->status(), $response->body());
        $html = $response->body();

        self::assertStringContainsString('@floorplan OverviewPage', (string) file_get_contents(dirname(__DIR__, 2) . '/views/overview/index.php'));
        self::assertSame(
            ['overdue' => 'Negative', 'to_assign' => 'Critical', 'processing' => 'Neutral', 'late_rate' => 'Neutral'],
            self::kpis($html),
            'one overdue mail, two to assign, nothing closed yet',
        );
        // The state is written on the card, not only coloured.
        self::assertStringContainsString('design="Negative">À traiter</ui5-tag>', $html);
        self::assertStringContainsString('design="Critical">À surveiller</ui5-tag>', $html);
        self::assertStringContainsString('design="Neutral">Sans donnée</ui5-tag>', $html);

        // Every card opens its application.
        foreach (['/mails?overdue=1', '/mails?direction=incoming&amp;status=registered', '/statistics'] as $href) {
            self::assertStringContainsString('interactive data-lt-href="' . $href . '"', $html);
        }
        // List cards: first rows and the total; rows open the mail.
        self::assertMatchesRegularExpression('#data-card="list" data-key="overdue".*?additional-text="1 sur 1"#s', $html);
        self::assertMatchesRegularExpression('#data-card="list" data-key="to_assign".*?additional-text="2 sur 2"#s', $html);
        self::assertStringContainsString('data-lt-href="/mails/' . $late . '" description="En retard &lt;b&gt;"', $html, 'user input is escaped');
        // Chart cards: data handed to the script, and available as text.
        self::assertStringContainsString('data-lt-ov-chart="volumes"', $html);
        self::assertStringContainsString('data-lt-ov-chart="overdue"', $html);
        self::assertStringContainsString('Afficher les données', $html);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}\b|rgb\(/i', (string) preg_replace('/csrf[^>]+>/', '', $html), 'no hard-coded colour in the page');
    }

    public function testRolesWithoutAssignmentRightsDoNotSeeTheAssignmentCards(): void
    {
        $this->mail('À affecter', null);

        $this->actingAs($this->user($this->management));
        $html = $this->get('/overview')->body();
        self::assertSame(['overdue', 'processing', 'late_rate'], array_keys(self::kpis($html)));
        self::assertStringNotContainsString('data-key="to_assign"', $html);
        self::assertStringContainsString('data-kpis="3" data-lists="1"', $html, 'the grid adapts to the number of cards');
        self::assertStringContainsString('Aucun courrier en retard.', $html, 'empty list card');

        $this->actingAs($this->user($this->secretary));
        self::assertSame(403, $this->get('/overview')->status(), 'the overview needs reports.view');
    }

    public function testTheLaunchpadAndTheNavigationLinkToTheOverview(): void
    {
        $this->actingAs($this->user($this->head));
        $home = $this->get('/')->body();
        self::assertMatchesRegularExpression('#data-tile="overview".*?data-lt-href="/overview"#s', $home);
        self::assertMatchesRegularExpression('#<ui5-side-navigation-item text="Vue d&\#039;ensemble"[^>]*href="/overview"#', $home);

        $this->actingAs($this->user($this->secretary));
        $home = $this->get('/')->body();
        self::assertStringNotContainsString('data-tile="overview"', $home);
        self::assertStringNotContainsString('href="/overview"', $home);
    }
}
