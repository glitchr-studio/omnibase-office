<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\ContactRequest;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contact form's messages, read and marked handled; purged after
 * office.contact.retention_months.
 */
class ContactRequestCrudController extends AbstractCrudController
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
        return ContactRequest::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-envelope-open-text';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions)->disable(Action::NEW), 'ROLE_STAFF');

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', '@office.admin.field.created_at')->setDisabled();
        yield TextField::new('name', '@office.admin.field.name')->setColumns(4)->setDisabled();
        yield TextField::new('email', '@office.admin.field.email')->setColumns(4)->setDisabled();
        yield TextField::new('phone', '@office.admin.field.phone')->setColumns(4)->setDisabled()->hideOnIndex();
        yield TextareaField::new('message', '@office.admin.field.message')->setDisabled()->hideOnIndex();
        yield BooleanField::new('handled', '@office.admin.field.handled')->setColumns(3);
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
