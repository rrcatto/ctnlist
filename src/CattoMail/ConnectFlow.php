<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SettingsCipher;
use App\Config\SettingsCipherException;
use App\Config\SiteConfig;
use App\Repository\OptionRepository;
use App\Security\SubscriberUser;
use App\Validator\InvalidField;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Connecting this installation to a mail service, and disconnecting it, with
 * catto-mail's connect flow (its integration guide, section 15: an
 * authorisation-code flow with PKCE, S256):
 *
 * 1. start(): an administrator connects to the mail service's address (the
 *    one configured, or the one they give when none is: it is then kept). A
 *    random state and code verifier are kept here (the verifier encrypted), and
 *    the administrator's browser goes to the service's connect page with the
 *    state, the verifier's SHA-256 challenge, where to come back to, where this
 *    installation receives webhooks and the domain it sends from (the site's
 *    From address: approving adds it to the account there).
 * 2. The administrator signs in there and approves; the service sends the
 *    browser back with a single-use code and the state.
 * 3. complete(): the state is checked, the code is exchanged from this
 *    server with the verifier, and what the service hands over once (the API
 *    key and the webhook signing secret) goes into the encrypted Settings
 *    store (ConnectionStore). No key or secret is ever in an address, a log
 *    or a page.
 *
 * disconnect() asks the service to end the connection (it revokes the key
 * and removes the webhook endpoint) and only then clears the stored values.
 *
 * Every refusal is an \InvalidArgumentException with a message for the
 * administrator.
 */
final class ConnectFlow
{
    /**
     * How long an administrator has to sign in (or open an account) and
     * approve at the mail service; its code then lives 10 minutes.
     */
    public const PENDING_SECONDS = 3600;

    private const PENDING = 'cattomail:connect:pending';
    private const CHANGED = 'cattomail:connection:changed';
    /** catto-mail's limit for application_name. */
    private const NAME_MAX = 100;

