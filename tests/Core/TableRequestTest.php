<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\FileStorage;
use App\Core\Page;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TableRequestTest extends TestCase
{
    private static function req(array $query): TableRequest
    {
        return TableRequest::fromRequest(new Request('GET', '/x', $query), ['name', 'date'], 'date', 'desc');
    }

    public function testParsesPagingSortAndSearch(): void
    {
        $t = self::req(['page' => '3', 'per_page' => '25', 'sort' => 'name', 'dir' => 'asc', 'q' => '  facture ']);
        self::assertSame(3, $t->page);
        self::assertSame(50, $t->offset());
        self::assertSame('name', $t->sort);
        self::assertSame('ASC', $t->dir);
        self::assertSame('facture', $t->search);
        self::assertSame('%facture%', $t->likePattern());
    }

    public function testDefaultsAndLimits(): void
    {
        $t = self::req(['page' => '-2', 'per_page' => '5000', 'sort' => 'password_hash', 'dir' => 'sideways', 'q' => '']);
        self::assertSame(1, $t->page);
        self::assertSame(TableRequest::MAX_PER_PAGE, $t->perPage);
        self::assertSame('date', $t->sort, 'unknown sort keys fall back to the default');
        self::assertSame('DESC', $t->dir);
        self::assertNull($t->search);
        self::assertNull($t->likePattern());
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        self::assertSame('%100\%\_a\\\\b%', self::req(['q' => '100%_a\\b'])->likePattern());
    }

    public function testPageEnvelope(): void
    {
        self::assertSame(['data' => [['id' => 1]], 'meta' => ['total' => 10, 'filtered' => 1]], (new Page([['id' => 1]], 10, 1))->toArray());
    }

    public function testFileStorageConfinesPaths(): void
    {
        $root = sys_get_temp_dir() . '/lettie_fs_' . bin2hex(random_bytes(4));
        $storage = new FileStorage($root, uploadsOnly: false);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'data');

        $storage->storeUpload($tmp, '1/2026/09/abc.pdf');
        self::assertTrue($storage->exists('1/2026/09/abc.pdf'));
        self::assertFileDoesNotExist($tmp);
        $storage->delete('1/2026/09/abc.pdf');
        self::assertFalse($storage->exists('1/2026/09/abc.pdf'));

        foreach (['../x', '1/../../x', '/etc/passwd', 'C:/Windows/x', "a\0b", '', './x'] as $bad) {
            try {
                $storage->path($bad);
                self::fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        array_map('rmdir', [$root . '/1/2026/09', $root . '/1/2026', $root . '/1', $root]);
    }

    public function testUploadsOnlyModeRejectsArbitraryFiles(): void
    {
        $storage = new FileStorage(sys_get_temp_dir() . '/lettie_never', uploadsOnly: true);
        self::assertFalse($storage->isUpload(__FILE__));
    }

    public function testFileResponseHeaders(): void
    {
        $response = Response::file(__FILE__, 'application/pdf', 'Réponse "finale".pdf', inline: true);
        self::assertSame('application/pdf', $response->header('Content-Type'));
        self::assertSame((string) filesize(__FILE__), $response->header('Content-Length'));
        self::assertSame('inline; filename="R_ponse _finale_.pdf"; filename*=UTF-8\'\'R%C3%A9ponse%20%22finale%22.pdf', $response->header('Content-Disposition'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        self::assertSame(__FILE__, $response->filePath());
        self::assertStringStartsWith('attachment;', (string) Response::file(__FILE__, 'image/png', 'x.png')->header('Content-Disposition'));
    }
}
