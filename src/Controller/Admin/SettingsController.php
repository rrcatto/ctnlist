<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\RuntimeSettings;
use App\Config\SettingsCatalogue;
use App\Config\SettingsCipherException;
use App\Form\Settings\SettingsFormData;
use App\Form\Settings\SettingsGroupType;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings that override selected .env variables (SettingsCatalogue),
 * resolved database → .env → default by RuntimeSettings. One Symfony form per
 * tab (SettingsGroupType): invalid input is shown at its field with the
 * submitted values kept; passwords are never rendered back. The database
 * holds overrides only; .env is never written.
 */
#[IsGranted('settings.manage')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly RuntimeSettings $settings,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('/settings', name: 'admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page();
    }

    #[Route('/settings/{group}', name: 'admin_settings_save', requirements: ['group' => '[a-z_]+'], methods: ['POST'])]
    public function save(string $group, Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        $label = $this->groupLabel($group);
        $form = $this->groupForm($group);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->redirect($this->generateUrl('admin_settings') . '#' . $group);
        }
        if ($form->isValid()) {
            try {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                $changed = $this->settings->save($group, SettingsFormData::toInput($group, $data), $user->id);
                $this->addFlash('info', $changed === [] ? $label . ': no changes to save.' : $label . ' settings saved.');
                return $this->redirect($this->generateUrl('admin_settings') . '#' . $group);
            } catch (SettingsCipherException $e) {
                // APP_SETTINGS_KEY is needed to store a secret: a configuration problem, shown with the form.
                $form->addError(new FormError($e->getMessage()));
            }
        }
        return $this->page($group, $form);
    }

    #[Route('/settings/{group}/reset', name: 'admin_settings_reset', requirements: ['group' => '[a-z_]+'], methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function reset(string $group, #[CurrentUser] SubscriberUser $user): Response
    {
        $label = $this->groupLabel($group);
        $this->settings->resetGroup($group, $user->id);
        $this->addFlash('info', $label . ': every setting now comes from .env or its default.');
        return $this->redirect($this->generateUrl('admin_settings') . '#' . $group);
    }

    /** Remove one override (the field's "Reset override" button). */
    #[Route('/settings/reset/{name}', name: 'admin_setting_reset', requirements: ['name' => '[A-Z][A-Z0-9_]*'], methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function resetSetting(string $name, #[CurrentUser] SubscriberUser $user): Response
    {
        $group = SettingsCatalogue::names()[$name] ?? throw $this->createNotFoundException('Unknown setting.');
        $this->settings->resetSetting($name, $user->id);
        $this->addFlash('info', SettingsCatalogue::GROUPS[$group]['settings'][$name]['label'] . ' now comes from ' . ($this->settings->source($name) === RuntimeSettings::SOURCE_ENV ? '.env.' : 'its default.'));
        return $this->redirect($this->generateUrl('admin_settings') . '#' . $group);
    }

    /** The page, with $submitted (and its tab active) in place of a fresh form for $active. */
    private function page(?string $active = null, ?FormInterface $submitted = null): Response
    {
        $forms = [];
        $fields = [];
        foreach (SettingsCatalogue::GROUPS as $group => $definition) {
            $forms[$group] = ($group === $active && $submitted !== null ? $submitted : $this->groupForm($group))->createView();
            foreach ($definition['settings'] as $name => $setting) {
                $fields[$name] = $this->field($name, $setting);
            }
        }
        return $this->render('admin/settings.html.twig', [
            'groups' => SettingsCatalogue::GROUPS,
            'forms' => $forms,
            'fields' => $fields,
            'active' => $active ?? array_key_first(SettingsCatalogue::GROUPS),
            'cipher_problem' => $this->settings->cipherProblem(),
        ], new Response(status: $submitted !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function groupForm(string $group): FormInterface
    {
        return $this->forms->createNamed('settings_' . $group, SettingsGroupType::class, SettingsFormData::initial($this->settings, $group), [
            'group' => $group,
            'action' => $this->generateUrl('admin_settings_save', ['group' => $group]),
        ]);
    }

    /**
     * Metadata shown with a field: its source, override details and secret
     * status. Secret values are never included.
     *
     * @param array{type: string, default?: string, secret?: bool} $setting
     * @return array<string, mixed>
     */
    private function field(string $name, array $setting): array
    {
        $secret = $setting['secret'] ?? false;
        $problem = $this->settings->secretProblem($name);
        $effective = $problem === null ? $this->settings->get($name) : '';
        return [
            'source' => $this->settings->source($name),
            'override' => $this->settings->overrideInfo($name),
            'secret_problem' => $problem,
            'env' => $secret ? '' : $this->settings->envValue($name),
            'env_set' => $this->settings->envValue($name) !== '',
            'default' => $secret ? '' : ($setting['default'] ?? ''),
            'dsn' => $setting['type'] === 'dsn' ? RuntimeSettings::dsnParts($effective) : null,
        ];
    }

    private function groupLabel(string $group): string
    {
        return SettingsCatalogue::GROUPS[$group]['label'] ?? throw $this->createNotFoundException('Unknown settings group.');
    }
}
