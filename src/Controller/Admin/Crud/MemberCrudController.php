<?php

namespace Base\Office\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Office\Controller\Admin\OpenToTrait;
use Base\Office\Entity\Member;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\ColorField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Field\TextareaField;
use Base\Office\Service\MemberRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The team as the site shows it. "Read the register" asks the member's
 * professional register again (glitchr/omnistate) and keeps what it said.
 */
class MemberCrudController extends AbstractCrudController
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
        return Member::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-user-doctor';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['position' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_ADMIN', 'registry');
        if ($this->registry?->isAvailable()) {
            foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
                $actions->add($page, Action::new('registry', '@office.admin.registry.action', 'fa-solid fa-address-card')->linkToCrudAction('registry'));
            }
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('displayName', '@office.admin.field.display_name')->setColumns(5);
        yield TextField::new('title', '@office.admin.field.title')->setColumns(4)->setRequired(false);
        yield TextField::new('category', '@office.admin.field.category')->setColumns(3)->setRequired(false)->setHelp('@office.admin.help.category');
        yield SlugField::new('slug')->setColumns(4)->hideOnIndex();
        // The account they sign in with: a plain choice (omnibase's association field would open the whole account's form).
        yield SelectField::new('user', '@office.admin.field.user')->setChoices($this->accounts())->setColumns(4)->setRequired(false)->hideOnIndex();
        yield TextField::new('registryId', '@office.admin.field.registry_id')->setColumns(4)->setRequired(false)->setHelp('@office.admin.help.registry_id');
        yield DateTimeField::new('registryCheckedAt', '@office.admin.field.registry_checked_at')->setDisabled()->hideOnForm();
        yield ImageField::new('portrait', '@office.admin.field.portrait')->setColumns(6)->setRequired(false)->hideOnIndex();
        yield TextareaField::new('biography', '@office.admin.field.biography')->setRequired(false)->hideOnIndex();
        yield AssociationField::new('offices', '@office.admin.field.offices')->setColumns(6)->setRequired(false)->hideOnIndex();
        yield ColorField::new('color', '@office.admin.field.color')->setColumns(2)->setRequired(false)->hideOnIndex();
        yield IntegerField::new('position', '@office.admin.field.position')->setColumns(2)->hideOnIndex();
        yield BooleanField::new('visible', '@office.admin.field.visible')->setColumns(2);
        yield BooleanField::new('bookable', '@office.admin.field.bookable')->setColumns(2);
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

    /** @return array<string, object> identifier => account */
    private function accounts(): array
    {
        $accounts = [];
        foreach ($this->entityManager->getRepository(\App\Entity\User::class)->findBy([], ['username' => 'ASC']) as $user) {
            $accounts[(string) $user] = $user;
        }

        return $accounts;
    }

    private ?MemberRegistry $registry = null;

    #[Required]
    public function setRegistry(MemberRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /** The member read again from their register. */
    #[AdminAction('/{entityId}/registry')]
    public function registry(Request $request, string $entityId): Response
    {
        /** @var Member $member */
        $member = $this->findEntity($entityId);
        try {
            $professional = $this->registry?->refresh($member);
            if (null === $professional) {
                $this->addFlash('warning', $this->translator?->trans(null === $member->getRegistryId() ? 'admin.registry.no_id' : 'admin.registry.unknown', [], 'office') ?? '');
            } else {
                $this->addFlash('success', $this->translator?->trans('admin.registry.read', ['name' => $professional->name(), 'profession' => (string) ($professional->profession ?? '')], 'office') ?? '');
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', $this->translator?->trans('admin.registry.unavailable', ['error' => $e->getMessage()], 'office') ?? $e->getMessage());
        }

        return $this->redirect((string) ($request->headers->get('referer') ?: $this->generateUrl('admin_crud_members_index')));
    }
}
