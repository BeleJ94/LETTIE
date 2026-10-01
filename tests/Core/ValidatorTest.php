<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Translator;
use App\Core\ValidationException;
use App\Core\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new Translator(dirname(__DIR__, 2) . '/lang', 'en'));
    }

    public function testReturnsValidatedAndCastData(): void
    {
        $data = $this->validator->validate(
            ['subject' => '  Invoice  ', 'count' => '3', 'urgent' => '1', 'note' => '', 'extra' => 'ignored'],
            ['subject' => 'required|string|max:255', 'count' => 'int|min:1', 'urgent' => 'bool', 'note' => 'nullable|string'],
        );

        self::assertSame(['subject' => 'Invoice', 'count' => 3, 'urgent' => true, 'note' => null], $data);
    }

    public function testCollectsTranslatedErrors(): void
    {
        try {
            $this->validator->validate(
                ['email' => 'nope', 'priority' => 'x', 'date' => '2026-02-30'],
                ['subject' => 'required', 'email' => 'email', 'priority' => 'in:low,normal,high', 'date' => 'date'],
                ['subject' => 'validation.fixture_label'],
            );
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertSame(['subject', 'email', 'priority', 'date'], array_keys($errors));
            self::assertSame('The validation.fixture_label field is required.', $errors['subject'][0]);
            self::assertSame('The email field must be a valid email address.', $errors['email'][0]);
        }
    }

    public function testMinMaxUseLengthForStringsAndValueForNumbers(): void
    {
        $this->validator->validate(['code' => 'ab', 'n' => '50'], ['code' => 'string|min:2|max:2', 'n' => 'int|max:50']);

        $this->expectException(ValidationException::class);
        $this->validator->validate(['n' => '51'], ['n' => 'int|max:50']);
    }

    public function testDatetimeRegexAndSame(): void
    {
        $data = $this->validator->validate(
            ['at' => '2026-09-30 14:05', 'ref' => 'IN-2026-0001', 'pwd' => 'x', 'pwd2' => 'x'],
            ['at' => 'datetime', 'ref' => 'regex:/^(IN|OUT)-\d{4}-\d{4}$/', 'pwd2' => 'same:pwd'],
        );
        self::assertSame('IN-2026-0001', $data['ref']);
    }

    public function testUnknownRuleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate(['a' => 'x'], ['a' => 'bogus']);
    }
}
