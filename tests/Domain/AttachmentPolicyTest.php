<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Attachment\AttachmentPolicy;
use App\Domain\Audit\ActivityDiff;
use App\Domain\RuleViolation;
use PHPUnit\Framework\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    private static function violation(callable $fn): ?string
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return $e->violations()['file'][0][0];
        }
        return null;
    }

    public function testAcceptsPdfAndImagesWithExtensionFromMime(): void
    {
        $policy = new AttachmentPolicy(1000);
        self::assertSame('pdf', $policy->check('application/pdf', 10));
        self::assertSame('jpg', $policy->check('image/jpeg', 1000));
        self::assertSame('png', $policy->check('image/png', 1));
        self::assertSame('tif', $policy->check('image/tiff', 1));
    }

    public function testRejectsOtherTypesEmptyAndOversizedFiles(): void
    {
        $policy = new AttachmentPolicy(1000);
        foreach (['text/html', 'image/svg+xml', 'application/x-php', 'application/zip', ''] as $mime) {
            self::assertSame('rules.attachment.type', self::violation(fn () => $policy->check($mime, 10)), $mime);
        }
        self::assertSame('rules.attachment.empty', self::violation(fn () => $policy->check('application/pdf', 0)));
        self::assertSame('rules.attachment.too_large', self::violation(fn () => $policy->check('application/pdf', 1001)));
    }

    public function testInlineOnlyForBrowserSafeTypes(): void
    {
        self::assertTrue(AttachmentPolicy::isInline('application/pdf'));
        self::assertTrue(AttachmentPolicy::isInline('image/png'));
        self::assertFalse(AttachmentPolicy::isInline('image/tiff'));
        self::assertFalse(AttachmentPolicy::isInline('text/html'));
    }

    public function testSanitizeName(): void
    {
        self::assertSame('facture été.pdf', AttachmentPolicy::sanitizeName('C:\\Users\\x\\facture été.pdf'));
        self::assertSame('passwd', AttachmentPolicy::sanitizeName('../../etc/passwd'));
        self::assertSame('ab.pdf', AttachmentPolicy::sanitizeName("a\x00b\n.pdf"));
        self::assertSame('document', AttachmentPolicy::sanitizeName('  ..  '));
        self::assertSame('document', AttachmentPolicy::sanitizeName("\xff\xfe"));
        $long = AttachmentPolicy::sanitizeName(str_repeat('é', 300) . '.pdf');
        self::assertSame(200, mb_strlen($long));
        self::assertStringEndsWith('.pdf', $long);
    }

    public function testActivityDiffKeepsOnlyChanges(): void
    {
        [$old, $new] = ActivityDiff::diff(
            ['subject' => 'A', 'priority' => 'normal', 'department_id' => 3, 'summary' => '', 'flag' => true],
            ['subject' => 'B', 'priority' => 'normal', 'department_id' => '3', 'summary' => null, 'flag' => false, 'extra' => 'x'],
        );
        self::assertSame(['subject' => 'A', 'flag' => true, 'extra' => null], $old);
        self::assertSame(['subject' => 'B', 'flag' => false, 'extra' => 'x'], $new);
        self::assertSame([[], []], ActivityDiff::diff(['a' => 1], ['a' => 1]));
    }
}
