<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\SettingsCipher;
use App\Config\SettingsCipherException;
use PHPUnit\Framework\TestCase;

final class SettingsCipherTest extends TestCase
{
    public function testRoundTripWithFreshNonces(): void
    {
        $cipher = new SettingsCipher(base64_encode(random_bytes(32)));
        $a = $cipher->encrypt('smtp://user:s3cret@mail.example.net:465');
        $b = $cipher->encrypt('smtp://user:s3cret@mail.example.net:465');

        self::assertNotSame($a, $b, 'a fresh nonce for every encryption');
        self::assertStringStartsWith('enc:v1:', $a);
        self::assertTrue(SettingsCipher::isEncrypted($a));
        self::assertStringNotContainsString('s3cret', $a);
        self::assertSame('smtp://user:s3cret@mail.example.net:465', $cipher->decrypt($a));
        self::assertSame('', $cipher->decrypt($cipher->encrypt('')));
        self::assertNull($cipher->problem());
    }

    public function testTamperedOrForeignDataFailsSafely(): void
    {
        $cipher = new SettingsCipher(base64_encode(random_bytes(32)));
        $stored = $cipher->encrypt('secret value');
        [$p1, $p2, $kid, $payload] = explode(':', $stored, 4);
        $raw = (string) base64_decode($payload, true);

        $cases = [
            'ciphertext' => $raw ^ str_pad('', strlen($raw) - 1, "\0") . "\x01",
            'tag' => substr($raw, 0, 12) . (substr($raw, 12, 1) ^ "\x01") . substr($raw, 13),
            'nonce' => ("\x01" ^ $raw[0]) . substr($raw, 1),
            'truncated' => substr($raw, 0, 20),
        ];
        foreach ($cases as $what => $damaged) {
            try {
                $cipher->decrypt("{$p1}:{$p2}:{$kid}:" . base64_encode($damaged));
                self::fail("accepted a damaged {$what}");
            } catch (SettingsCipherException $e) {
                self::assertStringNotContainsString('secret value', $e->getMessage());
            }
        }

        $this->expectException(SettingsCipherException::class);
        $this->expectExceptionMessage('different APP_SETTINGS_KEY');
        (new SettingsCipher(base64_encode(random_bytes(32))))->decrypt($stored);
    }

    public function testMissingOrMalformedKeyIsAControlledConfigurationError(): void
    {
        foreach ([null, '', '   ', 'not base64!', base64_encode(random_bytes(16))] as $key) {
            $cipher = new SettingsCipher($key);
            self::assertNotNull($cipher->problem());
            self::assertStringContainsString('APP_SETTINGS_KEY', (string) $cipher->problem());
            try {
                $cipher->encrypt('x');
                self::fail('encrypted without a usable key');
            } catch (SettingsCipherException $e) {
                self::assertStringContainsString('openssl rand -base64 32', $e->getMessage());
            }
        }
    }
}
