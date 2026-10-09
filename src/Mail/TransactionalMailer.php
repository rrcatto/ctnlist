<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\SiteConfig;
use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Log\SendLog;
use App\Repository\SubscriberRepository;
use App\Subscriber\Engagement;
use App\Util\Duration;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Application mail (not campaign content): notifications, sign-in links,
 * list confirmation invitations and contact acknowledgements. Each successful
 * handoff is written to the Send Log.
 */
final class TransactionalMailer
{
    public function __construct(
        private readonly SmtpServerPool $servers,
        private readonly MailConnectionFactory $connections,
        private readonly SendLog $sendLog,
        private readonly MessageLog $messageLog,
        private readonly SubscriberRepository $subscribers,
        private readonly Engagement $engagement,
        private readonly SiteConfig $site,
    ) {
    }

    /** A notification to a subscriber, blind-copied to the administrator. */
    public function sendNotification(
        string $muid,
        string $type,
        string $from,
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $subscriberUuid = null,
        string $listShortcode = '',
    ): bool {
        $replyTo = trim($from) !== '' ? trim($from) : $this->site->fromAddress;
        $email = (new Email())
            ->subject($subject)->html($html)->text($text)
            ->replyTo(self::address($replyTo))
            ->from(self::address($replyTo, $this->site->fromName))
            ->sender(self::address($replyTo))
            ->to(self::address($toEmail, $toName));
        $this->bounceTo($email);
        if (trim($this->site->adminEmail) !== '') {
            $email->bcc(self::address($this->site->adminEmail, $this->site->adminName));
        }
        if (!$this->deliver($email)) {
            return false;
        }
        $this->sendLog->record($muid, $type, $toEmail, $listShortcode, $subject, $subscriberUuid);
        return true;
    }

    /**
     * The one-time sign-in link. It opens a page whose button signs in
     * (SignInLinkPage), so the text asks for that choice rather than promising
     * that opening the link signs in. When it was requested to confirm a list
     * subscription ($confirmListName), the email says so: the link is the same
     * secure sign-in link and leads to that list's confirmation.
     */
    public function sendMagicLink(string $toEmail, string $subscriberUuid, string $loginUrl, int $ttlSeconds, ?string $confirmListName = null): bool
    {
        $lifetime = Duration::describe($ttlSeconds);
        $list = $this->site->listName;
        if ($confirmListName !== null) {
            $subject = 'Confirm your subscription to ' . $confirmListName;
            $intro = 'To confirm your subscription to ' . $confirmListName . ' on ' . $list . ', open the link below, choose Continue and then confirm. You are subscribed only once you confirm.';
            $action = 'Confirm my subscription';
        } else {
            $subject = $list . ' sign-in link';
            $intro = 'To sign in to ' . $list . ', open the link below and choose Sign in.';
            $action = 'Sign in to ' . $list;
        }
        $html = '<p>' . self::e($intro) . '</p>'
            . '<p><a href="' . self::e($loginUrl) . '">' . self::e($action) . '</a></p>'
            . '<p>This link expires in ' . $lifetime . ' and can be used only once.</p>'
            . '<p>If you did not request this link, you can ignore this email.</p>';
        $text = "{$intro}\n\n{$loginUrl}\n\n"
            . "This link expires in {$lifetime} and can be used only once.\n"
            . "If you did not request this link, you can ignore this email.\n";
        return $this->sendPlain($toEmail, $subject, $html, $text, 'MAGIC-LINK', '', $subscriberUuid);
    }

