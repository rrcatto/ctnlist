<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** bin/dev ensure-env: APP_SETTINGS_KEY is generated once and never replaced. */
final class DevEnvironmentTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ctnlist-dev-env-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testKeyIsGeneratedOnceAndPreserved(): void
    {
        $this->ensureEnv();
        $generated = $this->key();
        self::assertSame(32, strlen((string) base64_decode($generated, true)), 'a fresh 256-bit key');
        $inode = fileinode($this->file);

        $this->ensureEnv();
        self::assertSame($generated, $this->key(), 'an existing key is kept');
        self::assertSame($inode, fileinode($this->file), 'the file is not replaced');
    }

    public function testMissingOrEmptyKeyIsFilledInPlace(): void
    {
        file_put_contents($this->file, "APP_ENV=dev\nAPP_SECRET=abc\nAPP_SETTINGS_KEY=\n");
        $inode = fileinode($this->file);
        $this->ensureEnv();
        self::assertNotSame('', $this->key());
        self::assertSame($inode, fileinode($this->file), 'rewritten in place, so a running bind mount sees it');
        self::assertStringContainsString("APP_SECRET=abc\n", (string) file_get_contents($this->file), 'other settings untouched');
        self::assertSame(1, substr_count((string) file_get_contents($this->file), 'APP_SETTINGS_KEY='));

        file_put_contents($this->file, "APP_ENV=dev\n");
        $this->ensureEnv();
        self::assertNotSame('', $this->key());
    }

    public function testRepeatedRunsChangeNothingAndKeepUnrelatedEntries(): void
    {
        $original = "# local overrides\nAPP_ENV=dev\n\nMAILER_DSN=\"smtp://mailpit:1025\"\nCATTOMAIL_API_KEY=\nAPP_SETTINGS_KEY=\nZ_LAST=1 # comment\n";
        file_put_contents($this->file, $original);
        $this->ensureEnv();
        $once = (string) file_get_contents($this->file);
        self::assertSame(str_replace("APP_SETTINGS_KEY=\n", 'APP_SETTINGS_KEY=' . $this->key() . "\n", $original), $once, 'only the key line changes, in place and order');

        $this->ensureEnv();
        $this->ensureEnv();
        self::assertSame($once, (string) file_get_contents($this->file), 'idempotent');
    }

    public function testNewFileGetsFreshSecretsAndNoPlaceholders(): void
    {
        @unlink($this->file);
        $this->ensureEnv();
        $first = (string) file_get_contents($this->file);
        foreach (['APP_SECRET' => 32, 'APP_INSTANCE_ID' => 16] as $name => $bytes) {
            self::assertMatchesRegularExpression('/^' . $name . '=[0-9a-f]{' . (2 * $bytes) . '}$/m', $first, $name);
        }
        $key = $this->key();
        self::assertSame(32, strlen((string) base64_decode($key, true)));

        unlink($this->file);
        $this->ensureEnv();
        self::assertNotSame($key, $this->key(), 'every installation gets its own key');
    }

    private function ensureEnv(): void
    {
        $root = dirname(__DIR__, 2);
        exec(sprintf('cd %s && CTNLIST_DEV_ENV_FILE=%s bash bin/dev ensure-env 2>&1', escapeshellarg($root), escapeshellarg($this->file)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    private function key(): string
    {
        preg_match('/^APP_SETTINGS_KEY=(.*)$/m', (string) file_get_contents($this->file), $m);
        return trim($m[1] ?? '');
    }
}
