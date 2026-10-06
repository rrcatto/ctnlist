<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** A message template in the editor (TemplateType); it may be saved incomplete. */
final class TemplateDraft
{
    #[Assert\Length(max: 100)]
    public string $name = '';

    public string $html = '';

    public string $text = '';

    /** @param array{t_name: string, t_html: string, t_text: string} $template */
    public static function fromTemplate(array $template): self
    {
        $draft = new self();
        $draft->name = $template['t_name'];
        $draft->html = $template['t_html'];
        $draft->text = $template['t_text'];
        return $draft;
    }
}
