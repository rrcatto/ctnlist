<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\SendingDomainCheck;
use App\CattoMail\SendingSetup;
use App\Config\SiteConfig;
use App\Tests\Support\FakeCattoMail;

/**
 * Settings › Set up sending (SendingSetup, owner decision 2026-10-27): where the four steps stand,
 * from what is stored here, and the test message through the mail service. The test environment
 * configures the mail service in .env (address and key), so the first two steps are done.
 */
final class SendingSetupTest extends CattoMailTestCase
{
    public function testTheStepsFollowWhatIsKnownAndTheTestMessageGoesThroughTheMailService(): void
    {
        $site = $this->service(SiteConfig::class);
        $domain = strtolower(substr((string) strrchr($site->fromAddress, '@'), 1));
        $setup = $this->service(SendingSetup::class);

        $state = $setup->state();
        self::assertSame(['account' => true, 'connect' => true, 'domain' => false, 'test' => false], $state['steps']);
        self::assertSame(['domain', 2, 4, FakeCattoMail::ORIGIN], [$state['current'], $state['done'], $state['total'], $state['address']]);
        self::assertSame(SendingDomainCheck::UNCHECKED, $state['from_domain']['state'], 'nothing is asked of the mail service to draw the page');
        self::assertSame([], $this->fake->requests);

        // The mail service's answer (the worker's, or "Ask the mail service now"): the From domain is being set up.
        $this->fake->senderDomains = [];
        $this->fake->domainStates[$domain] = ['status' => 'pending', 'dkim_status' => 'not_configured', 'can_send' => false];
        $this->service(SendingDomainCheck::class)->refresh();
        $state = $setup->state();
        self::assertSame([SendingDomainCheck::CANNOT_SEND, 'domain'], [$state['from_domain']['state'], $state['current']]);
        self::assertStringStartsWith(FakeCattoMail::ORIGIN . '/dashboard/c/', $state['from_domain']['setup_url'], 'its DNS steps at the mail service');

        // Ready at the mail service.
        unset($this->fake->domainStates[$domain]);
        $this->fake->senderDomains = [$domain];
        $this->service(SendingDomainCheck::class)->refresh();
        self::assertSame('test', $setup->state()['current']);

        // The test message: one transactional send job, taken by the mail service, logged like a proof.
        $outcome = $setup->sendTest('setup-admin@ctnlist.test');
        self::assertNull($outcome->problem);
        self::assertSame(1, $outcome->handedOff);
        self::assertCount(1, $this->fake->sendJobs);
        $job = array_values($this->fake->sendJobs)[0];
        self::assertSame('transactional', $job['message_class'] ?? null);
        self::assertSame($site->fromAddress, $job['sender_identity']['email'] ?? null);
        self::assertSame(['PROOF', 'Test message from ' . $site->listName], array_values($this->db->fetchAssociative(
            'SELECT sl_type, sl_subject FROM sendlog WHERE sl_email = ? ORDER BY sl_id DESC LIMIT 1', ['setup-admin@ctnlist.test']) ?: []));
        $state = $setup->state();
        self::assertSame([null, 'setup-admin@ctnlist.test'], [$state['current'], $state['test']['to'] ?? null], 'every step done');
        self::assertTrue($setup->complete());
    }

    public function testATestTheMailServiceRefusesLeavesTheStepOpen(): void
    {
        $this->fake->senderDomains = [];
        $outcome = $this->service(SendingSetup::class)->sendTest('setup-admin@ctnlist.test');
        self::assertNotNull($outcome->problem, 'the From domain is not a sending domain there');
        self::assertFalse($this->service(SendingSetup::class)->state()['steps']['test']);
    }
}
