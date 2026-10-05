<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Mail\TransactionalMailer;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Administrator subscriber operations (v5 behaviour): the subscriber edit
 * form, bulk subscribe/unsubscribe, file import, the export files and
 * synchronisation from other installations. Adding never grants consent;
 * subscribers are invited to confirm.
 */
final class SubscriberAdmin
{
    /** Bulk unsubscribe scopes. */
    public const UNSUBSCRIBE_SCOPES = ['list', 'domain', 'bounce', 'spam'];

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly MembershipRepository $memberships,
        private readonly SubscriptionService $subscriptions,
        private readonly SuppressionChecker $suppression,
        private readonly Engagement $engagement,
        private readonly MessageLog $messageLog,
        private readonly TransactionalMailer $mailer,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.log_dir%')] private readonly string $logDir,
    ) {
    }

    /**
     * Save the subscriber form. An empty token adds a new subscriber
     * (administrators only). Administrators may also set the priority and add
     * a list membership (inviting the subscriber when it is new). As in v5 the
     * save counts as engagement: priority becomes the given priority + 100.
     *
     * @param array<string, mixed> $input
     * @return string the outcome shown to the user
     * @throws \InvalidArgumentException for an invalid address or unknown subscriber
     */
    public function save(string $token, array $input, string $muid, bool $administrator): string
    {
        $priority = (int) ($input['s_priority'] ?? 0);
        $listId = (int) ($input['list_id'] ?? 0);
        $new = trim($token) === '';
        if ($new) {
            $email = EmailNormaliser::normalise((string) ($input['s_email'] ?? ''));
            if (!EmailNormaliser::isValid($email)) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
            if (!$this->subscriptions->subscribe($email, $priority, $listId)) {
                throw new \InvalidArgumentException('The subscriber could not be added.');
            }
            $identity = $this->subscribers->findIdentityByEmail($email) ?? throw new \InvalidArgumentException('The subscriber could not be added.');
            if ($muid !== '') {
                $this->messageLog->record($identity['s_uuid'], $muid, MessageActivity::Subscribe);
            }
        } else {
            $identity = $this->subscribers->findIdentityByUuid($token) ?? throw new \InvalidArgumentException('Invalid subscriber identifier.');
        }

        $this->subscribers->updateProfile($identity['s_id'], array_map(
            static fn(string $field): string => trim((string) ($input[$field] ?? '')),
            ['s_fname' => 's_fname', 's_lname' => 's_lname', 's_gender' => 's_gender', 's_province' => 's_province', 's_country' => 's_country']
        ));
        if ($administrator && $listId > 0 && ($list = $this->lists->findById($listId)) !== null
            && $this->memberships->ensure($identity['s_id'], $listId)) {
            $this->mailer->sendListConfirmationInvitation($identity['s_email'], $identity['s_uuid'], $list['l_shortcode'], $list['l_name']);
        }
        $this->engagement->set($identity['s_uuid'], ($administrator ? $priority : 0) + 100);

        if (!$new) {
            if ($muid !== '') {
                $this->messageLog->record($identity['s_uuid'], $muid, MessageActivity::Update);
            }
            $this->notifyProfileUpdate($identity['s_uuid'], $muid);
        }
        return 'Subscriber saved.';
    }

    /** @return int the number subscribed */
    public function bulkSubscribe(string $input, int $priority, int $listId): int
    {
        $added = [];
        foreach (EmailNormaliser::extract($input) as $email) {
            if ($this->subscriptions->subscribe($email, $priority, $listId)) {
                $added[] = $email;
            }
        }
        $this->appendLog('emails_added.txt', $added, 'Number subscribed: ' . count($added));
        return count($added);
    }

    /**
     * Unsubscribe from one list, or record global suppressions: whole
     * domains (SPAM), bounces (BOUNCE-ADMIN) or spam complaints (SPAM-ADMIN).
     *
     * @return int the number processed
     * @throws \InvalidArgumentException for an unknown list or scope
     */
    public function bulkUnsubscribe(string $input, int $listId, string $reason, string $scope): int
    {
        if (!in_array($scope, self::UNSUBSCRIBE_SCOPES, true)) {
            throw new \InvalidArgumentException('Unknown scope.');
        }
        if ($scope === 'list' && $this->lists->findById($listId) === null) {
            throw new \InvalidArgumentException('The selected list does not exist.');
        }
        $count = 0;
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach (EmailNormaliser::extract($input) as $email) {
            $done = match ($scope) {
                'domain' => $this->suppression->suppressDomain(EmailNormaliser::domain($email), 'SPAM'),
                'bounce' => $this->suppression->suppressEmail($email, 'BOUNCE-ADMIN', $reason),
                'spam' => $this->suppression->suppressEmail($email, 'SPAM-ADMIN', $reason),
                default => $this->unsubscribeFromList($email, $listId, $reason, $now),
            };
            $count += $done ? 1 : 0;
        }
        return $count;
    }

    /**
     * Write the two v5 export files to the installation's logs/ directory.
     *
     * @return list<string> one line per file
     */
    public function export(int $offset, int $limit, int $listId): array
    {
        $report = [];
        foreach (['export-subscribers.txt' => true, 'export-remove.txt' => false] as $file => $eligible) {
            $emails = $this->subscribers->exportEmails($eligible, $offset, $limit, $listId);
            $written = $this->write($file, implode('', array_map(static fn(string $e): string => $e . PHP_EOL, $emails)), false);
            $report[] = $written ? "{$file} - export complete (" . count($emails) . ' addresses).' : 'Unable to create export file.';
        }
        return $report;
    }

    /**
     * v5 synchronisation: add engaged subscribers of the other installations
     * in SYNC_DATABASES_JSON (active entries, not this domain) as pending
     * members of the entry's list, with priority + 1000000.
     *
     * @return int the number added or updated
     */
    public function synchronise(): int
    {
        $total = 0;
        foreach ($this->site->syncDatabases as $server) {
            if ((int) ($server['active'] ?? 0) !== 1 || (string) ($server['domain'] ?? '') === $this->site->domain) {
                continue;
            }
            $list = $this->lists->resolve((string) ($server['list_shortcode'] ?? 'ALL'));
            $pdo = $list === null ? null : $this->connect($server);
            if ($pdo === null) {
                continue;
            }
            $rows = $pdo->query('SELECT s_email, s_priority FROM subscribers WHERE s_last_interacted IS NOT NULL
                ORDER BY s_last_interacted DESC, s_priority DESC, s_email ASC LIMIT 5000000');
            foreach ($rows ?: [] as $row) {
                if ($this->subscriptions->subscribe((string) $row['s_email'], (int) $row['s_priority'] + 1000000, $list['l_id'])) {
                    $total++;
                }
            }
        }
        return $total;
    }

    private function unsubscribeFromList(string $email, int $listId, string $reason, string $now): bool
    {
        $identity = $this->subscribers->findIdentityByEmail($email);
        if ($identity === null) {
            return false;
        }
        $this->memberships->unsubscribe($identity['s_id'], $listId, $reason, $now);
        return true;
    }

    private function notifyProfileUpdate(string $uuid, string $muid): void
    {
        $identity = $this->subscribers->findIdentityByUuid($uuid);
        if ($identity === null) {
            return;
        }
        $list = $this->site->listName;
        $url = rtrim($this->site->baseUrl, '/') . '/subscribe/' . rawurlencode($uuid) . ($muid !== '' ? '/' . rawurlencode($muid) : '');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->mailer->sendNotification(
            $muid, 'UPDATE-PROFILE', $this->site->fromAddress, $identity['s_email'], trim($identity['s_fname'] . ' ' . $identity['s_lname']),
            $identity['s_email'] . ' has updated their profile on ' . $list,
            '<p>Subscriber information for ' . $e($identity['s_email']) . ' on ' . $e($list) . ' has been updated.</p><p><a href="' . $e($url) . '">View your profile</a></p>',
            "Subscriber information for {$identity['s_email']} on {$list} has been updated.\n{$url}\n",
            $uuid,
        );
    }

    /** @param array<string, mixed> $server */
    private function connect(array $server): ?\PDO
    {
        $driver = strtolower((string) ($server['driver'] ?? 'pgsql'));
        $port = (int) ($server['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        $dsn = match ($driver) {
            'pgsql' => "pgsql:host={$server['host']};port={$port};dbname={$server['name']};sslmode=" . ($server['sslmode'] ?? 'prefer'),
            'mysql' => "mysql:host={$server['host']};port={$port};dbname={$server['name']};charset=" . ($server['charset'] ?? 'utf8mb4'),
            default => null,
        };
        if ($dsn === null) {
            return null;
        }
        try {
            return new \PDO($dsn, (string) ($server['user'] ?? ''), (string) ($server['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            $this->logger->error('Subscriber synchronisation could not connect to {host}: {message}', ['host' => $server['host'] ?? '', 'message' => $e->getMessage()]);
            return null;
        }
    }

    /** @param list<string> $lines */
    private function appendLog(string $file, array $lines, string $footer): void
    {
        $entry = "--------------\n" . $this->clock->now()->format('Y-m-d H:i:s') . "\n" . implode('', array_map(static fn(string $l): string => $l . "\n", $lines)) . $footer . "\n";
        $this->write($file, $entry, true);
    }

    private function write(string $file, string $contents, bool $append): bool
    {
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0770, true)) {
            return false;
        }
        $written = @file_put_contents($this->logDir . '/' . basename($file), $contents, $append ? FILE_APPEND | LOCK_EX : LOCK_EX);
        if ($written === false) {
            $this->logger->error('Could not write {file} in {dir}.', ['file' => $file, 'dir' => $this->logDir]);
        }
        return $written !== false;
    }
}
