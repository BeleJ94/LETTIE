<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\View;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lettie_views_' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/layouts', 0777, true);
        mkdir(self::$dir . '/pages');
        file_put_contents(self::$dir . '/layouts/main.php', '<html><title><?= e($this->section(\'title\', \'Default\')) ?></title><?= $this->section(\'content\') ?></html>');
        file_put_contents(self::$dir . '/pages/hello.php', '<?php $this->layout(\'layouts/main\') ?><?php $this->start(\'title\') ?>Hi<?php $this->stop() ?><p><?= e($name) ?> @ <?= e($app) ?></p>');
        file_put_contents(self::$dir . '/pages/plain.php', '<b><?= e($name) ?></b><?= $this->partial(\'pages/part\', [\'x\' => 1]) ?>');
        file_put_contents(self::$dir . '/pages/part.php', '<i><?= e($x) ?></i>');
        file_put_contents(self::$dir . '/pages/broken.php', '<p>before<?php throw new RuntimeException(\'boom\') ?>');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['layouts/main', 'pages/hello', 'pages/plain', 'pages/part', 'pages/broken'] as $f) {
            unlink(self::$dir . "/{$f}.php");
        }
        rmdir(self::$dir . '/layouts');
        rmdir(self::$dir . '/pages');
        rmdir(self::$dir);
    }

    public function testRendersWithLayoutSectionsSharedDataAndEscaping(): void
    {
        $view = new View(self::$dir);
        $view->share('app', 'Lettie');
        $html = $view->render('pages/hello', ['name' => '<script>']);

        self::assertSame('<html><title>Hi</title><p>&lt;script&gt; @ Lettie</p></html>', $html);
    }

    public function testRendersWithoutLayoutAndPartials(): void
    {
        self::assertSame('<b>A&amp;B</b><i>1</i>', (new View(self::$dir))->render('pages/plain', ['name' => 'A&B']));
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new View(self::$dir))->render('../secret');
    }

    public function testMissingViewThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new View(self::$dir))->render('pages/missing');
    }

    public function testExceptionInTemplateCleansOutputBuffers(): void
    {
        $level = ob_get_level();
        try {
            (new View(self::$dir))->render('pages/broken');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($level, ob_get_level());
    }

    public function testEscapeHelper(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;&#039;', e('<a href="x">\''));
        self::assertSame('', e(null));
        self::assertSame('42', e(42));
    }
}