    /** Invite a subscriber to authenticate and explicitly confirm a list. */
    public function sendListConfirmationInvitation(string $toEmail, string $subscriberUuid, string $listShortcode, string $listName): bool
    {
        $shortcode = strtoupper($listShortcode);
        $url = rtrim($this->site->baseUrl, '/') . '/confirm/' . rawurlencode($subscriberUuid) . '/' . rawurlencode($shortcode);
        $subject = 'Confirm your subscription to ' . $listName;
        $html = '<p>You have been added as a pending member of <strong>' . self::e($listName) . '</strong>.</p>'
            . '<p><a href="' . self::e($url) . '">Authenticate and confirm this subscription</a></p>'
            . '<p>You will not receive bulk messages for this list until you explicitly confirm.</p>';
        $text = "You have been added as a pending member of {$listName}.\n\n"
            . "Authenticate and confirm: {$url}\n\n"
            . "You will not receive bulk messages for this list until you explicitly confirm.\n";
        return $this->sendPlain($toEmail, $subject, $html, $text, 'SUBSCRIBE', $shortcode, $subscriberUuid);
    }

    /**
     * Acknowledge a contact/order form, blind-copied to the administrator and
     * the contact address. Records a booking for the message when known and
     * raises the subscriber's priority. Returns the number of unique
     * recipients (0 on failure), as the v5 contact workflow expects.
     */
    public function sendContactAcknowledgement(string $toEmail, string $toName, string $html, string $text, string $subscriberUuid, string $muid): int
    {
        $subscriberUuid = trim($subscriberUuid);
        if ($subscriberUuid === '') {
            $subscriberUuid = (string) ($this->subscribers->findIdentityByEmail($toEmail)['s_uuid'] ?? '');
        }
        $subject = $toEmail . ' - ' . $this->site->contactSubject;
        $fromAddress = trim($this->site->contactEmail) !== '' ? $this->site->contactEmail : $this->site->fromAddress;
        $fromName = trim($this->site->contactName) !== '' ? $this->site->contactName : $this->site->fromName;

        $email = (new Email())
            ->subject($subject)->html($html)->text($text)
            ->from(self::address($fromAddress, $fromName))
            ->to(self::address($toEmail, $toName));
        if (trim($this->site->contactEmail) !== '') {
            $email->returnPath(self::address($this->site->contactEmail));
        }

        $recipients = [strtolower(trim($toEmail)) => true];
        $bcc = [];
        foreach ([[$this->site->adminEmail, $this->site->adminName], [$this->site->contactEmail, $this->site->contactName]] as [$address, $name]) {
            $key = strtolower(trim($address));
            if ($key !== '' && !isset($recipients[$key])) {
                $bcc[] = self::address($address, $name);
                $recipients[$key] = true;
            }
        }
        if ($bcc !== []) {
            $email->bcc(...$bcc);
        }

        if (!$this->deliver($email)) {
            return 0;
        }
        $this->sendLog->record($muid, 'CONTACT', $toEmail, '', $subject, $subscriberUuid);
        $this->messageLog->record($subscriberUuid, $muid, MessageActivity::Booking);
        if ($subscriberUuid !== '') {
            $this->engagement->bump($subscriberUuid, 100000);
        }
        return count($recipients);
    }

    private function sendPlain(string $toEmail, string $subject, string $html, string $text, string $type, string $listShortcode, string $subscriberUuid): bool
    {
        $email = (new Email())
            ->subject($subject)
            ->from(self::address($this->site->fromAddress, $this->site->fromName))
            ->to(self::address($toEmail))
            ->html($html)
            ->text($text);
        if (!$this->deliver($email)) {
            return false;
        }
        $this->sendLog->record('', $type, $toEmail, $listShortcode, $subject, $subscriberUuid);
        return true;
    }

    private function deliver(Email $email): bool
    {
        $server = $this->servers->transactionalServer();
        if ($server === null) {
            return false;
        }
        $connection = $this->connections->create();
        if (!$connection->open($server)) {
            return false;
        }
        try {
            return $connection->send($email);
        } finally {
            $connection->close();
        }
    }

    private function bounceTo(Email $email): void
    {
        if (trim($this->site->bounceAddress) !== '') {
            $email->returnPath(self::address($this->site->bounceAddress));
        }
    }

    /** A display name is cosmetic: control characters (e.g. line breaks typed into a form) become spaces rather than failing the mail. */
    private static function address(string $email, string $name = ''): Address
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
        return $name === '' ? new Address(trim($email)) : new Address(trim($email), $name);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
