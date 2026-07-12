<?php

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Application-owned mail wrapper.
 *
 * The public methods intentionally retain the legacy ctnlist interface while
 * the implementation uses Symfony Mailer instead of SwiftMailer.
 */
class mailer
{
    protected $fat;
    protected $BaseURL;
    protected $FromAddress;
    protected $Domain;
    protected $BounceAddress;
    protected $FromName;
    protected $AdminEmail;
    protected $AdminName;
    protected $ListName;
    protected $UnsubscribeAddress;
    protected $OrderFormSubject;
    protected $OrderEmail;
    protected $OrderFormName;
    protected $EmailsPerMinute;
    protected $default_smtp_server;

    private $cm_transport = null;
    private ?SymfonyMailer $cm_mailer = null;
    private float $lastSendAt = 0.0;

    public $errormsg;
    public $errorcode;

    public $subscriber;
    public $message;
    public $template;
    public $sendlog;
    public $smlog;

    public function __construct(
        Base $fat,
        SubscribersController $subscriber,
        MessagesController $message,
        TemplatesController $template,
        SendlogController $sendlog,
        SmlogController $smlog
    ) {
        $this->fat = $fat;
        $this->subscriber = $subscriber;
        $this->message = $message;
        $this->template = $template;
        $this->sendlog = $sendlog;
        $this->smlog = $smlog;

        $this->BaseURL = $fat->get('BaseURL');
        $this->FromName = $fat->get('FromName');
        $this->FromAddress = $fat->get('FromAddress');
        $this->Domain = $fat->get('Domain');
        $this->BounceAddress = $fat->get('BounceAddress');
        $this->AdminEmail = $fat->get('AdminEmail');
        $this->AdminName = $fat->get('AdminName');
        $this->ListName = $fat->get('ListName');
        $this->UnsubscribeAddress = $fat->get('UnsubscribeAddress');
        $this->OrderFormSubject = $fat->get('OrderFormSubject');
        $this->OrderEmail = $fat->get('OrderEmail');
        $this->OrderFormName = $fat->get('OrderFormName');
        $this->EmailsPerMinute = (int) $fat->get('EmailsPerMinute');
        $this->default_smtp_server = $fat->get('default_smtp_server');

        $this->errormsg = '';
        $this->errorcode = 0;
    }

    /**
     * Open the configured mail transport.
     *
     * Existing callers may still pass a legacy SMTP-server array. When no
     * explicit server is supplied, MAILER_DSN is preferred and the legacy
     * default SMTP configuration is used as a fallback.
     */
    public function OpenSMTP($smtp_server = ''): bool
    {
        $this->resetError();

        try {
            $dsn = '';

            if (is_array($smtp_server)) {
                if (($smtp_server['active'] ?? 0) != 1) {
                    return false;
                }
                $dsn = $this->buildLegacySmtpDsn($smtp_server);
                $this->EmailsPerMinute = (int) ($smtp_server['sendrate'] ?? $this->fat->get('EmailsPerMinute'));
            } elseif ($smtp_server === '') {
                $dsn = $this->env('MAILER_DSN');
                if ($dsn === '') {
                    if (!is_array($this->default_smtp_server) || (($this->default_smtp_server['active'] ?? 0) != 1)) {
                        $this->setError('No active mail transport is configured.');
                        return false;
                    }
                    $dsn = $this->buildLegacySmtpDsn($this->default_smtp_server);
                    $this->EmailsPerMinute = (int) ($this->default_smtp_server['sendrate'] ?? $this->fat->get('EmailsPerMinute'));
                }
            } else {
                $dsn = (string) $smtp_server;
            }

            if ($dsn === '') {
                $this->setError('The mail transport DSN is empty.');
                return false;
            }

            $this->cm_transport = Transport::fromDsn($dsn);

            // SMTP transports expose start()/stop(). Calling start() preserves
            // the legacy OpenSMTP() behaviour of detecting connection failures
            // before a message is built or queued for delivery.
            if (method_exists($this->cm_transport, 'start')) {
                $this->cm_transport->start();
            }

            $this->cm_mailer = new SymfonyMailer($this->cm_transport);
            $this->lastSendAt = 0.0;
            return true;
        } catch (\Throwable $e) {
            $this->captureException($e);
            $this->cm_transport = null;
            $this->cm_mailer = null;
            return false;
        }
    }

    public function CloseSMTP(): bool
    {
        if ($this->cm_transport === null) {
            return true;
        }

        try {
            if (method_exists($this->cm_transport, 'stop')) {
                $this->cm_transport->stop();
            }

            $this->cm_transport = null;
            $this->cm_mailer = null;
            $this->lastSendAt = 0.0;
            return true;
        } catch (\Throwable $e) {
            $this->captureException($e);
            $this->cm_transport = null;
            $this->cm_mailer = null;
            $this->lastSendAt = 0.0;
            return false;
        }
    }

