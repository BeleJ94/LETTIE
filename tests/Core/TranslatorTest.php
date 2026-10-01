<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Translator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lettie_lang_' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/fr.php', "<?php return ['greet' => 'Bonjour :name', 'menu' => ['mails' => 'Courriers'], 'only_fr' => 'Seulement FR'];");
        file_put_contents(self::$dir . '/en.php', "<?php return ['greet' => 'Hello :name', 'menu' => ['mails' => 'Mails']];");
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$dir . '/*.php') ?: []);
        rmdir(self::$dir);
    }

    public function testTranslatesWithPlaceholdersAndNestedKeys(): void
    {
        $t = new Translator(self::$dir, 'en');
        self::assertSame('Hello Ana', $t->get('greet', ['name' => 'Ana']));
        self::assertSame('Mails', $t->get('menu.mails'));
    }

    public function testFallsBackToFallbackLocaleThenKey(): void
    {
        $t = new Translator(self::$dir, 'en', 'fr');
        self::assertSame('Seulement FR', $t->get('only_fr'));
        self::assertSame('missing.key', $t->get('missing.key'));
        self::assertFalse($t->has('only_fr'));
    }

    public function testSwitchLocale(): void
    {
        $t = new Translator(self::$dir, 'fr');
        self::assertSame(['en', 'fr'], $t->available());
        $t->setLocale('en');
        self::assertSame('en', $t->locale());
        self::assertSame('Mails', $t->get('menu.mails'));
    }

    public function testRejectsUnknownOrMaliciousLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Translator(self::$dir, '../etc');
    }

    public function testPlaceholdersAreReplacedAsWholeNames(): void
    {
        file_put_contents(self::$dir . '/fr.php', "<?php return ['greet' => 'Bonjour :name', 'menu' => ['mails' => 'Courriers'], 'only_fr' => 'Seulement FR', 'pages' => 'Page :page / :pages, :other'];");
        $t = new Translator(self::$dir, 'fr');
        self::assertSame('Page 2 / 7, :other', $t->get('pages', ['page' => 2, 'pages' => 7]));
    }

    public function testSectionMergesFallbackUnderCurrentLocale(): void
    {
        $t = new Translator(self::$dir, 'en', 'fr');
        self::assertSame(['mails' => 'Mails'], $t->section('menu'));
        self::assertSame([], $t->section('missing'));
    }

    public function testProjectJsSectionIsComplete(): void
    {
        $t = new Translator(dirname(__DIR__, 2) . '/lang', 'en');
        $js = $t->section('js');
        self::assertArrayHasKey('table', $js);
        self::assertArrayHasKey('errors', $js);
        foreach (['network', 'unauthorized', 'forbidden', 'not_found', 'session_expired', 'validation', 'server', 'http'] as $kind) {
            self::assertArrayHasKey($kind, $js['errors'], "js.errors.{$kind} is used by lt-core.js");
        }
    }

    public function testProjectLangFilesHaveSameKeys(): void
    {
        $fr = self::flatten(require dirname(__DIR__, 2) . '/lang/fr.php');
        $en = self::flatten(require dirname(__DIR__, 2) . '/lang/en.php');
        self::assertSame(array_keys($fr), array_keys($en));
    }

    /** @param array<string, mixed> $lines
     *  @return array<string, string> */
    private static function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];
        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = $value;
            }
        }
        ksort($flat);
        return $flat;
    }
}
