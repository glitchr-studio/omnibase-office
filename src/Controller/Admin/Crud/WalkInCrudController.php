<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\WalkIn;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Hours without an appointment: the blood tests at 7:30, a walk-in morning.
 */
class WalkInCrudController extends AbstractCrudController
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
        return WalkIn::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-door-open';
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_ADMIN');

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label', '@office.admin.field.label')->setColumns(6);
        yield SelectField::new('days', '@office.admin.field.days')->setChoices($this->days())->allowMultipleChoices()->setColumns(6);
        yield TextField::new('opensAt', '@office.admin.field.opens_at')->setColumns(2);
        yield TextField::new('closesAt', '@office.admin.field.closes_at')->setColumns(2);
        yield AssociationField::new('office', '@office.admin.field.office')->setColumns(4)->setRequired(false);
        yield AssociationField::new('member', '@office.admin.field.member')->setColumns(4)->setRequired(false)->hideOnIndex();
        yield TextareaField::new('notes', '@office.admin.field.notes')->setRequired(false)->hideOnIndex();
        yield BooleanField::new('active', '@office.admin.field.active')->setColumns(2);
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
