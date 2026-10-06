<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailHealth;
use App\Command\CattoMailCheckCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** ctnlist:cattomail:check (and bin/dev test-cattomail), against the fake catto-mail. */
final class CattoMailCheckCommandTest extends CattoMailTestCase
{
    public function testConnectionCheckWithoutSecrets(): void
    {
        $command = new CommandTester($this->service(CattoMailCheckCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('Connection:   Reachable and authenticated', $command->getDisplay());
        self::assertStringContainsString('secret set', $command->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $command->getDisplay());
        self::assertSame([], $this->fake->requestsTo('POST', '/.*'), 'the check creates nothing');
    }

    public function testEndToEndProofAndValidation(): void
    {
        $this->fake->finishOnRead = true;
        $id = $this->createSubscriber('tester@ctnlist.test', 'Tess');
        $muid = $this->createMessage('E2E');
        $command = new CommandTester($this->service(CattoMailCheckCommand::class));

        $status = $command->execute(['--e2e' => true, '--to' => 'tester@ctnlist.test', '--muid' => $muid, '--wait' => '0', '--interval' => '0']);
        $display = $command->getDisplay();
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('Connection:   Reachable and authenticated', $display);
        self::assertStringContainsString('Validation job', $display);
        self::assertStringContainsString('job completed, recipient remote_accepted, validation completed (results stored)', $display);
        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertSame('PROOF', $this->db->fetchOne('SELECT sl_type FROM sendlog ORDER BY sl_id DESC LIMIT 1'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM smlog WHERE sml_muid = ?', [$muid]), 'a proof never touches smlog');
        self::assertSame($id, (int) $this->db->fetchOne('SELECT s_id FROM subscribers WHERE s_email = ?', ['tester@ctnlist.test']), 'no subscriber created or changed');
    }

    public function testRefusesAnythingButALocalDevelopmentCattoMail(): void
    {
        $refusal = new \ReflectionMethod(CattoMailCheckCommand::class, 'e2eRefusal');
        $command = fn(string $base, string $connect, string $env): ?string => $refusal->invoke(new CattoMailCheckCommand(
            new CattoMailConfig($base, 'key', 'whsec', connectHost: $connect), $this->service(CattoMailHealth::class), $this->service(\App\Config\SiteConfig::class),
            $this->service(\App\Repository\MessageRepository::class), $this->service(\App\Campaign\MessageService::class), $this->service(\App\Repository\CattoMailSendRepository::class),
            $this->service(\App\CattoMail\SendJobSync::class), $this->service(\App\Repository\SubscriberRepository::class), $this->service(\App\CattoMail\AddressValidation::class),
            $this->service(\App\Repository\CattoMailValidationRepository::class), $env));
        self::assertStringContainsString('not a local development catto-mail', (string) $command('https://mail.example.com', '', 'dev'));
        self::assertStringContainsString('only dev or test', (string) $command('https://localhost', 'catto-mail', 'prod'));
        self::assertNull($command('https://localhost', 'catto-mail', 'dev'), 'the development route');
        self::assertNull($command('https://cattomail.test', '', 'test'));
    }
}
