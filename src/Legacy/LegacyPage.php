<?php

declare(strict_types=1);

namespace App\Legacy;

/** Title and content of the page a legacy route rendered, for LegacyBridge. */
final class LegacyPage
{
    private ?string $title = null;
    private string $content = '';

    public function set(string $title, string $content): void
    {
        $this->title = $title;
        $this->content = $content;
    }

    public function isRendered(): bool
    {
        return $this->title !== null;
    }

    public function title(): string
    {
        return (string) $this->title;
    }

    public function content(): string
    {
        return $this->content;
    }
}
