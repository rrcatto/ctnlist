<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SiteConfig;
use App\Repository\OptionRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Settings › Set up sending (owner decision 2026-10-27): the four steps from nothing to this
 * site's first message through the mail service, each done or not, from what is already known
 * here (nothing is asked of the mail service to draw it):
 *
 *   account   the mail service's address is known (.env, the Settings page, or given here);
 *             whether an account exists there only Connect can tell, and Connect can open one
 *             where the service accepts new accounts;
 *   connect   the API can be used (a connection, or a key in .env);
 *   domain    the domain of the site's From address can send, by the mail service's last answer
 *             (SendingDomainCheck; the worker asks again every minute while it cannot);
 *   test      a test message went to the mail service and was accepted: the one sent from here
 *             (sendTest), or any earlier send job the mail service took.
 */
final class SendingSetup
{
    public const STEPS = ['account', 'connect', 'domain', 'test'];
    /** When and to whom the last test from here was accepted (JSON, no secret). */
    private const TEST_SENT = 'cattomail:setup:test-sent';

    public function __construct(
        private readonly CattoMailConfig $config,
        private readonly SendingDomainCheck $domains,
        private readonly CattoMailSender $sender,
        private readonly OptionRepository $options,
        private readonly Connection $db,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Where the steps stand now (read from the database on every call).
     *
     * @phpstan-impure
     * @return array{steps: array<string, bool>, current: ?string, done: int, total: int, address: string,
     *     from_domain: array{state: string, domain: string, checked_at: string, status: string, dkim_status: string, setup_url: string},
     *     test: ?array{at: string, to: string}}
     */
    public function state(): array
    {
        $domain = $this->domains->fromAddress();
        $test = $this->lastTest();
        $steps = [
            'account' => $this->config->address() !== '',
            'connect' => $this->config->isConfigured(),
            'domain' => $domain['state'] === SendingDomainCheck::OK,
            'test' => $test !== null || $this->anyJobTaken(),
        ];
        // A step counts only after the ones before it: without a connection, an earlier answer about
        // the domain, or mail taken earlier, belongs to a connection that is no longer there.
        $current = null;
        foreach (self::STEPS as $step) {
            $steps[$step] = $steps[$step] && $current === null;
            if (!$steps[$step]) {
                $current ??= $step;
            }
        }
        return ['steps' => $steps, 'current' => $current, 'done' => count(array_filter($steps)), 'total' => count($steps),
            'address' => $this->config->address(), 'from_domain' => $domain, 'test' => $test];
    }

    /** Whether every step is done (the notice on administration pages goes away). */
    public function complete(): bool
    {
        return $this->state()['current'] === null;
    }

    /**
     * Send a short test message through the mail service to $email, as the site's From address
     * (transactional, no list). It is logged like a proof (Send Log, PROOF); proofs that otherwise
     * go to Mailpit (MAIL_PROOF_SMTP) do not change this: the point is the mail service.
     */
    public function sendTest(string $email): SendOutcome
    {
        $subject = 'Test message from ' . $this->site->listName;
        $text = "This message was sent by {$this->site->listName} ({$this->site->baseUrl}) through its mail service, to show that sending works.\n\n"
            . "Nothing needs to be done. If it reached you, the site's campaign mail can be sent.\n";
        $html = '<p>This message was sent by <strong>' . htmlspecialchars($this->site->listName) . '</strong> (' . htmlspecialchars($this->site->baseUrl)
            . ') through its mail service, to show that sending works.</p><p>Nothing needs to be done. If it reached you, the site’s campaign mail can be sent.</p>';
        $outcome = $this->sender->sendOne('proof', new OutgoingMessage('', '', '', OutgoingMessage::TRANSACTIONAL, $this->site->fromAddress, $this->site->fromName),
            new OutgoingRecipient($email, null, 'PROOF', $subject, $html, $text));
        if ($outcome->problem === null && $outcome->handedOff > 0) {
            $this->options->set(self::TEST_SENT, (string) json_encode(['at' => $this->clock->now()->format('Y-m-d H:i'), 'to' => $email], JSON_UNESCAPED_SLASHES));
            $this->logger->notice('Set up sending: a test message was accepted by the mail service.');
        }
        return $outcome;
    }

    /** @return array{at: string, to: string}|null */
    private function lastTest(): ?array
    {
        $stored = json_decode((string) $this->options->get(self::TEST_SENT), true);
        return is_array($stored) && is_string($stored['at'] ?? null) && is_string($stored['to'] ?? null) ? ['at' => $stored['at'], 'to' => $stored['to']] : null;
    }

    /** The mail service has taken a send job of this site before (a campaign or a proof that went there). */
    private function anyJobTaken(): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM cattomail_send_jobs WHERE csj_submitted_at IS NOT NULL LIMIT 1') !== false;
    }
}
