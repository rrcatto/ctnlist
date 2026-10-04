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
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly SiteConfig $site,
    ) {
    }

    /**
     * @param Message $message
     * @param Recipient|null $recipient null renders anonymously (archive copy)
     * @param string $listShortcode the delivery's list ('' for an audience-less proof)
     */
    public function render(array $message, ?array $recipient, string $listShortcode = ''): RenderedMessage
    {
        $base = $this->site->baseUrl;
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
        $r('{STORE}', '<a href="' . $this->site->storeUrl . '">STORE</a>', $this->site->storeUrl);

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
            $unsubscribe = $base . 'unsubscribe/' . $suid . '/' . $list . '/' . $muid;
            $r('{unsubscribe}', '<a href="' . $unsubscribe . '">UNSUBSCRIBE</a>', $unsubscribe);
        } else {
            // A proof of an audience-less draft has no list to unsubscribe from.
            $r('{unsubscribe}', 'UNSUBSCRIBE', 'UNSUBSCRIBE');
        }
        $r('{forward}', '<a href="' . $base . 'forward/' . $suid . '/' . $muid . '">FORWARD</a>', $base . 'forward/' . $suid . '/' . $muid);
        $r('{preferences}', '<a href="' . $base . 'profile/subscriber/' . $suid . '">UPDATE</a>', $base . 'profile/subscriber/' . $suid);

        $booking = $this->linkTemplate($this->site->bookingUrl, $suid, $muid);
        $r('{booking}', '<a href="' . $booking . '">BOOKING FORM</a>', $booking);
        $contact = $this->linkTemplate($this->site->contactUrl, $suid, $muid);
        $r('{contact}', '<a href="' . $contact . '">CONTACT FORM</a>', $contact);

        $r('{firstname}', $recipient['s_fname'], $recipient['s_fname']);
        $r('{lastname}', $recipient['s_lname'], $recipient['s_lname']);
        $r('{emailsleft}', (string) $recipient['s_emailsleft'], (string) $recipient['s_emailsleft']);

        if ($list !== '') {
            $confirm = $base . 'confirm/' . $suid . '/' . $list . '/' . $muid;
            $r('{confirm}', '<a href="' . $confirm . '">YES</a>', $confirm);
        } else {
            $r('{confirm}', 'OPT IN', 'OPT IN');
        }
        $r('{like}', '<a href="' . $base . 'like/' . $suid . '/' . $muid . '">YES</a>', $base . 'like/' . $suid . '/' . $muid);
        $r('{dislike}', '<a href="' . $base . 'dislike/' . $suid . '/' . $muid . '">NO</a>', $base . 'dislike/' . $suid . '/' . $muid);
        $r('{usertrack}', '<img src="' . $base . 'ut/' . $suid . '/' . $muid . '" width="0" height="0">');
        $r('{baseurl}', $base, $base);
        $r('{listshortcode}', $list, $list);
        $r('{muid}', $muid, $muid);
        $r('{suid}', $suid, $suid);

        return new RenderedMessage($html, $text);
    }

    /** Fill {BaseURL}, {suid} and {muid} in a configured link (BookingURL, ContactURL). */
    private function linkTemplate(string $url, string $suid, string $muid): string
    {
        return str_ireplace(['{BaseURL}', '{suid}', '{muid}'], [$this->site->baseUrl, $suid, $muid], $url);
    }
}
