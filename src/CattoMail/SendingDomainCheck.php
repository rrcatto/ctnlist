<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SiteConfig;
use App\Repository\OptionRepository;
use Psr\Clock\ClockInterface;

/**
 * "Can the site's From address send?": catto-mail sends only from the
 * client's registered sending domains, and tells an application which of
 * them can send now (GET /v1/sending-domains, `can_send`). The last answer is
 * kept, so the Delivery page can warn without calling catto-mail on every
 * view; it is refreshed when a connection is made and by "Check connection".
 *
 * @phpstan-type SendingDomain array{domain: string, status: string, dkim_status: string, can_send: bool}
 */
final class SendingDomainCheck
{
    public const OK = 'ok';
    public const CANNOT_SEND = 'cannot_send';
    public const NOT_REGISTERED = 'not_registered';
    public const UNCHECKED = 'unchecked';
    public const NO_FROM_ADDRESS = 'no_from_address';

    private const KEY = 'cattomail:sending-domains';

    public function __construct(
        private readonly CattoMailClient $client,
        private readonly OptionRepository $options,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ask catto-mail and keep the answer.
     *
     * @throws CattoMailException when catto-mail cannot be asked
     */
    public function refresh(): void
    {
        $this->remember($this->client->listSendingDomains());
    }

    /** @param list<SendingDomain> $domains */
    public function remember(array $domains): void
    {
        $this->options->set(self::KEY, (string) json_encode(['checked_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'domains' => $domains]));
    }

    public function forget(): void
    {
        $this->options->delete(self::KEY);
    }

    /**
     * The last answer, if any.
     *
     * @return array{checked_at: string, domains: list<SendingDomain>}|null
     */
    public function last(): ?array
    {
        $stored = json_decode((string) $this->options->get(self::KEY), true);
        if (!is_array($stored) || !is_array($stored['domains'] ?? null)) {
            return null;
        }
        return ['checked_at' => (string) ($stored['checked_at'] ?? ''), 'domains' => CattoMailClient::sendingDomains($stored['domains'])];
    }

    /**
     * Whether the site's From address (MAIL_FROM_ADDRESS) can send, by the
     * last answer. A message or list with its own sender needs its domain
     * registered in the same way.
     *
     * @return array{state: string, domain: string, checked_at: string, status: string, dkim_status: string}
     */
    public function fromAddress(): array
    {
        $at = strrpos($this->site->fromAddress, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($this->site->fromAddress, $at + 1)));
        $result = ['state' => self::NO_FROM_ADDRESS, 'domain' => $domain, 'checked_at' => '', 'status' => '', 'dkim_status' => ''];
        if ($domain === '') {
            return $result;
        }
        $last = $this->last();
        if ($last === null) {
            return ['state' => self::UNCHECKED] + $result;
        }
        $result['checked_at'] = $last['checked_at'];
        foreach ($last['domains'] as $registered) {
            if ($registered['domain'] === $domain) {
                return ['state' => $registered['can_send'] ? self::OK : self::CANNOT_SEND, 'status' => $registered['status'], 'dkim_status' => $registered['dkim_status']] + $result;
            }
        }
        return ['state' => self::NOT_REGISTERED] + $result;
    }
}
