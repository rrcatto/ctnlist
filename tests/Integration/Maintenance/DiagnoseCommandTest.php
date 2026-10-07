<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Command\DiagnoseCommand;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DiagnoseCommandTest extends IntegrationTestCase
{
    public function testReportsWithoutRevealingSecretsAndFlagsObsoleteSettings(): void
    {
        $_SERVER['MAIL_UNSUBSCRIBE_ADDRESS'] = 'unsubscribe@example.com';
        try {
            $command = new CommandTester($this->service(DiagnoseCommand::class));
            $status = $command->execute([]);
        } finally {
            unset($_SERVER['MAIL_UNSUBSCRIBE_ADDRESS']);
        }
        $display = $command->getDisplay();
        self::assertStringContainsString('OK    database reachable', $display);
        self::assertStringContainsString('WARN  MAIL_UNSUBSCRIBE_ADDRESS is set but no longer used', $display);
        self::assertStringContainsString('APP_SECRET set', $display);
        foreach (['APP_SECRET', 'APP_SETTINGS_KEY', 'CATTOMAIL_API_KEY', 'CATTOMAIL_WEBHOOK_SECRET'] as $name) {
            $value = (string) ($_SERVER[$name] ?? getenv($name) ?: '');
            if (strlen($value) >= 6) {
                self::assertStringNotContainsString($value, $display, $name . ' value never printed');
            }
        }
        self::assertSame(0, $status, $display);
    }
}
