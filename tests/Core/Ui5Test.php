<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Translator;
use App\Core\Ui5;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use PHPUnit\Framework\TestCase;

final class Ui5Test extends TestCase
{
    public function testEveryMailStatusAndPriorityHasASemanticState(): void
    {
        foreach (MailStatus::cases() as $status) {
            self::assertArrayHasKey($status->value, Ui5::TAG_DESIGNS['status']);
        }
        foreach (Priority::cases() as $priority) {
            self::assertArrayHasKey($priority->value, Ui5::TAG_DESIGNS['priority']);
        }
    }

    /** The lists (JS) and the pages (PHP) must show the same state for the same value. */
    public function testTagDesignsMatchTheJavaScriptTable(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/lt-listreport.js');
        foreach (Ui5::TAG_DESIGNS as $enum => $designs) {
            foreach ($designs as $value => $design) {
                self::assertMatchesRegularExpression(
                    '/\b' . preg_quote($value, '/') . ":\s*'" . $design . "'/",
                    $js,
                    "{$enum}.{$value} should be {$design} in lt-listreport.js"
                );
            }
        }
    }

    public function testTagEscapesItsLabelAndCarriesTheDesign(): void
    {
        self::assertSame('<ui5-tag design="Critical">&lt;b&gt;x&lt;/b&gt;</ui5-tag>', Ui5::tag('status', 'awaiting_reply', '<b>x</b>'));
        self::assertSame('<ui5-tag design="Set2" color-scheme="6" hide-state-icon>In</ui5-tag>', Ui5::tag('direction', 'incoming', 'In'));
        self::assertSame('<ui5-tag design="Neutral" hide-state-icon>?</ui5-tag>', Ui5::tag('status', 'unknown', '?'));
    }

    public function testFieldStateAndMessage(): void
    {
        $errors = ['subject' => ['Obligatoire.', 'Trop <long>.']];
        self::assertSame(' value-state="Negative"', Ui5::state($errors, 'subject'));
        self::assertSame('', Ui5::state($errors, 'summary'));
        self::assertSame('<div slot="valueStateMessage">Obligatoire. Trop &lt;long&gt;.</div>', Ui5::stateMessage($errors, 'subject'));
        self::assertSame('', Ui5::stateMessage($errors, 'summary'));
    }

    public function testOptionsSelectTheCurrentValueAndEscape(): void
    {
        self::assertSame(
            '<ui5-option value="">—</ui5-option><ui5-option value="3" selected>R&amp;D</ui5-option>',
            Ui5::options(['' => '—', 3 => 'R&D'], '3'),
        );
    }

    public function testFileSizeFollowsTheLocale(): void
    {
        self::assertSame('512 o', Ui5::fileSize(512, 'fr'));
        self::assertSame('1,5 Ko', Ui5::fileSize(1536, 'fr'));
        self::assertSame('1.5 KB', Ui5::fileSize(1536, 'en'));
        self::assertSame('2,0 Mo', Ui5::fileSize(2 * 1048576, 'fr'));
    }
}
