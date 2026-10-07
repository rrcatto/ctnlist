<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form;

use App\Config\RuntimeSettings;
use App\Form\Settings\SettingsFormData;
use App\Form\Settings\SettingsGroupType;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/** The Settings forms: input rules at their fields, and form data → RuntimeSettings::save(). */
final class SettingsFormTest extends IntegrationTestCase
{
    /** Link templates keep their placeholders; only web addresses are accepted. */
    public function testLinkTemplatesAcceptWebAddressesAndPlaceholders(): void
    {
        foreach (['{BaseURL}contact-form/{suid}/{muid}', 'https://booking.example.org/?s={suid}', 'http://intranet.example.org/form'] as $link) {
            $form = $this->submit('contact', ['[APP_CONTACT_URL]' => $link, '[APP_BOOKING_URL]' => $link]);
            self::assertCount(0, $this->field($form, '[APP_CONTACT_URL]')->getErrors(), $link);
            self::assertCount(0, $this->field($form, '[APP_BOOKING_URL]')->getErrors(), $link);
        }
    }

    public function testInvalidInputIsReportedAtItsField(): void
    {
        foreach ([
            ['sender', '[MAIL_FROM_ADDRESS]', 'not-an-address', 'valid email'],
            ['sender', '[MAIL_TEST_ADDRESS]', 'x@', 'valid email'],
            ['contact', '[APP_FACEBOOK_URL]', 'javascript:alert(1)', 'full web address'],
            ['contact', '[APP_FACEBOOK_URL]', 'data:text/html,<script>x</script>', 'full web address'],
            ['contact', '[APP_BOOKING_URL]', 'javascript:alert(document.cookie)', 'Start the link with https://'],
            ['contact', '[APP_CONTACT_URL]', 'data:text/html;base64,PHNjcmlwdD4=', 'Start the link with https://'],
            ['sender', '[MAIL_FROM_NAME]', "Lists\r\nBcc: victim@example.net", 'one line'],
            ['signin', '[AUTH_MAGIC_LINK_TTL]', '10', 'at least 60'],
            ['signin', '[AUTH_MAGIC_LINK_TTL]', 'soon', 'whole number'],
            ['smtp', '[MAILER_DSN][host]', 'bad host/name', 'valid host name'],
            ['smtp', '[MAILER_DSN][port]', '70000', 'between 1 and 65535'],
            ['smtp', '[MAILER_DSN][options]', 'a b', 'spaces'],
        ] as [$group, $path, $value, $message]) {
            $form = $this->submit($group, [$path => $value]);
            self::assertFalse($form->isValid(), "{$path} = {$value}");
            $field = $this->field($form, $path);
            self::assertCount(1, $field->getErrors(), "the error is attached to {$path}");
            $error = $field->getErrors()[0];
            self::assertInstanceOf(\Symfony\Component\Form\FormError::class, $error);
            self::assertStringContainsString($message, $error->getMessage());
            self::assertSame($value, (string) $field->getViewData(), 'the submitted value is kept');
        }
    }

    public function testValidInputBecomesTheSavedValues(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        $form = $this->submit('site', ['[APP_LIST_NAME]' => '  Form list  ']);
        self::assertTrue($form->isValid());
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        self::assertSame(['APP_LIST_NAME'], $settings->save('site', SettingsFormData::toInput('site', $data), null));
        self::assertSame('Form list', $settings->get('APP_LIST_NAME'));

        // Unchanged forms store nothing, switches included.
        foreach (['site', 'contact', 'sender', 'smtp', 'contact_form', 'subscription', 'archives', 'signin'] as $group) {
            $unchanged = $this->submit($group, []);
            self::assertTrue($unchanged->isValid(), $group . ': ' . $unchanged->getErrors(true));
            /** @var array<string, mixed> $data */
            $data = $unchanged->getData();
            self::assertSame([], $settings->save($group, SettingsFormData::toInput($group, $data), null), $group);
        }
    }

    public function testPasswordsAreNeverFormData(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        $form = $this->submit('smtp', ['[MAILER_DSN][host]' => 'mail.example.net', '[MAILER_DSN][port]' => '465', '[MAILER_DSN][security]' => 'smtps',
            '[MAILER_DSN][username]' => 'list', '[MAILER_DSN][password]' => 's3cret:pw']);
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        $settings->save('smtp', SettingsFormData::toInput('smtp', $data), null);
        self::assertStringContainsString('s3cret', rawurldecode($settings->get('MAILER_DSN')));

        $initial = SettingsFormData::initial($settings, 'smtp');
        self::assertIsArray($initial['MAILER_DSN']);
        self::assertNull($initial['MAILER_DSN']['password'], 'the stored password is not loaded into the form');
        self::assertStringNotContainsString('s3cret', (string) json_encode($initial));
        $view = $this->form('smtp')->createView();
        self::assertSame('', $view['MAILER_DSN']['password']->vars['value'], 'and never rendered');

        // An empty password keeps the stored one.
        $kept = $this->submit('smtp', []);
        /** @var array<string, mixed> $data */
        $data = $kept->getData();
        self::assertSame([], $settings->save('smtp', SettingsFormData::toInput('smtp', $data), null));
        self::assertStringContainsString('s3cret', rawurldecode($settings->get('MAILER_DSN')));
    }

    private function form(string $group): FormInterface
    {
        return $this->service(FormFactoryInterface::class)->createNamed('settings_' . $group, SettingsGroupType::class,
            SettingsFormData::initial($this->service(RuntimeSettings::class), $group), ['group' => $group, 'csrf_protection' => false]);
    }

    /**
     * Submit a group's form as the page shows it, with $changes keyed by field path ("[MAILER_DSN][host]").
     *
     * @param array<string, string> $changes
     */
    private function submit(string $group, array $changes): FormInterface
    {
        $form = $this->form($group);
        $submitted = self::submittedValues($form->createView());
        foreach ($changes as $path => $value) {
            $target = &$submitted;
            preg_match_all('/\[([^\]]+)\]/', $path, $keys);
            foreach ($keys[1] as $key) {
                $target = &$target[$key];
            }
            $target = $value;
            unset($target);
        }
        $form->submit($submitted);
        return $form;
    }

    /**
     * What a browser sends for a rendered form (checked boxes only).
     *
     * @return array<int|string, mixed>|string|null
     */
    private static function submittedValues(\Symfony\Component\Form\FormView $view): array|string|null
    {
        if ($view->children === []) {
            if (in_array('checkbox', $view->vars['block_prefixes'], true)) {
                return $view->vars['checked'] ? '1' : null;
            }
            return is_scalar($view->vars['value']) ? (string) $view->vars['value'] : '';
        }
        $values = [];
        foreach ($view->children as $name => $child) {
            $value = self::submittedValues($child);
            if ($value !== null) {
                $values[$name] = $value;
            }
        }
        return $values;
    }

    private function field(FormInterface $form, string $path): FormInterface
    {
        preg_match_all('/\[([^\]]+)\]/', $path, $keys);
        foreach ($keys[1] as $key) {
            $form = $form->get($key);
        }
        return $form;
    }
}
