<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Config\SiteConfig;
use App\Repository\ArchiveRepository;
use Psr\Clock\ClockInterface;

/**
 * Immutable campaign archives: created once per message, the first time it
 * is queued, from the anonymous rendering. Later edits to the message do not
 * change its archive.
 *
 * @phpstan-import-type Message from \App\Repository\MessageRepository
 */
final class ArchiveService
{
    public function __construct(
        private readonly ArchiveRepository $archives,
        private readonly TemplateRenderer $renderer,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param Message $message
     * @return int the new archive id, or 0 when archives are disabled
     */
    public function archive(array $message): int
    {
        if (!$this->site->archiveEnabled) {
            return 0;
        }
        $archiveId = $this->archives->create($message['m_subject'], $this->clock->now()->format('Y-m-d H:i:s'));
        // The archive copy links to itself through {archive}.
        $this->archives->setHtml($archiveId, $this->renderer->render(['m_a_id' => $archiveId] + $message, null)->html);
        return $archiveId;
    }
}
