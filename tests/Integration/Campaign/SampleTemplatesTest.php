<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Campaign\SampleTemplates;
use App\Campaign\TemplateRenderer;
use App\Command\SampleTemplatesCommand;
use App\Repository\MessageRepository;
use App\Repository\TemplateRepository;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three sample templates (resources/sample-templates/): what they are
 * made of, that ctnlist:templates:samples adds them once and never replaces
 * an edited one, and that a message in each of them renders completely for
 * a subscriber, for a proof and for the archive.
 */
final class SampleTemplatesTest extends IntegrationTestCase
{
    /** Every placeholder App\Campaign\TemplateRenderer fills (README "Message placeholders"). */
    private const PLACEHOLDERS = ['content', 'firstname', 'lastname', 'emailsleft', 'subscription', 'unsubscribe', 'confirm', 'forward', 'preferences',
        'like', 'dislike', 'contact', 'usertrack', 'suid', 'muid', 'baseurl', 'listshortcode', 'listname', 'domain', 'organisation', 'archive',
        'subscribe', 'facebook', 'twitter'];

    public function testTheSamplesUseOnlyCtnlistsPlaceholders(): void
    {
        $samples = $this->service(SampleTemplates::class)->samples();
        self::assertSame(['Sample: Newsletter', 'Sample: Announcement', 'Sample: Letter'], array_keys($samples));
        foreach ($samples as $name => $sample) {
            self::assertLessThanOrEqual(100, strlen($name), 'fits templates.t_name');
            foreach (['html', 'text'] as $part) {
                self::assertSame(1, substr_count($sample[$part], '{content}'), "$name ($part): the message goes in once");
                self::assertStringContainsString('{unsubscribe}', $sample[$part], "$name ($part): every message says how to stop");
                preg_match_all('/\{([a-z-]+)\}/i', $sample[$part], $used);
                self::assertSame([], array_values(array_diff(array_unique($used[1]), self::PLACEHOLDERS)), "$name ($part): only placeholders ctnlist fills");
            }
            // HTML for mail: inline styles only, nothing a mail client would not run, nothing loaded from elsewhere.
            self::assertDoesNotMatchRegularExpression('/<(script|style|link|iframe|form|img)\b|\bon\w+=|https?:\/\//i', $sample['html'], $name);
            self::assertStringContainsString('{usertrack}', $sample['html'], "$name: opens can be recorded");
            self::assertStringNotContainsString('{usertrack}', $sample['text'], "$name: the text part has no pixel");
            self::assertStringNotContainsString('<', $sample['text'], "$name: the text part is text");
        }
    }

    public function testTheCommandAddsThemOnceAndNeverReplacesAnEditedOne(): void
    {
        $templates = $this->service(TemplateRepository::class);
        $before = $templates->count();
        $command = new CommandTester($this->service(SampleTemplatesCommand::class));

        self::assertSame(0, $command->execute([]));
        self::assertSame("Added: Sample: Newsletter, Sample: Announcement, Sample: Letter.\n", $command->getDisplay());
        self::assertSame($before + 3, $templates->count());

        // An administrator adapts one and renames another; running the command again changes neither.
        $ids = array_column($templates->choices(), 't_id', 't_name');
        $templates->update($ids['Sample: Newsletter'], 'Sample: Newsletter', '<p>Mine now: {content}</p>', 'Mine now: {content}');
        $templates->update($ids['Sample: Letter'], 'Our letter', '<p>{content}</p>', '{content}');
        self::assertSame(0, $command->execute([]));
        self::assertSame("Added: Sample: Letter.\nAlready there, left as they are: Sample: Newsletter, Sample: Announcement.\n", $command->getDisplay());
        self::assertSame('<p>Mine now: {content}</p>', $templates->find($ids['Sample: Newsletter'])['t_html'] ?? null, 'the edited sample is kept');
        self::assertSame($before + 4, $templates->count(), 'the renamed one is the administrator\'s; the sample is offered again');
    }

    public function testAMessageInEachSampleRendersCompletely(): void
    {
        $samples = $this->service(SampleTemplates::class)->samples();
        $this->service(SampleTemplates::class)->install();
        $ids = array_column($this->service(TemplateRepository::class)->choices(), 't_id', 't_name');
        $list = $this->createList('NEWS', 'News');
        $subscriberId = $this->createSubscriber('reader@example.com', 'Rea', 'Der');
        $subscriber = $this->db->fetchAssociative('SELECT s_id, s_uuid, s_email, s_fname, s_lname, s_emailsleft, s_priority, s_last_interacted, s_delivery_state FROM subscribers WHERE s_id = ?', [$subscriberId]);
        self::assertIsArray($subscriber);
        $renderer = $this->service(TemplateRenderer::class);

        foreach (array_keys(SampleTemplates::SAMPLES) as $name) {
            $muid = $this->createMessage('In ' . $name, [$list]);
            $this->db->executeStatement('UPDATE messages SET m_t_id = ?, m_html = ?, m_text = ? WHERE m_uniqid = ?', [$ids[$name], '<p>The news of the week.</p>', 'The news of the week.', $muid]);
            $message = $this->service(MessageRepository::class)->findByMuid($muid);
            self::assertNotNull($message);

            /** @phpstan-ignore argument.type (the row has the columns the renderer reads) */
            $forSubscriber = $renderer->render($message, $subscriber, 'NEWS');
            $proof = $renderer->renderProof($message, 'NEWS');
            $archive = $renderer->render($message, null, 'NEWS');
            foreach (['subscriber' => $forSubscriber, 'proof' => $proof, 'archive' => $archive] as $kind => $rendered) {
                self::assertStringContainsString('<p>The news of the week.</p>', $rendered->html, "$name, $kind");
                self::assertStringContainsString('The news of the week.', $rendered->text, "$name, $kind");
                self::assertDoesNotMatchRegularExpression('/\{[a-z-]+\}/i', $rendered->html . $rendered->text, "$name, $kind: no placeholder is left");
            }
            self::assertStringContainsString('/unsubscribe/' . $subscriber['s_uuid'] . '/NEWS/' . $muid, $forSubscriber->html, $name);
            self::assertStringContainsString('/unsubscribe/' . $subscriber['s_uuid'] . '/NEWS/' . $muid, $forSubscriber->text, $name);
            self::assertStringContainsString('/ut/' . $subscriber['s_uuid'] . '/' . $muid, $forSubscriber->html, "$name: the open pixel");
            if (str_contains($samples[$name]['html'], '{firstname}')) {
                self::assertStringContainsString('Dear Rea', $forSubscriber->html, "$name: the subscriber's name");
            }
            self::assertStringContainsString('/proof-link', $proof->html, "$name: a proof's links explain themselves");
            self::assertStringNotContainsString('/ut/', $proof->html, $name);
        }
    }
}