    public function __construct(
        private readonly CattoMailClient $client,
        private readonly CattoMailConfig $config,
        private readonly ConnectionStore $store,
        private readonly SendingDomainCheck $domains,
        private readonly OptionRepository $options,
        private readonly SettingsCipher $cipher,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /** Why a connection cannot be made from here, or null when it can. */
    public function obstacle(): ?string
    {
        $env = $this->config->envSettings();
        if ($env !== []) {
            return 'This installation\'s .env sets ' . implode(', ', $env) . ', the mail service\'s key or secret. A value in .env takes precedence, so a connection made here would not be used: remove '
                . (count($env) === 1 ? 'that setting' : 'those settings') . ' from .env first.';
        }
        $problem = $this->cipher->problem();
        if ($problem !== null) {
            return 'The API key and the webhook secret of a connection are stored encrypted, and that is not possible yet: ' . $problem;
        }
        if (!$this->development() && !self::isHttps($this->webhookUrl())) {
            return 'The mail service delivers webhooks only to https addresses, and this site\'s address (APP_BASE_URL) is not one.';
        }
        return null;
    }

    /** The mail service's address (its origin) as configured, or '' when none is known yet. */
    public function address(): string
    {
        return $this->config->address();
    }

    /** The domain this site sends from (that of its From address), as the connect page is told; '' when there is none. */
    public function sendingDomain(): string
    {
        $at = strrpos($this->site->fromAddress, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($this->site->fromAddress, $at + 1)));
        return preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) === 1 ? $domain : '';
    }

    /** Where this installation receives catto-mail's webhooks. */
    public function webhookUrl(): string
    {
        return $this->config->webhookUrl !== '' ? $this->config->webhookUrl : $this->site->baseUrl . 'cattomail/webhook';
    }

    /**
     * Begin a connection: returns the address at the mail service to send
     * the administrator's browser to.
     *
     * @param string $service the mail service's address as the administrator typed it, or '' for the configured one
     * @param string $redirectUri this installation's callback address, on the site the administrator is using
     * @param string $return where the administrator goes once connected: 'delivery' or 'setup' (Set up sending)
     * @throws \InvalidArgumentException
     */
    public function start(string $service, string $redirectUri, SubscriberUser $admin, string $return = 'delivery'): string
    {
        $obstacle = $this->obstacle();
        if ($obstacle !== null) {
            throw new \InvalidArgumentException($obstacle);
        }
        $origin = self::serviceOrigin(trim($service) !== '' ? $service : $this->address());
        if (!$this->development() && !self::isHttps($redirectUri)) {
            throw new \InvalidArgumentException('The mail service sends an administrator back only to an https address: open this site over https and connect from there.');
        }
        $state = self::token();
        $verifier = self::token();
        $webhookUrl = $this->webhookUrl();
        try {
            $this->options->set(self::PENDING, (string) json_encode([
                'state' => $state,
                'verifier' => $this->cipher->encrypt($verifier),
                'service' => $origin,
                'redirect_uri' => $redirectUri,
                'webhook_url' => $webhookUrl,
                'admin' => $admin->uuid,
                'return' => $return === 'setup' ? 'setup' : 'delivery',
                'expires_at' => $this->clock->now()->getTimestamp() + self::PENDING_SECONDS,
            ], JSON_UNESCAPED_SLASHES));
            // An address typed while none was known is kept: the next attempt needs no typing.
            if ($this->store->address() === '' && !$this->store->isConnected()) {
                $this->store->rememberAddress($origin, $admin->id);
            }
        } catch (SettingsCipherException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        $this->logger->notice('catto-mail connection to {service} started by {admin}.', ['service' => $origin, 'admin' => $admin->uuid]);
        return $origin . '/dashboard/connect?' . http_build_query([
            'application_name' => $this->applicationName(),
            'redirect_uri' => $redirectUri,
            'webhook_url' => $webhookUrl,
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ] + ($this->sendingDomain() !== '' ? ['sending_domain' => $this->sendingDomain()] : []), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The connection request that waits for the mail service's answer, if
     * one is still valid (nothing secret).
     *
     * @return array{service: string, state: string, admin: string, expires_at: int, return: string}|null
     */
    public function pending(): ?array
    {
        $pending = $this->readPending();
        return $pending === null ? null : ['service' => $pending['service'], 'state' => $pending['state'], 'admin' => $pending['admin'], 'expires_at' => $pending['expires_at'],
            'return' => $pending['return']];
    }

    /** Whether $state is the state of the request that waits here, started by this administrator. */
    public function expects(string $state, SubscriberUser $admin): bool
    {
        $pending = $this->readPending();
        return $pending !== null && $state !== '' && hash_equals($pending['state'], $state) && hash_equals($pending['admin'], $admin->uuid);
    }

    /**
     * Finish a connection with the code the mail service sent back: exchange
     * it and store what the service hands over.
     *
     * @return array{service: string, client_name: string, application_name: string, return: string} what was connected, and where the administrator goes next
     * @throws \InvalidArgumentException
     */
    public function complete(string $state, #[\SensitiveParameter] string $code, SubscriberUser $admin): array
    {
        $pending = $this->readPending();
        if ($pending === null) {
            throw new \InvalidArgumentException('No connection request is waiting here: it was finished already, or more than an hour has passed. Start again.');
        }
        if (!$this->expects($state, $admin)) {
            throw new \InvalidArgumentException('That answer does not belong to the connection request this administrator started here. Start again.');
        }
        if (preg_match('/^[\x21-\x7E]{1,512}$/', $code) !== 1) {
            throw new \InvalidArgumentException('The mail service\'s answer carried no usable code. Start again.');
        }
        try {
            $answer = $this->client->exchangeConnectCode($pending['service'], $code, $this->cipher->decrypt($pending['verifier']), $pending['redirect_uri']);
        } catch (SettingsCipherException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (CattoMailRejected $e) {
            if ($e->status === 409) {
                // catto-mail's connect-refused: nothing was created there, and the code stays usable until it expires.
                throw new \InvalidArgumentException('The mail service refused the connection for now (' . $e->getMessage() . '). Put that right at the mail service, then press Finish again.', 0, $e);
            }
            // One answer for every code that is not accepted (used, expired, another request's): only a new request helps.
            $this->options->delete(self::PENDING);
            throw new \InvalidArgumentException('The mail service did not accept the approval: its code was used already, is older than 10 minutes, or was not issued for this request. Start again.', 0, $e);
        } catch (CattoMailException $e) {
            throw new \InvalidArgumentException('The mail service could not be reached to finish the connection (' . $e->getMessage() . '). The approval stays valid for a few minutes: press Finish again.', 0, $e);
        }
        $apiKey = self::text($answer['api_key'] ?? null);
        $webhook = is_array($answer['webhook'] ?? null) ? $answer['webhook'] : [];
        $secret = self::text($webhook['signing_secret'] ?? null);
        $baseUrl = rtrim(self::text($answer['api_base_url'] ?? null), '/');
        if ($apiKey === '' || $secret === '' || !self::isHttps($baseUrl) || parse_url($baseUrl, PHP_URL_USER) !== null) {
            $this->options->delete(self::PENDING);
            throw new \InvalidArgumentException('The mail service answered, but not as its API contract describes, so nothing was stored. End the connection in the mail service\'s dashboard and start again.');
        }
        $details = [
            'connection_id' => self::text($answer['connection_id'] ?? null),
            'application_name' => self::text($answer['application_name'] ?? null),
            'client_name' => self::text($answer['client_name'] ?? null),
            'service' => $pending['service'],
            'webhook_url' => self::text($webhook['url'] ?? null) ?: $pending['webhook_url'],
            'webhook_endpoint_id' => self::text($webhook['endpoint_id'] ?? null),
        ];
        try {
            // The address is kept as a person gives it (the origin); the API root is derived from it.
            $this->store->save(preg_replace('#/v1$#', '', $baseUrl) ?? $baseUrl, $apiKey, $secret, $details, $admin->id);
        } catch (SettingsCipherException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        $this->options->delete(self::PENDING);
        $this->domains->remember(CattoMailClient::sendingDomains($answer['sending_domains'] ?? null));
        $this->changed();
        // Names only: the key and the secret are never logged.
        $this->logger->notice('catto-mail connected to {service} (connection {connection}, client "{client}") by {admin}.',
            ['service' => $pending['service'], 'connection' => $details['connection_id'], 'client' => $details['client_name'], 'admin' => $admin->uuid]);
        return ['service' => $pending['service'], 'client_name' => $details['client_name'], 'application_name' => $details['application_name'], 'return' => $pending['return']];
    }

    /**
     * End the connection: the mail service revokes the key and removes the
     * webhook endpoint, then the stored values are cleared. With $forget the
     * stored values are cleared without asking the service (for a service
     * that no longer exists, or a stored key that can no longer be read):
     * the key then stays valid there until it is revoked in its dashboard.
     *
     * @throws \InvalidArgumentException
     */
    public function disconnect(SubscriberUser $admin, bool $forget = false): void
    {
        $stored = $this->store->credentials();
        if ($stored === null) {
            throw new \InvalidArgumentException('No connection is stored here.');
        }
        if (!$forget) {
            if ($stored['problem'] !== null) {
                throw new \InvalidArgumentException('The stored API key cannot be read (' . $stored['problem'] . '), so the connection cannot be ended from here. End it in the mail service\'s dashboard, then forget it here.');
            }
            try {
                $this->client->deleteConnection(CattoMailConfig::rootOf($stored['base_url']), $stored['api_key']);
            } catch (CattoMailRejected $e) {
                // 401: the key is no longer accepted, so the connection is already ended there.
                if ($e->status !== 401) {
                    throw new \InvalidArgumentException('The mail service did not end the connection (' . $e->getMessage() . '). Nothing was changed here.', 0, $e);
                }
            } catch (CattoMailException $e) {
                throw new \InvalidArgumentException('The mail service could not be reached, so the connection was not ended and nothing was changed here (' . $e->getMessage() . ').', 0, $e);
            }
        }
        $this->store->clear();
        $this->domains->forget();
        $this->changed();
        $this->logger->notice('catto-mail connection {action} by {admin}.', ['action' => $forget ? 'forgotten (not revoked at the mail service)' : 'ended', 'admin' => $admin->uuid]);
    }

    /** When the stored connection last changed (Y-m-d H:i:s), if it ever did. */
    public function changedAt(): ?string
    {
        return $this->options->get(self::CHANGED);
    }

    /**
     * The origin (https://host[:port]) of the address an administrator gave
     * for the mail service; anything after the host is dropped, so the
     * address of any of its pages will do.
     *
     * @throws InvalidField
     */
    public static function serviceOrigin(string $input): string
    {
        $input = trim($input);
        $parts = parse_url(str_contains($input, '://') ? $input : 'https://' . $input);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || preg_match('/[\x00-\x20\x7F]/', $input) === 1 || preg_match('/^[a-z0-9.\-\[\]:]+$/', $host) !== 1) {
            throw new InvalidField('service', 'Enter the mail service\'s address, for example https://mail.example.org.');
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidField('service', 'The mail service\'s address must start with https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidField('service', 'The address must not contain a user name or password.');
        }
        return 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** The PKCE code challenge (S256) of a code verifier: BASE64URL(SHA-256(verifier)), unpadded. */
    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** What the person approving sees, and what the API key is named after at the mail service. */
    private function applicationName(): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $this->site->listName));
        return mb_substr($name !== '' ? $name : $this->site->domain, 0, self::NAME_MAX);
    }

    /** @return array{state: string, verifier: string, service: string, redirect_uri: string, webhook_url: string, admin: string, expires_at: int, return: string}|null */
    private function readPending(): ?array
    {
        $pending = json_decode((string) $this->options->get(self::PENDING), true);
        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < $this->clock->now()->getTimestamp()) {
            return null;
        }
        $fields = [];
        foreach (['state', 'verifier', 'service', 'redirect_uri', 'webhook_url', 'admin'] as $name) {
            $fields[$name] = self::text($pending[$name] ?? null);
            if ($fields[$name] === '') {
                return null;
            }
        }
        return ['state' => $fields['state'], 'verifier' => $fields['verifier'], 'service' => $fields['service'], 'redirect_uri' => $fields['redirect_uri'],
            'webhook_url' => $fields['webhook_url'], 'admin' => $fields['admin'], 'expires_at' => (int) $pending['expires_at'],
            'return' => ($pending['return'] ?? '') === 'setup' ? 'setup' : 'delivery'];
    }

    private function changed(): void
    {
        $this->options->set(self::CHANGED, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    private function development(): bool
    {
        return in_array($this->environment, ['dev', 'test'], true);
    }

    private static function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://') && is_string(parse_url($url, PHP_URL_HOST));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** 43 characters of A-Z a-z 0-9 - _ (256 random bits): a state, or a PKCE code verifier. */
    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
