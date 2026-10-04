<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Booking\Appointment;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Office\Enum\AppointmentStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every appointment, as a list (the agenda is the screen to work in): who,
 * when, its status. The client's reason is not shown here.
 */
class AppointmentCrudController extends AbstractCrudController
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
        return Appointment::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-calendar-check';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions)->disable(Action::NEW), 'ROLE_STAFF');

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('startsAt', '@office.admin.field.starts_at')->setColumns(3)->setDisabled();
        yield AssociationField::new('member', '@office.admin.field.member')->setColumns(3)->setDisabled();
        yield AssociationField::new('type', '@office.admin.field.type')->setColumns(3)->setDisabled();
        yield TextField::new('clientName', '@office.admin.field.client')->setColumns(3)->setDisabled();
        yield TextField::new('clientPhone', '@office.admin.field.phone')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('beneficiary', '@office.admin.field.beneficiary')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('address', '@office.admin.field.address')->setColumns(6)->setRequired(false)->hideOnIndex();
        yield SelectField::new('statusValue', '@office.admin.field.status')->setChoices($this->choices(AppointmentStatus::cases()))->setColumns(3);
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
