<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Base\Office\Enum\BookableBy;
use Base\Office\Enum\BookingMode;
use Base\Office\Enum\Channel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an appointment is for: duration, buffer, channel, who may book it
 * online, by slot or by request.
 */
class AppointmentTypeCrudController extends AbstractCrudController
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
        return AppointmentType::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-list-check';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['position' => 'ASC']);
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
        yield IntegerField::new('position', '@office.admin.field.position')->setColumns(2)->hideOnIndex();
        yield IntegerField::new('duration', '@office.admin.field.duration')->setColumns(2)->setHelp('@office.admin.help.minutes');
        yield IntegerField::new('buffer', '@office.admin.field.buffer')->setColumns(2)->setHelp('@office.admin.help.minutes')->hideOnIndex();
        yield SelectField::new('channelValue', '@office.admin.field.channel')->setChoices($this->choices(Channel::cases()))->setColumns(3);
        yield SelectField::new('modeValue', '@office.admin.field.mode')->setChoices($this->choices(BookingMode::cases()))->setColumns(3);
        yield SelectField::new('bookableByValue', '@office.admin.field.bookable_by')->setChoices($this->choices(BookableBy::cases()))->setColumns(3);
        yield BooleanField::new('manualConfirmation', '@office.admin.field.manual_confirmation')->setColumns(3)->hideOnIndex();
        yield TextField::new('price', '@office.admin.field.price')->setColumns(3)->setRequired(false)->setHelp('@office.admin.help.price');
        yield AssociationField::new('members', '@office.admin.field.members')->setColumns(6)->setRequired(false);
        yield AssociationField::new('office', '@office.admin.field.office')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextareaField::new('instructions', '@office.admin.field.instructions')->setRequired(false)->hideOnIndex();
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
}
