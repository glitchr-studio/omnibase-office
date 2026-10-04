<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Booking\Absence;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A member away: their slots are gone for that time.
 */
class AbsenceCrudController extends AbstractCrudController
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
        return Absence::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-umbrella-beach';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_STAFF');

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('member', '@office.admin.field.member')->setColumns(4);
        yield DateTimeField::new('startsAt', '@office.admin.field.from')->setColumns(4);
        yield DateTimeField::new('endsAt', '@office.admin.field.until')->setColumns(4);
        yield TextField::new('reason', '@office.admin.field.absence_reason')->setRequired(false)->setHelp('@office.admin.help.absence_reason');
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