    /**
     * Send a campaign message to the subscriber currently loaded by ctnlist.
     */
    public function SendMessage(): int
    {
        try {
            $suid = (string) $this->subscriber->subscriber->s_uniqid;
            $muid = (string) $this->message->message->m_uniqid;
            $mtype = 'MESSAGE';
            $configuredFrom = trim((string) $this->message->message->m_from_address);
            $toemail = trim((string) $this->subscriber->subscriber->s_email);
            $toname = trim((string) $this->subscriber->subscriber->s_fname . ' ' . (string) $this->subscriber->subscriber->s_lname);
            $listname = (string) $this->subscriber->subscriber->s_subscribedby;
            $subject = (string) $this->message->message->m_subject;
            $mhtml = (string) $this->template->shtml;
            $mtext = (string) $this->template->stext;

            $replyTo = $configuredFrom !== '' ? $configuredFrom : (string) $this->FromAddress;
            $sender = 'info+' . $suid . '@' . $this->Domain;
            $fromName = trim((string) $this->message->message->m_from_name);

            $email = (new Email())
                ->subject($subject)
                ->html($mhtml)
                ->text($mtext)
                ->replyTo($this->address($replyTo))
                ->from($this->address($replyTo, $fromName))
                ->sender($this->address($sender))
                ->to($this->address($toemail, $toname));

            if (trim((string) $this->BounceAddress) !== '') {
                $email->returnPath($this->address((string) $this->BounceAddress));
            }

            $headers = $email->getHeaders();
            $headers->addTextHeader('List-Id', $this->ListName . ' <' . $this->BaseURL . '>');
            $headers->addTextHeader('List-Unsubscribe', '<' . $this->BaseURL . 'unsubscribe/' . $suid . '/' . $muid . '>');
            $headers->addTextHeader('List-Subscribe', '<' . $this->BaseURL . 'subscribe?m=' . $muid . '>');
            $headers->addTextHeader('List-Post', 'NO');
            $headers->addTextHeader('List-Owner', '<mailto:' . $this->AdminEmail . '> (' . $this->AdminName . ')');
            $headers->addTextHeader('List-Archive', '<' . $this->BaseURL . 'archives>');
            $headers->addTextHeader('X-ctnlist-suid', $suid);
            $headers->addTextHeader('X-ctnlist-muid', $muid);

            $sent = $this->deliver($email);
            if ($sent > 0) {
                $this->sendlog->logSend($muid, $mtype, $toemail, $listname, $subject);
                $this->smlog->logMsgSent($suid, $muid);
            }
            return $sent;
        } catch (\Throwable $e) {
            $this->captureException($e);
            return 0;
        }
    }

    /**
     * Send an application notification and blind-copy the administrator.
     */
    public function SendNotification($muid, $mtype, $mfrom, $toemail, $toname, $subject, $mhtml, $mtext): int
    {
        try {
            $listname = (string) $this->subscriber->subscriber->s_subscribedby;
            $replyTo = trim((string) $mfrom) !== '' ? trim((string) $mfrom) : (string) $this->FromAddress;

            $email = (new Email())
                ->subject((string) $subject)
                ->html((string) $mhtml)
                ->text((string) $mtext)
                ->replyTo($this->address($replyTo))
                ->from($this->address($replyTo, (string) $this->FromName))
                ->sender($this->address($replyTo))
                ->to($this->address((string) $toemail, (string) $toname));

            if (trim((string) $this->BounceAddress) !== '') {
                $email->returnPath($this->address((string) $this->BounceAddress));
            }

            if (trim((string) $this->AdminEmail) !== '') {
                $email->bcc($this->address((string) $this->AdminEmail, (string) $this->AdminName));
            }

            $sent = $this->deliver($email);
            if ($sent > 0) {
                $this->sendlog->logSend((string) $muid, (string) $mtype, (string) $toemail, $listname, (string) $subject);
            }
            return $sent;
        } catch (\Throwable $e) {
            $this->captureException($e);
            return 0;
        }
    }

