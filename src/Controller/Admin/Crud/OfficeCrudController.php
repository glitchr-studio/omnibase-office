<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Office;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\NumberField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The office's places: address, phones, access, accessibility. The opening
 * hours are omnibase's (the back office's hours screen).
 */
class OfficeCrudController extends AbstractCrudController
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
        return Office::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-house-medical';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['main' => 'DESC', 'position' => 'ASC']);
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
        yield SlugField::new('slug')->setColumns(4)->hideOnIndex();
        yield BooleanField::new('main', '@office.admin.field.main')->setColumns(2);
        yield TextField::new('street', '@office.admin.field.street')->setColumns(6)->setRequired(false)->hideOnIndex();
        yield TextField::new('postalCode', '@office.admin.field.postal_code')->setColumns(2)->setRequired(false);
        yield TextField::new('city', '@office.admin.field.city')->setColumns(4)->setRequired(false);
        yield NumberField::new('latitude', '@office.admin.field.latitude')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield NumberField::new('longitude', '@office.admin.field.longitude')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('phone', '@office.admin.field.phone')->setColumns(3)->setRequired(false);
        yield TextField::new('email', '@office.admin.field.email')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('secondaryPhone', '@office.admin.field.secondary_phone')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('secondaryPhoneLabel', '@office.admin.field.secondary_phone_label')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextareaField::new('access', '@office.admin.field.access')->setRequired(false)->hideOnIndex();
        yield BooleanField::new('wheelchairAccessible', '@office.admin.field.wheelchair')->setColumns(3);
        yield TextareaField::new('accessibility', '@office.admin.field.accessibility')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('parking', '@office.admin.field.parking')->setRequired(false)->hideOnIndex();
        yield IntegerField::new('position', '@office.admin.field.position')->setColumns(2)->hideOnIndex();
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
