<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Repository\TemplateRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Three sample templates to use and adapt (resources/sample-templates/, an
 * HTML and a text part each, written with the placeholders TemplateRenderer
 * fills; {content} is where the message goes). install() adds the ones whose
 * name is not taken yet, so it can be run again at any time and never
 * replaces a template an administrator has edited or renamed.
 */
final class SampleTemplates
{
    /** name => file name without extension */
    public const SAMPLES = [
        'Sample: Newsletter' => 'newsletter',
        'Sample: Announcement' => 'announcement',
        'Sample: Letter' => 'letter',
    ];

    public function __construct(
        private readonly TemplateRepository $templates,
        #[Autowire('%kernel.project_dir%/resources/sample-templates')] private readonly string $directory,
    ) {
    }

    /**
     * The samples as shipped.
     *
     * @return array<string, array{html: string, text: string}> by template name
     */
    public function samples(): array
    {
        $samples = [];
        foreach (self::SAMPLES as $name => $file) {
            $html = @file_get_contents($this->directory . '/' . $file . '.html');
            $text = @file_get_contents($this->directory . '/' . $file . '.txt');
            if ($html === false || $text === false) {
                throw new \RuntimeException('Sample template "' . $file . '" is missing from ' . $this->directory . '.');
            }
            $samples[$name] = ['html' => rtrim($html) . "\n", 'text' => rtrim($text) . "\n"];
        }
        return $samples;
    }

    /**
     * Add the samples that are not there yet.
     *
     * @return array{added: list<string>, kept: list<string>} template names
     */
    public function install(): array
    {
        $existing = array_map(static fn(array $template): string => $template['t_name'], $this->templates->choices());
        $added = [];
        $kept = [];
        foreach ($this->samples() as $name => $sample) {
            if (in_array($name, $existing, true)) {
                $kept[] = $name;
                continue;
            }
            $this->templates->create($name, $sample['html'], $sample['text']);
            $added[] = $name;
        }
        return ['added' => $added, 'kept' => $kept];
    }
}
