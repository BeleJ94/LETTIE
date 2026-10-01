<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Repositories\Repository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArchitectureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public function testEveryRepositoryExtendsScopedBaseAndCannotBypassIt(): void
    {
        $files = self::phpFiles('app/Repositories');
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'App\\Repositories\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            if ($class === Repository::class) {
                self::assertTrue($reflection->isAbstract());
                continue;
            }
            self::assertTrue($reflection->isSubclassOf(Repository::class), "{$class} must extend Repository");
            self::assertSame(Repository::class, $reflection->getConstructor()?->getDeclaringClass()->getName(), "{$class} must not override the constructor");
        }
    }

    public function testSqlOnlyInRepositories(): void
    {
        foreach (['app/Controllers', 'app/Services', 'app/Middleware', 'app/Domain'] as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                $code = (string) file_get_contents($file);
                self::assertDoesNotMatchRegularExpression('/->(prepare|exec)\s*\(|pdo->query\s*\(/i', $code, "SQL call in {$file}");
                self::assertStringNotContainsString('use PDO', $code, "PDO used in {$file}");
            }
        }
    }

    public function testControllersAndMiddlewareGoThroughServices(): void
    {
        foreach (['app/Controllers', 'app/Middleware'] as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                self::assertStringNotContainsString('App\\Repositories\\', (string) file_get_contents($file), "{$file} must call a Service, not a Repository");
            }
        }
    }

    public function testDomainHasNoIo(): void
    {
        foreach (self::phpFiles('app/Domain') as $file) {
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\$_(GET|POST|SESSION|SERVER|COOKIE|FILES)|App\\\\(Core|Repositories|Services)\\\\/', $code, $file);
        }
    }

    public function testPinnedVendorAssetsExistWithLicenses(): void
    {
        $vendor = self::root() . '/public/assets/vendor';
        $expected = [
            'jquery-3.7.1' => ['jquery.min.js'],
            'datatables-2.1.8' => ['dataTables.min.js', 'dataTables.dataTables.min.css'],
            'chartjs-4.4.7' => ['chart.umd.js'],
            'sweetalert2-11.14.5' => ['sweetalert2.min.js', 'sweetalert2.min.css'],
            'lucide-0.460.0' => ['lucide.min.js'],
            'exceljs-4.4.0' => ['exceljs.min.js'],
            'pdfmake-0.2.14' => ['pdfmake.min.js', 'vfs_fonts.js'],
            'ibm-plex-sans-5.1.0' => ['IBMPlexSans-Regular-Latin1.woff2', 'IBMPlexSans-Bold-Latin1.woff2'],
        ];
        foreach ($expected as $dir => $files) {
            foreach ($files as $file) {
                self::assertFileExists("{$vendor}/{$dir}/{$file}");
            }
            self::assertNotEmpty(glob("{$vendor}/{$dir}/LICENSE*"), "License missing for {$dir}");
        }
        self::assertStringContainsString('jQuery v3.7.1', (string) file_get_contents("{$vendor}/jquery-3.7.1/jquery.min.js", length: 200));
    }

    public function testLayoutReferencesOnlyExistingLocalAssets(): void
    {
        $layout = (string) file_get_contents(self::root() . '/views/layouts/main.php');
        preg_match_all('#<\?= e\(\$(vendor|assets)\) \?>(/[^"]+)"#', $layout, $m, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(8, count($m));
        foreach ($m as [, $var, $path]) {
            $file = self::root() . '/public/assets' . ($var === 'vendor' ? '/vendor' : '') . $path;
            self::assertFileExists($file);
        }
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $layout, 'No CDN: every asset is served locally');
    }

    /**
     * DataTables 2 reads data-* attributes of header cells as column options
     * (data-render="due" became render: "due" and crashed on null values).
     */
    public function testTableHeadersUseLettiePrefixedAttributes(): void
    {
        foreach (self::phpFiles('views') as $file) {
            self::assertDoesNotMatchRegularExpression('/<th[^>]*\sdata-(name|render|sortable|orderable|class|class-name|type|width|visible|searchable)=/i',
                (string) file_get_contents($file), "{$file}: use data-lt-* on table headers");
        }
    }

    public function testAssetsReferencedByViewsExist(): void
    {
        foreach (self::phpFiles('views') as $file) {
            preg_match_all('#\$basePath\) \?>(/assets/[^"?]+)#', (string) file_get_contents($file), $m);
            foreach ($m[1] as $path) {
                self::assertFileExists(self::root() . '/public' . $path, "Referenced in {$file}");
            }
        }
    }

    public function testSvgImagesAreStaticAndSelfContained(): void
    {
        foreach (glob(self::root() . '/public/assets/img/*.svg') ?: [] as $svg) {
            $content = (string) file_get_contents($svg);
            self::assertNotFalse(@simplexml_load_string($content), "{$svg} is not well-formed XML");
            self::assertDoesNotMatchRegularExpression('/<script|\son[a-z]+\s*=|javascript:|<foreignObject/i', $content, "{$svg} must not contain scripts");
            self::assertDoesNotMatchRegularExpression('#(href|src)\s*=\s*"(https?:)?//#i', $content, "{$svg} must not load external resources");
        }
    }

    public function testCssFontsPointToVendoredFiles(): void
    {
        $css = (string) file_get_contents(self::root() . '/public/assets/css/app.css');
        preg_match_all('#url\("\.\./vendor/([^"]+)"\)#', $css, $m);
        self::assertCount(5, $m[1]);
        foreach ($m[1] as $path) {
            self::assertFileExists(self::root() . '/public/assets/vendor/' . $path);
        }
    }

    public function testViewsContainNoInlineScriptsOrStyles(): void
    {
        foreach (self::phpFiles('views') as $file) {
            $html = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, "Inline <script> in {$file}");
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "Inline event handler in {$file}");
            self::assertDoesNotMatchRegularExpression('/<style\b|\sstyle\s*=/i', $html, "Inline style in {$file}");
            self::assertDoesNotMatchRegularExpression('/javascript:/i', $html, "javascript: URL in {$file}");
        }
    }
}
