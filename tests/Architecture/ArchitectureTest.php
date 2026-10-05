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
            'lucide-0.460.0' => ['lucide.min.js'],
            'exceljs-4.4.0' => ['exceljs.min.js'],
            'pdfmake-0.2.14' => ['pdfmake.min.js', 'vfs_fonts.js'],
            'ui5-webcomponents-2.27.2' => ['ui5.js', 'ui5-fonts.css', 'BUILD.json'],
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

    public function testUi5FontsPointToVendoredFiles(): void
    {
        $dir = self::root() . '/public/assets/vendor/ui5-webcomponents-2.27.2';
        $css = (string) file_get_contents($dir . '/ui5-fonts.css');
        preg_match_all('#url\(([^)]+)\)#', $css, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            self::assertStringStartsWith('fonts/', $url, 'The "72" font is served locally, never from a CDN');
            self::assertFileExists($dir . '/' . $url);
        }
    }

    /** docs/FIORI_DESIGN.md §3: colours, fonts and sizes come only from the theme variables. */
    public function testAppCssUsesOnlyThemeVariables(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::root() . '/public/assets/css/app.css'));
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $css, 'Hex colour in app.css');
        // Only black alpha masks (mask-image) are allowed as colour functions.
        self::assertDoesNotMatchRegularExpression('/\b(rgb|hsl)a?\((?!0, 0, 0, [.0-9]+\))/i', $css, 'Colour function in app.css');
        self::assertDoesNotMatchRegularExpression('/font-family:\s*+(?!var\(--sap)/i', $css, 'Font family outside the theme');
        self::assertDoesNotMatchRegularExpression('/font-size:\s*+(?!var\(--sap)/i', $css, 'Font size outside the theme');
        self::assertDoesNotMatchRegularExpression('/@font-face/i', $css, 'The "72" font faces are declared by ui5-fonts.css');

        preg_match_all('/(--lt-[a-z0-9-]+)\s*:/', $css, $m);
        $local = array_values(array_unique($m[1]));
        sort($local);
        self::assertSame(['--lt-content-max', '--lt-tap', '--lt-transition'], $local, 'Only layout dimensions may be local variables');
    }

    /** Screens still built with the old components. This list may only shrink (docs/FIORI_MIGRATION_PLAN.md). */
    private const NOT_MIGRATED_YET = [
        'views/delegations/index.php',
        'views/notifications/index.php',
        'views/statistics/index.php',
    ];

    /** docs/FIORI_DESIGN.md §4: every screen declares its floorplan, or "None" with its reason. */
    public function testEveryScreenDeclaresAnAllowedFloorplan(): void
    {
        $allowed = ['Launchpad', 'ListReport', 'ObjectPage', 'Worklist', 'OverviewPage', 'AnalyticalListPage', 'Wizard', 'None'];
        $declared = [];
        foreach (self::phpFiles('views') as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(self::root()) + 1));
            // Partials and layouts are parts of screens, not screens.
            if (preg_match('#^views/(partials|layouts)/|/_[a-z_-]+\.php$#', $relative) === 1) {
                continue;
            }
            $found = preg_match('/@floorplan\s+(\w+)(\s+—\s+\S.*)?/u', (string) file_get_contents($file), $m) === 1;
            if (in_array($relative, self::NOT_MIGRATED_YET, true)) {
                self::assertFalse($found, "{$relative} declares a floorplan: remove it from NOT_MIGRATED_YET");
                continue;
            }
            self::assertTrue($found, "{$relative} must declare its floorplan (@floorplan …)");
            self::assertContains($m[1], $allowed, "Unknown floorplan in {$relative}");
            if ($m[1] === 'None') {
                self::assertNotEmpty($m[2] ?? '', "{$relative}: \"@floorplan None\" must give its reason");
            }
            $declared[$relative] = $m[1];
        }
        self::assertSame('Launchpad', $declared['views/home/index.php'] ?? null, 'The home page is the Launchpad');
        self::assertSame(
            ['views/auth/code.php', 'views/auth/forgot.php', 'views/auth/login.php', 'views/auth/reset.php', 'views/errors/error.php', 'views/mails/slip.php'],
            array_keys(array_filter($declared, static fn (string $floorplan): bool => $floorplan === 'None')),
            'Only the sign-in screens, the error page and the slip are outside the floorplans',
        );
        foreach (self::NOT_MIGRATED_YET as $relative) {
            self::assertFileExists(self::root() . '/' . $relative, 'NOT_MIGRATED_YET lists a file that no longer exists');
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
