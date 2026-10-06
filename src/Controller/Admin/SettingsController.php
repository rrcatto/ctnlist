<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\RuntimeSettings;
use App\Config\SettingsCatalogue;
use App\Config\SettingsCipherException;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings that override selected .env variables (SettingsCatalogue),
 * resolved database → .env → default by RuntimeSettings. The database holds
 * overrides only; .env is never written. Secret values never reach the page.
 */
#[IsGranted('settings.manage')]
final class SettingsController extends AbstractController
{
    public function __construct(private readonly RuntimeSettings $settings)
    {
    }

    #[Route('/settings', name: 'admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        $fields = [];
        foreach (SettingsCatalogue::GROUPS as $definition) {
            foreach ($definition['settings'] as $name => $setting) {
                $fields[$name] = $this->field($name, $setting);
            }
        }
        return $this->render('admin/settings.html.twig', [
            'groups' => SettingsCatalogue::GROUPS,
            'fields' => $fields,
            'cipher_problem' => $this->settings->cipherProblem(),
        ]);
    }

    #[Route('/settings/{group}', name: 'admin_settings_save', requirements: ['group' => '[a-z_]+'], methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function save(string $group, Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        $label = $this->groupLabel($group);
        try {
            $changed = $this->settings->save($group, $request->request->all(), $user->id);
            $this->addFlash('info', $changed === [] ? $label . ': no changes to save.' : $label . ' settings saved.');
        } catch (\InvalidArgumentException | SettingsCipherException $e) {
            $this->addFlash('danger', $label . ': ' . $e->getMessage());
        }
        return $this->redirect($this->generateUrl('admin_settings') . '#' . $group);
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

    /**
     * What the page shows for one setting. Secret values are never included:
     * only whether one is set, and for SMTP the non-secret parts.
     *
     * @param array{type: string, default?: string, secret?: bool} $setting
     * @return array<string, mixed>
     */
    private function field(string $name, array $setting): array
    {
        $secret = $setting['secret'] ?? false;
        $default = $setting['default'] ?? '';
        $problem = $this->settings->secretProblem($name);
        $effective = '';
        if ($problem === null) {
            // Numbers, switches and lists show the value in effect; text shows its default as a placeholder.
            $effective = $this->settings->get($name, in_array($setting['type'], ['int', 'bool', 'servers'], true) ? $default : '');
        }
        return [
            'source' => $this->settings->source($name),
            'override' => $this->settings->overrideInfo($name),
            'secret_problem' => $problem,
            'value' => $secret ? '' : $effective,
            'env' => $secret ? '' : $this->settings->envValue($name),
            'env_set' => $this->settings->envValue($name) !== '',
            'default' => $secret ? '' : $default,
            'dsn' => $setting['type'] === 'dsn' ? RuntimeSettings::dsnParts($effective) : null,
            'servers' => $setting['type'] === 'servers' ? RuntimeSettings::serverRows($effective) : null,
        ];
    }

    private function groupLabel(string $group): string
    {
        return SettingsCatalogue::GROUPS[$group]['label'] ?? throw $this->createNotFoundException('Unknown settings group.');
    }
}
