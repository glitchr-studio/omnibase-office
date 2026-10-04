<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Booking\Schedule;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The weekly schedules: who receives when, where, for which types.
 */
class ScheduleCrudController extends AbstractCrudController
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
        return Schedule::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-business-time';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['dayOfWeek' => 'ASC', 'startsAt' => 'ASC']);
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
        yield SelectField::new('dayOfWeek', '@office.admin.field.day')->setChoices($this->days())->setColumns(2);
        yield TextField::new('startsAt', '@office.admin.field.starts_at')->setColumns(2)->setHelp('@office.admin.help.time');
        yield TextField::new('endsAt', '@office.admin.field.ends_at')->setColumns(2)->setHelp('@office.admin.help.time');
        yield AssociationField::new('office', '@office.admin.field.office')->setColumns(4)->setRequired(false)->setHelp('@office.admin.help.schedule_office');
        yield AssociationField::new('appointmentTypes', '@office.admin.field.types')->setColumns(8)->setRequired(false)->setHelp('@office.admin.help.schedule_types')->hideOnIndex();
        yield DateField::new('validFrom', '@office.admin.field.valid_from')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield DateField::new('validUntil', '@office.admin.field.valid_until')->setColumns(3)->setRequired(false)->hideOnIndex();
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

    private function days(): array
    {
        $days = [];
        for ($d = 1; $d <= 7; ++$d) {
            $days[$this->translator?->trans('day.'.$d, [], 'office') ?? (string) $d] = $d;
        }

        return $days;
    }
}
