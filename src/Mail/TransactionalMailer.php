<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\SiteConfig;
use App\Log\SendLog;
use App\Util\Duration;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Application mail (not campaign content): notifications, sign-in links,
 * list confirmation invitations and contact-form mail. Each successful
 * handoff is written to the Send Log.
 */
final class TransactionalMailer
{
    public function __construct(
        private readonly SmtpServerPool $servers,
        private readonly MailConnectionFactory $connections,
        private readonly SendLog $sendLog,
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
            $intro = 'To confirm your subscription to ' . $confirmListName . ' on ' . $list . ', open the link below, press Continue on the page it opens, and then confirm. You are subscribed only once you confirm.';
            $action = 'Confirm my subscription';
        } else {
            $subject = $list . ' sign-in link';
            $intro = 'To sign in to ' . $list . ', open the link below, then press Sign in on the page it opens. Opening the link alone does not sign you in.';
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
     * The organisation's copy of a contact/order form: to the contact address
     * and the administrator, answered by replying to the person who wrote. It
     * carries the internal details of the submission (whose link was used,
     * from where), so it never goes to the submitter. False when there is
     * nobody to send it to or it could not be sent.
     */
    public function sendContactNotice(string $fromEmail, string $fromName, string $html, string $text, string $subscriberUuid, string $muid): bool
    {
        $to = [];
        foreach ([[$this->site->contactEmail, $this->site->contactName], [$this->site->adminEmail, $this->site->adminName]] as [$address, $name]) {
            if (trim($address) !== '') {
                $to[strtolower(trim($address))] ??= self::address($address, $name);
            }
        }
        if ($to === []) {
            return false;
        }
        $subject = 'Contact form: ' . $fromEmail;
        $email = $this->contactMail($subject, $html, $text)->to(...array_values($to))->replyTo(self::address($fromEmail, $fromName));
        if (!$this->deliver($email)) {
            return false;
        }
        foreach ($to as $address) {
            $this->sendLog->record($muid, 'CONTACT', $address->getAddress(), '', $subject, $subscriberUuid);
        }
        return true;
    }

    /** The acknowledgement to the person who filled in the contact/order form: what they wrote and nothing else. */
    public function sendContactAcknowledgement(string $toEmail, string $toName, string $html, string $text, string $subscriberUuid, string $muid): bool
    {
        $subject = $toEmail . ' - ' . $this->site->contactSubject;
        if (!$this->deliver($this->contactMail($subject, $html, $text)->to(self::address($toEmail, $toName)))) {
            return false;
        }
        $this->sendLog->record($muid, 'CONTACT', $toEmail, '', $subject, $subscriberUuid);
        return true;
    }

    /** Contact-form mail comes from the contact address, or else from the site's sender. */
    private function contactMail(string $subject, string $html, string $text): Email
    {
        $contact = trim($this->site->contactEmail);
        $email = (new Email())
            ->subject($subject)->html($html)->text($text)
            ->from(self::address($contact !== '' ? $contact : $this->site->fromAddress, trim($this->site->contactName) !== '' ? $this->site->contactName : $this->site->fromName));
        if ($contact !== '') {
            $email->returnPath(self::address($contact));
        }
        return $email;
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
