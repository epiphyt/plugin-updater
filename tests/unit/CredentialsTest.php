<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use epiphyt\Plugin_Updater\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Credentials::class)]
final class CredentialsTest extends TestCase
{
    public function testIsCompleteRequiresBothValues(): void
    {
        self::assertTrue((new Credentials('a@example.com', 'key'))->is_complete());
        self::assertFalse((new Credentials('a@example.com', ''))->is_complete());
        self::assertFalse((new Credentials('', 'key'))->is_complete());
    }

    public function testFromOptionValueAcceptsLicenseEmailKey(): void
    {
        $credentials = Credentials::from_option_value([
            'license_email' => 'a@example.com',
            'license_key' => 'key',
        ]);

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('a@example.com', $credentials->get_email());
        self::assertSame('key', $credentials->get_license_key());
    }

    public function testFromOptionValueAcceptsEmailKey(): void
    {
        $credentials = Credentials::from_option_value([
            'email' => 'a@example.com',
            'license_key' => 'key',
        ]);

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('a@example.com', $credentials->get_email());
    }

    public function testFromOptionValueRejectsNonArray(): void
    {
        self::assertNull(Credentials::from_option_value('nope'));
        self::assertNull(Credentials::from_option_value(null));
        self::assertNull(Credentials::from_option_value([]));
    }

    public function testFromConstantsReturnsNullWhenUndefined(): void
    {
        self::assertNull(Credentials::from_constants('UNDEFINED_EMAIL_X', 'UNDEFINED_KEY_X'));
    }

    public function testFromConstantsReadsDefinedConstants(): void
    {
        define('TEST_LICENSE_EMAIL_X', 'const@example.com');
        define('TEST_LICENSE_KEY_X', 'const-key');

        $credentials = Credentials::from_constants('TEST_LICENSE_EMAIL_X', 'TEST_LICENSE_KEY_X');

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('const@example.com', $credentials->get_email());
        self::assertSame('const-key', $credentials->get_license_key());
    }

    /**
     * The stored key is displayed masked, so a submitted value containing an
     * asterisk must not overwrite the real key.
     */
    public function testFromSubmissionKeepsStoredKeyWhenMasked(): void
    {
        $credentials = Credentials::from_submission(
            ['license_email' => 'new@example.com', 'license_key' => '****abcd'],
            ['license_email' => 'old@example.com', 'license_key' => 'real-key']
        );

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('new@example.com', $credentials->get_email());
        self::assertSame('real-key', $credentials->get_license_key());
    }

    public function testFromSubmissionUsesSubmittedKeyWhenNotMasked(): void
    {
        $credentials = Credentials::from_submission(
            ['license_email' => 'new@example.com', 'license_key' => 'brand-new'],
            ['license_email' => 'old@example.com', 'license_key' => 'real-key']
        );

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('brand-new', $credentials->get_license_key());
    }

    public function testFromSubmissionWithMaskedKeyAndNoStoredValue(): void
    {
        $credentials = Credentials::from_submission(
            ['license_email' => 'new@example.com', 'license_key' => '****'],
            null
        );

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('', $credentials->get_license_key());
        self::assertFalse($credentials->is_complete());
    }

    /**
     * Only these two values may ever be sent. The stored option carries more.
     */
    public function testToRequestArgsSendsOnlyCredentials(): void
    {
        $credentials = Credentials::from_option_value([
            'license_email' => 'a@example.com',
            'license_key' => 'key',
            'license_request' => 'should-not-be-sent',
        ]);

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame(
            ['email' => 'a@example.com', 'license_key' => 'key'],
            $credentials->to_request_args()
        );
    }
}