    /**
     * Send the legacy contact/order-form acknowledgement.
     */
    public function SendOrderMessage($toemail, $toname, $mhtml, $mtext): int
    {
        try {
            $valid = $this->subscriber->LoadSubscriber($toemail);
            $listname = $valid ? (string) $this->subscriber->subscriber->s_subscribedby : '';
            $suid = (string) $this->fat->get('POST.suid');
            $muid = (string) $this->fat->get('POST.muid');
            $subject = $toemail . ' - ' . $this->OrderFormSubject;

            $fromAddress = trim((string) $this->OrderEmail) !== '' ? (string) $this->OrderEmail : (string) $this->FromAddress;
            $fromName = trim((string) $this->OrderFormName) !== '' ? (string) $this->OrderFormName : (string) $this->FromName;

            $email = (new Email())
                ->subject($subject)
                ->html((string) $mhtml)
                ->text((string) $mtext)
                ->from($this->address($fromAddress, $fromName))
                ->to($this->address((string) $toemail, (string) $toname));

            if (trim((string) $this->OrderEmail) !== '') {
                $email->returnPath($this->address((string) $this->OrderEmail));
            }

            $bcc = [];
            if (trim((string) $this->AdminEmail) !== '') {
                $bcc[] = $this->address((string) $this->AdminEmail, (string) $this->AdminName);
            }
            if (trim((string) $this->OrderEmail) !== '') {
                $bcc[] = $this->address((string) $this->OrderEmail, (string) $this->OrderFormName);
            }
            if ($bcc !== []) {
                $email->bcc(...$bcc);
            }

            $sent = $this->deliver($email);
            if ($sent > 0) {
                $this->sendlog->logSend($muid, 'CONTACT', (string) $toemail, $listname, $subject);
                $this->smlog->logMsgBooking($suid, $muid);
                $this->subscriber->bumpPriority($suid, 100000);
            }
            return $sent;
        } catch (\Throwable $e) {
            $this->captureException($e);
            return 0;
        }
    }

    /**
     * Send a one-time passwordless login link.
     */
    public function SendMagicLink(string $toemail, string $loginUrl, int $ttlSeconds): int
    {
        try {
            $fromAddress = $this->env('MAIL_FROM_ADDRESS');
            if ($fromAddress === '') {
                $fromAddress = (string) $this->FromAddress;
            }

            $fromName = $this->env('MAIL_FROM_NAME');
            if ($fromName === '') {
                $fromName = (string) $this->FromName;
            }

            $minutes = max(1, (int) ceil($ttlSeconds / 60));
            $subject = $this->ListName . ' sign-in link';
            $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeListName = htmlspecialchars((string) $this->ListName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $html = '<p>Use the link below to sign in to ' . $safeListName . '.</p>'
                . '<p><a href="' . $safeUrl . '">Sign in to ' . $safeListName . '</a></p>'
                . '<p>This link expires in ' . $minutes . ' minutes and can be used only once.</p>'
                . '<p>If you did not request this link, you can ignore this email.</p>';

            $text = "Use this link to sign in to {$this->ListName}:\n\n{$loginUrl}\n\n"
                . "This link expires in {$minutes} minutes and can be used only once.\n"
                . "If you did not request this link, you can ignore this email.\n";

            $email = (new Email())
                ->subject($subject)
                ->from($this->address($fromAddress, $fromName))
                ->to($this->address($toemail))
                ->html($html)
                ->text($text);

            return $this->deliver($email);
        } catch (\Throwable $e) {
            $this->captureException($e);
            return 0;
        }
    }

    private function deliver(Email $email): int
    {
        if ($this->cm_mailer === null && !$this->OpenSMTP()) {
            return 0;
        }

        try {
            $this->throttle();
            $this->cm_mailer->send($email);
            $this->lastSendAt = microtime(true);
            return 1;
        } catch (\Throwable $e) {
            $this->captureException($e);
            return 0;
        }
    }

    private function throttle(): void
    {
        $perMinute = max(0, (int) $this->EmailsPerMinute);
        if ($perMinute === 0 || $this->lastSendAt <= 0.0) {
            return;
        }

        $minimumInterval = 60 / $perMinute;
        $elapsed = microtime(true) - $this->lastSendAt;
        if ($elapsed < $minimumInterval) {
            usleep((int) (($minimumInterval - $elapsed) * 1_000_000));
        }
    }

    private function address(string $email, string $name = ''): Address
    {
        $email = trim($email);
        $name = trim($name);
        return $name === '' ? new Address($email) : new Address($email, $name);
    }

    private function buildLegacySmtpDsn(array $smtpServer): string
    {
        $host = trim((string) ($smtpServer['host'] ?? ''));
        $port = (int) ($smtpServer['port'] ?? 25);
        $user = (string) ($smtpServer['user'] ?? '');
        $pass = (string) ($smtpServer['pass'] ?? '');
        $encryption = strtolower(trim((string) ($smtpServer['enc'] ?? '')));

        if ($host === '') {
            throw new \InvalidArgumentException('SMTP host is missing.');
        }

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $auth = '';
        if ($user !== '') {
            $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $dsn = $scheme . '://' . $auth . $host . ':' . $port;
        if ($encryption === 'tls') {
            $dsn .= '?require_tls=true';
        }

        return $dsn;
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private function resetError(): void
    {
        $this->errormsg = '';
        $this->errorcode = 0;
    }

    private function setError(string $message, int $code = 0): void
    {
        $this->errormsg = $message;
        $this->errorcode = $code;
    }

    private function captureException(\Throwable $e): void
    {
        $this->setError($e->getMessage(), (int) $e->getCode());
        error_log('ctnlist mail error: ' . $e->getMessage());
    }
}
