<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Campaign\MessageService;
use App\CattoMail\SendOutcome;
use App\Log\SendLog;
use App\Mail\MailConnection;
use App\Mail\MailConnectionFactory;
use App\Mail\ProofMailer;
use App\Repository\TemplateRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\CapturingMailTransport;
use App\Tests\Support\FakeCattoMail;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport;

/**
 * The two routes of a proof ("Send to me"): through the mail service when
 * MAIL_PROOF_SMTP is empty, and straight to a Mailpit instance when it is
 * set. Either way the message is merged with its template for the test
 * recipient and logged as PROOF; only proofs take the Mailpit route.
 */
final class ProofRouteTest extends IntegrationTestCase
{
    private FakeCattoMail $fake;
    private CapturingMailTransport $mailpit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->fakeCattoMail();
        $this->mailpit = new CapturingMailTransport();
    }

    public function testWithoutTheSettingAProofGoesThroughTheMailService(): void
    {
        $this->proofsTo('');
        $muid = $this->messageWithTemplate();

        $outcome = $this->service(MessageService::class)->sendProof($muid, 'tester@outside.org');

        self::assertSame(SendOutcome::SUBMITTED, $outcome->status);
        // One transactional job at the mail service, carrying the message merged with its template.
        self::assertCount(1, $this->fake->sendJobs);
        $job = $this->fake->sendJobs[$this->fake->lastSendJobId()];
        self::assertSame('transactional', $job['message_class']);
        $recipient = $this->fake->recipients[$this->fake->lastSendJobId()][0];
        self::assertSame('tester@outside.org', $recipient['email_address']);
        self::assertSame('<div class="frame"><h1>ctnlist proofs</h1><p>Hello Test, this is the body.</p><p>UNSUBSCRIBE</p></div>',
            preg_replace('#<a [^>]*>(.*?)</a>#', '$1', $recipient['html_body']));
        self::assertSame("ctnlist proofs\n\nHello Test, this is the body.\n", $recipient['text_body']);
        // Nothing went to Mailpit.
        self::assertSame([], $this->mailpit->connections);
        self::assertSame([], $this->mailpit->messages);
        self::assertSame([['PROOF', 'tester@outside.org']], $this->proofLog());
    }

    public function testWithTheSettingAProofGoesStraightToMailpit(): void
    {
        $this->proofsTo('127.0.0.1:1025');
        $muid = $this->messageWithTemplate();
        $counts = fn(): array => array_map('intval', $this->db->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM subscribers) AS s, (SELECT COUNT(*) FROM smlog) AS sml, (SELECT COUNT(*) FROM queue) AS q,
                    (SELECT COUNT(*) FROM cattomail_runs) AS runs, (SELECT COUNT(*) FROM cattomail_recipients) AS staged'
        ) ?: []);
        $before = $counts();

        $outcome = $this->service(MessageService::class)->sendProof($muid, 'tester@outside.org');

        self::assertSame(SendOutcome::SUBMITTED, $outcome->status);
        self::assertSame([1, 1], [$outcome->staged, $outcome->handedOff]);
        // Mailpit's SMTP listener was the destination: plain SMTP, no credentials, no TLS negotiation.
        self::assertSame([['host' => '127.0.0.1', 'port' => 1025, 'options' => ['auto_tls' => 'false', 'user' => null]]], $this->mailpit->connections);
        self::assertCount(1, $this->mailpit->messages);
        $proof = $this->mailpit->messages[0];
        self::assertSame('tester@outside.org', $proof->getTo()[0]->getAddress());
        self::assertSame(['sender@ctnlist.test', 'Sender'], [$proof->getFrom()[0]->getAddress(), $proof->getFrom()[0]->getName()], 'the message\'s own sender');
        self::assertSame('Proof of the template', $proof->getSubject());
        // The same merge as through the mail service: template around the message, test values, proof links, no tracking pixel.
        $html = (string) $proof->getHtmlBody();
        self::assertSame('<div class="frame"><h1>ctnlist proofs</h1><p>Hello Test, this is the body.</p><p>UNSUBSCRIBE</p></div>', preg_replace('#<a [^>]*>(.*?)</a>#', '$1', $html));
        self::assertStringContainsString('/proof-link">UNSUBSCRIBE</a>', $html, 'links that act for a subscriber explain that proofs have none');
        self::assertStringNotContainsString('/ut/', $html);
        self::assertSame("ctnlist proofs\n\nHello Test, this is the body.\n", $proof->getTextBody());
        // The mail service was not asked for anything, and nothing was staged for it.
        self::assertSame([], $this->fake->requests);
        self::assertSame($before, $counts(), 'no subscriber, smlog row, queue entry, send run or staged recipient');
        self::assertSame([['PROOF', 'tester@outside.org']], $this->proofLog(), 'logged as a proof, as a handoff to the mail service is');
    }

    public function testOnlyProofsTakeTheMailpitRoute(): void
    {
        $this->proofsTo('mailpit:1025');
        $list = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Resend me', [$list]);
        $subscriber = $this->createSubscriber('reader@example.com', 'Rea', 'Der');
        $this->setMembership($subscriber, $list, true);
        $service = $this->service(MessageService::class);

        // The proof goes to Mailpit; the mail service is not asked for anything.
        self::assertSame(SendOutcome::SUBMITTED, $service->sendProof($muid, 'tester@outside.org')->status);
        self::assertSame('mailpit', $this->mailpit->connections[0]['host']);
        self::assertSame([], $this->fake->requests);

        // A resend is campaign mail to a subscriber: through the mail service, whatever the proof setting says.
        $resent = $service->sendTo($muid, 'reader@example.com');
        self::assertSame(SendOutcome::SUBMITTED, $resent->status, (string) $resent->problem);
        self::assertCount(1, $this->fake->sendJobs);
        self::assertSame('reader@example.com', $this->fake->recipients[$this->fake->lastSendJobId()][0]['email_address']);
        self::assertCount(1, $this->mailpit->messages, 'only the proof went to Mailpit');

        // The mail service's own end-to-end check sends its proof through the mail service on purpose.
        self::assertSame(SendOutcome::SUBMITTED, $service->sendProof($muid, 'tester@outside.org', throughMailService: true)->status);
        self::assertCount(2, $this->fake->sendJobs);
        self::assertSame('tester@outside.org', $this->fake->recipients[$this->fake->lastSendJobId()][0]['email_address']);
        self::assertCount(1, $this->mailpit->messages);
    }

    public function testAProofThatMailpitDoesNotTakeIsReportedAndNotLogged(): void
    {
        $this->proofsTo('127.0.0.1:1025');
        $muid = $this->messageWithTemplate();
        $this->mailpit->reachable = false;

        $outcome = $this->service(MessageService::class)->sendProof($muid, 'tester@outside.org');

        self::assertSame(SendOutcome::FAILED, $outcome->status, 'nothing is kept for a retry: the administrator sends the proof again');
        self::assertSame('Mailpit could not be reached at 127.0.0.1:1025 (MAIL_PROOF_SMTP).', $outcome->problem);
        self::assertSame([], $this->proofLog(), 'no handoff, no Send Log entry');
        self::assertSame([], $this->fake->requests, 'and it does not fall back to the mail service');
    }

    public function testAnUnusableSettingIsSaidSoInsteadOfFallingBack(): void
    {
        foreach (['mailpit', 'smtp://mailpit:1025', '127.0.0.1:0', '127.0.0.1:70000', 'user:secret@mailpit:1025', 'mail pit:1025'] as $value) {
            self::assertNull(ProofMailer::parse($value), $value);
        }
        self::assertSame(['host' => 'mailpit.internal', 'port' => 1025], ProofMailer::parse(' Mailpit.Internal:1025 '));
        self::assertSame(['host' => '::1', 'port' => 2525], ProofMailer::parse('[::1]:2525'));

        $proofs = $this->proofsTo('smtp://mailpit:1025');
        self::assertTrue($proofs->enabled());
        self::assertStringContainsString('host and port of Mailpit\'s SMTP listener', (string) $proofs->problem());
        $outcome = $this->service(MessageService::class)->sendProof($this->messageWithTemplate(), 'tester@outside.org');
        self::assertSame(SendOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('MAIL_PROOF_SMTP must be the host and port', (string) $outcome->problem);
        self::assertSame([], $this->mailpit->connections);
        self::assertSame([], $this->fake->requests);

        // Which addresses are on this server (diagnose warns about the others in production).
        foreach (['127.0.0.1:1025' => true, 'localhost:1025' => true, '[::1]:1025' => true, 'mailpit:1025' => false, '10.0.0.5:1025' => false] as $value => $local) {
            self::assertSame($local, (new ProofMailer($value, $this->connections(), $this->service(SendLog::class)))->local(), $value);
        }
        self::assertFalse((new ProofMailer('', $this->connections(), $this->service(SendLog::class)))->enabled());
        self::assertFalse((new ProofMailer(null, $this->connections(), $this->service(SendLog::class)))->enabled());
    }

    // ---- helpers ---------------------------------------------------------------

    /** Route proofs as MAIL_PROOF_SMTP=$setting would, with Mailpit's SMTP listener captured. Call before fetching MessageService. */
    private function proofsTo(string $setting): ProofMailer
    {
        $proofs = new ProofMailer($setting, $this->connections(), $this->service(SendLog::class));
        self::getContainer()->set(ProofMailer::class, $proofs);
        return $proofs;
    }

    private function connections(): MailConnectionFactory
    {
        return new MailConnectionFactory(fn(): MailConnection => new MailConnection(new Transport([$this->mailpit]), new NullLogger()));
    }

    /** A message in a template: {content} is where the message goes. */
    private function messageWithTemplate(): string
    {
        $template = $this->service(TemplateRepository::class)->create('Frame',
            '<div class="frame"><h1>{listname} proofs</h1>{content}<p>{unsubscribe}</p>{usertrack}</div>', "{listname} proofs\n\n{content}\n");
        $muid = $this->createMessage('Proof of the template', [$this->createList('PRF', 'Proofs')]);
        $this->db->executeStatement('UPDATE messages SET m_t_id = ?, m_html = ?, m_text = ? WHERE m_uniqid = ?',
            [$template, '<p>Hello {firstname}, this is the body.</p>', 'Hello {firstname}, this is the body.', $muid]);
        // The site is called "ctnlist" here whatever the development Settings say.
        $this->db->executeStatement("INSERT INTO options (o_key, o_value) VALUES ('setting:APP_LIST_NAME', 'ctnlist') ON CONFLICT (o_key) DO UPDATE SET o_value = EXCLUDED.o_value");
        return $muid;
    }

    /** @return list<array{0: string, 1: string}> the Send Log's proof rows: type and address */
    private function proofLog(): array
    {
        return array_map(static fn(array $row): array => [(string) $row['sl_type'], (string) $row['sl_email']],
            $this->db->fetchAllAssociative("SELECT sl_type, sl_email FROM sendlog WHERE sl_type = 'PROOF' ORDER BY sl_id"));
    }
}
