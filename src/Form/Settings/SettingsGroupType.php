<?php

declare(strict_types=1);

namespace App\Form\Settings;

use App\Config\SettingsCatalogue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One tab of the Settings page: a field per SettingsCatalogue entry of the
 * group (named after its environment variable), validated by
 * SettingConstraints. Data is an array (see SettingsFormData).
 *
 * @phpstan-import-type Setting from SettingsCatalogue
 */
final class SettingsGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (SettingsCatalogue::GROUPS[$options['group']]['settings'] as $name => $setting) {
            $this->addSetting($builder, $name, $setting);
        }
    }

    /** @param Setting $setting */
    private function addSetting(FormBuilderInterface $builder, string $name, array $setting): void
    {
        $common = ['label' => $setting['label'], 'required' => SettingConstraints::required($setting), 'help' => $setting['help'] ?? null, 'constraints' => SettingConstraints::for($setting)];
        match ($setting['type']) {
            'bool' => $builder->add($name, CheckboxType::class, ['label' => 'Enabled', 'required' => false, 'help' => $setting['help'] ?? null, 'label_attr' => ['class' => 'checkbox-switch']]),
            'int' => $builder->add($name, IntegerType::class, $common + ['invalid_message' => SettingConstraints::NOT_A_NUMBER, 'attr' => isset($setting['min']) ? ['min' => $setting['min']] : []]),
            'email' => $builder->add($name, EmailType::class, $common),
            'url' => $builder->add($name, UrlType::class, $common + ['default_protocol' => null]),
            'textarea' => $builder->add($name, TextareaType::class, $common + ['attr' => ['rows' => 3]]),
            'dsn' => $builder->add($name, SmtpServerType::class, ['label' => false]),
            default => $builder->add($name, TextType::class, $common),
        };
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('group');
        $resolver->setAllowedValues('group', array_keys(SettingsCatalogue::GROUPS));
    }
}
