<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Config\SiteConfig;
use App\Repository\TemplateRepository;

/**
 * Merges a message into its template and fills the v5 placeholders, either
 * for one subscriber (with list context) or anonymously (for archives).
 * Ported replacement-for-replacement from v5 MergeTemplate; placeholder
 * names are case-insensitive.
 *
 * @phpstan-import-type Message from \App\Repository\MessageRepository
 * @phpstan-import-type Recipient from \App\Repository\SubscriberRepository
 */
final class TemplateRenderer
{
    /** Merge values for proofs (no real subscriber; normal, not confirmation-level, subscription text). */
    public const PROOF_RECIPIENT = [
        's_id' => 0,
        's_uuid' => '00000000-0000-0000-0000-000000000000',
        's_email' => '',
        's_fname' => 'Test',
        's_lname' => 'Recipient',
        's_emailsleft' => PHP_INT_MAX,
        's_priority' => 0,
        's_last_interacted' => null,
    ];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly SiteConfig $site,
    ) {
    }

    /**
     * @param Message $message
     * @param Recipient|null $recipient null renders anonymously (archive copy)
     * @param string $listShortcode the delivery's list ('' for an audience-less proof)
     * @param bool $proof a proof copy (see renderProof()): subscriber-specific links go to /proof-link
     */
    public function render(array $message, ?array $recipient, string $listShortcode = '', bool $proof = false): RenderedMessage
    {
        $base = $this->site->baseUrl;
        // In a proof, links that act for a subscriber explain that proofs have no subscriber.
        $link = static fn(string $url): string => $proof ? $base . 'proof-link' : $url;
        $muid = $message['m_uniqid'];
        $list = strtoupper(trim($listShortcode));
        $archive = $message['m_a_id'];

        $template = $this->templates->find($message['m_t_id']);
        $html = $template === null ? $message['m_html'] : str_ireplace('{content}', $message['m_html'], $template['t_html']);
        $text = $template === null ? $message['m_text'] : str_ireplace('{content}', $message['m_text'], $template['t_text']);

        $r = static function (string $placeholder, string $htmlValue, ?string $textValue = null) use (&$html, &$text): void {
            $html = str_ireplace($placeholder, $htmlValue, $html);
            if ($textValue !== null) {
                $text = str_ireplace($placeholder, $textValue, $text);
            }
        };

        $r('{advertise}', '<a href="' . $this->site->advertiseUrl . '">ADVERTISE</a>', $this->site->advertiseUrl);
        $r('{facebook}', '<a href="' . $this->site->facebookUrl . '">FACEBOOK</a>', $this->site->facebookUrl);
        $r('{twitter}', '<a href="' . $this->site->xUrl . '">TWITTER</a>', $this->site->xUrl);
        // The Ecwid store is gone; old content that still contains {STORE} renders nothing.
        $r('{STORE}', '', '');

        $subscribeUrl = $base . 'subscribe?m=' . rawurlencode($muid) . ($list !== '' ? '&l=' . rawurlencode($list) : '');
        $r('{subscribe}', '<a href="' . htmlspecialchars($subscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">SUBSCRIBE</a>', $subscribeUrl);
        $r('{listname}', $this->site->listName, $this->site->listName);
        $r('{domain}', $this->site->domain, $this->site->domain);
        $r('{organisation}', $this->site->organisation, $this->site->organisation);
        $archiveLink = static fn() => $r('{archive}', '<a href="' . $base . 'archive/' . $archive . '">ARCHIVE</a>', $base . 'archive/' . $archive);

        // Order matters as in v5: values inserted later (e.g. the subscription
        // message) may themselves contain placeholders filled after them.
        if ($recipient === null) {
            $archiveLink();
            // v5 leaves {booking}, {contact}, {lms-booking}, {baseurl} and
            // {listshortcode} unreplaced in anonymous copies.
            foreach (['{unsubscribe}' => 'UNSUBSCRIBE', '{forward}' => 'FORWARD', '{preferences}' => 'UPDATE',
                '{firstname}' => '', '{lastname}' => '', '{subscription}' => '', '{emailsleft}' => '',
                '{confirm}' => 'OPT IN', '{like}' => 'YES', '{dislike}' => 'NO'] as $placeholder => $value) {
                $r($placeholder, $value, $value);
            }
            $r('{usertrack}', '');
            $r('{muid}', '', '');
            $r('{suid}', '', '');
            return new RenderedMessage($html, $text);
        }

        $suid = $recipient['s_uuid'];
        $subscription = $recipient['s_emailsleft'] <= $this->site->subscriptionConfirmLevel
            ? $this->site->subscriptionConfirmMessage
            : $this->site->subscriptionMessage;
        // Placeholders inside the subscription message are filled below.
        $html = str_ireplace('{subscription}', $subscription, $html);
        $text = str_ireplace('{subscription}', $subscription, $text);
        $archiveLink();

        if ($list !== '') {
            $unsubscribe = $link($base . 'unsubscribe/' . $suid . '/' . $list . '/' . $muid);
            $r('{unsubscribe}', '<a href="' . $unsubscribe . '">UNSUBSCRIBE</a>', $unsubscribe);
        } else {
            // A proof of an audience-less draft has no list to unsubscribe from.
            $r('{unsubscribe}', 'UNSUBSCRIBE', 'UNSUBSCRIBE');
        }
        $forward = $link($base . 'forward/' . $suid . '/' . $muid);
        $r('{forward}', '<a href="' . $forward . '">FORWARD</a>', $forward);
        $preferences = $link($base . 'profile/subscriber/' . $suid);
        $r('{preferences}', '<a href="' . $preferences . '">UPDATE</a>', $preferences);

        $booking = $link($this->linkTemplate($this->site->bookingUrl, $suid, $muid));
        $r('{booking}', '<a href="' . $booking . '">BOOKING FORM</a>', $booking);
        $contact = $link($this->linkTemplate($this->site->contactUrl, $suid, $muid));
        $r('{contact}', '<a href="' . $contact . '">CONTACT FORM</a>', $contact);

        $r('{firstname}', $recipient['s_fname'], $recipient['s_fname']);
        $r('{lastname}', $recipient['s_lname'], $recipient['s_lname']);
        $r('{emailsleft}', (string) $recipient['s_emailsleft'], (string) $recipient['s_emailsleft']);

        if ($list !== '') {
            $confirm = $link($base . 'confirm/' . $suid . '/' . $list . '/' . $muid);
            $r('{confirm}', '<a href="' . $confirm . '">YES</a>', $confirm);
        } else {
            $r('{confirm}', 'OPT IN', 'OPT IN');
        }
        $like = $link($base . 'like/' . $suid . '/' . $muid);
        $r('{like}', '<a href="' . $like . '">YES</a>', $like);
        $dislike = $link($base . 'dislike/' . $suid . '/' . $muid);
        $r('{dislike}', '<a href="' . $dislike . '">NO</a>', $dislike);
        $r('{usertrack}', $proof ? '' : '<img src="' . $base . 'ut/' . $suid . '/' . $muid . '" width="0" height="0">');
        $r('{baseurl}', $base, $base);
        $r('{listshortcode}', $list, $list);
        $r('{muid}', $muid, $muid);
        $r('{suid}', $proof ? '' : $suid, $proof ? '' : $suid);

        return new RenderedMessage($html, $text);
    }

    /**
     * A proof copy: rendered as for a subscriber, so the layout looks as it
     * will, with test merge values (PROOF_RECIPIENT, no real subscriber).
     * Links that would act for a subscriber (unsubscribe, confirm, forward,
     * preferences, reactions, booking and contact forms) lead to /proof-link,
     * which explains they are unavailable in a proof; there is no tracking
     * pixel and {suid} is empty.
     *
     * @param Message $message
     */
    public function renderProof(array $message, string $listShortcode = ''): RenderedMessage
    {
        return $this->render($message, self::PROOF_RECIPIENT, $listShortcode, true);
    }

    /** Fill {BaseURL}, {suid} and {muid} in a configured link (BookingURL, ContactURL). */
    private function linkTemplate(string $url, string $suid, string $muid): string
    {
        return str_ireplace(['{BaseURL}', '{suid}', '{muid}'], [$this->site->baseUrl, $suid, $muid], $url);
    }
}
