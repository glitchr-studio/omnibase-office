<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Booking\ServiceArea;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the office goes to its clients: postcodes, towns, or a radius.
 */
class ServiceAreaCrudController extends AbstractCrudController
{
    use OpenToTrait;

    private ?TranslatorInterface $translator = null;

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    public static function getEntityFqcn(): string
    {
        return ServiceArea::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-map-location-dot';
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_ADMIN');

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@office.admin.field.name')->setColumns(6);
        yield BooleanField::new('active', '@office.admin.field.active')->setColumns(2);
        yield TextareaField::new('postalCodesText', '@office.admin.field.postal_codes')->setRequired(false)->setHelp('@office.admin.help.list');
        yield TextareaField::new('townsText', '@office.admin.field.towns')->setRequired(false)->setHelp('@office.admin.help.list')->hideOnIndex();
        yield AssociationField::new('office', '@office.admin.field.office')->setColumns(4)->setRequired(false)->hideOnIndex();
        yield NumberField::new('radiusKm', '@office.admin.field.radius')->setColumns(2)->setRequired(false)->hideOnIndex();
        yield AssociationField::new('members', '@office.admin.field.members')->setColumns(6)->setRequired(false)->hideOnIndex();
    }

    /** @param list<\UnitEnum> $cases */
    private function choices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$this->translator?->trans($case->label(), [], 'office') ?? $case->value] = $case->value;
        }

        return $choices;
    }
}
