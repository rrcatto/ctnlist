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

    /** --production applies the production rules; this test installation is not production, so it fails, with ERROR lines naming why. */
    public function testProductionRulesFailAnInstallationThatIsNotReady(): void
    {
        $command = new CommandTester($this->service(DiagnoseCommand::class));
        $status = $command->execute(['--production' => true]);
        $display = $command->getDisplay();

        self::assertSame(1, $status, 'errors make the exit status 1');
        self::assertStringContainsString('(production rules)', $display);
        self::assertStringContainsString('ERROR APP_ENV is "test"', $display);
        self::assertStringContainsString('ERROR APP_BASE_URL', $display);
        self::assertStringContainsString('ERROR SUPPRESSION_PROVIDER=none', $display);
        self::assertStringContainsString('OK    database schema up to date', $display);
        self::assertMatchesRegularExpression('/^OK    PHP extension pdo_pgsql$/m', $display);
        foreach (['APP_SECRET', 'APP_SETTINGS_KEY', 'CATTOMAIL_API_KEY', 'CATTOMAIL_WEBHOOK_SECRET', 'CATTOMAIL_WEBHOOK_SECRET_PREVIOUS'] as $name) {
            $value = (string) ($_SERVER[$name] ?? getenv($name) ?: '');
            if (strlen($value) >= 6) {
                self::assertStringNotContainsString($value, $display, $name . ' value never printed');
            }
        }
    }

    /** The worker's recorded configuration fingerprint is compared with this process's. */
    public function testReportsAWorkerRunningWithAnotherConfiguration(): void
    {
        $options = $this->service(\App\Repository\OptionRepository::class);
        $options->set(\App\CattoMail\CattoMailActivity::WORKER_CONFIG, $this->service(\App\Config\ConfigFingerprint::class)->value());
        $same = new CommandTester($this->service(DiagnoseCommand::class));
        $same->execute([]);
        self::assertStringContainsString('OK    the worker runs with this configuration', $same->getDisplay());

        $options->set(\App\CattoMail\CattoMailActivity::WORKER_CONFIG, 'aaaaaaaaaaaa');
        $other = new CommandTester($this->service(DiagnoseCommand::class));
        $other->execute([]);
        self::assertStringContainsString('WARN  the worker last ran with a different configuration', $other->getDisplay());
    }
}
