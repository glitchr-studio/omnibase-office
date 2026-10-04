<?php

namespace Base\Office\Admin\Settings;

use Base\Admin\Settings\SettingsSectionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The settings' "Office" section: what the legal notice must say and only
 * the office knows - who hosts the site, who to write to about one's data.
 */
#[AsTaggedItem(priority: 50)]
final class OfficeLegalSection implements SettingsSectionInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getPage(): string
    {
        return self::SETTINGS;
    }

    public function getFields(): array
    {
        return [
            'office.legal.host' => ['required' => false, 'form_type' => TextareaType::class, 'label' => $this->translator->trans('settings.legal_host', [], 'office')],
            'office.legal.dpo' => ['required' => false, 'label' => $this->translator->trans('settings.legal_dpo', [], 'office')],
            'office.legal.retention' => ['required' => false, 'form_type' => TextareaType::class, 'label' => $this->translator->trans('settings.legal_retention', [], 'office')],
        ];
    }
}
