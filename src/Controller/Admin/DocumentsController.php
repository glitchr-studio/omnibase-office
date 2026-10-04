<?php

namespace Base\Office\Controller\Admin;

use App\Entity\User;
use Base\Office\Enum\DocumentKind;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Exception\ShareException;
use Base\Office\Repository\Share\AccessLogRepository;
use Base\Office\Repository\Share\DocumentRepository;
use Base\Office\Share\AudienceResolverInterface;
use Base\Office\Share\DocumentVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The vault from the office's side: sending a document to a client (who is
 * mailed "a document awaits", nothing more), the list of what was sent -
 * a title is shown only to whom the DocumentVoter lets see it -, and the
 * access log for the coordination.
 */
#[IsGranted('ROLE_STAFF')]
class DocumentsController extends AbstractController
{
    use AdminPageTrait;

    public function __construct(
        private readonly DocumentVault $vault,
        private readonly DocumentRepository $documents,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/documents', name: 'office_admin_documents', methods: ['GET'], defaults: ['_nest' => true])]
    public function index(Request $request): Response
    {
        $recipient = $request->query->getInt('client') ? $this->entityManager->getRepository(User::class)->find($request->query->getInt('client')) : null;
        $list = $recipient
            ? $this->documents->findForRecipient($recipient, true)
            : $this->documents->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->page('@Office/admin/documents.html.twig', [
            'documents' => $list,
            'recipient' => $recipient,
            'ready' => $this->vault->isReady(),
            'kinds' => DocumentKind::cases(),
        ]);
    }

    #[Route('/admin/documents', name: 'office_admin_documents_send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $this->assertToken($request, 'office-documents');
        /** @var User $sender */
        $sender = $this->getUser();
        $email = mb_strtolower(trim($request->request->getString('email')));
        $recipient = $request->request->getInt('client')
            ? $this->entityManager->getRepository(User::class)->find($request->request->getInt('client'))
            : ('' !== $email ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) : null);
        $file = $request->files->get('file');

        if (null === $recipient) {
            $this->addFlash('danger', $this->translator->trans('admin.documents.flash.no_recipient', [], 'office'));
        } elseif (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', $this->translator->trans('share.error.empty', [], 'office'));
        } else {
            try {
                $expires = '' !== $request->request->getString('expires') ? new \DateTimeImmutable($request->request->getString('expires').' 23:59:59') : null;
                $this->vault->deposit(
                    $file,
                    $recipient,
                    $sender,
                    $request->request->getString('title'),
                    DocumentKind::tryFrom($request->request->getString('kind')) ?? DocumentKind::OTHER,
                    confidential: $request->request->getBoolean('confidential', true),
                    context: 'office:sent',
                    expiresAt: $expires,
                );
                $this->addFlash('success', $this->translator->trans('admin.documents.flash.sent', [], 'office'));
            } catch (ShareException $e) {
                $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
            } catch (KeyMissingException) {
                $this->addFlash('danger', $this->translator->trans('share.error.unavailable', [], 'office'));
            }
        }

        return $this->redirectToRoute('office_admin_documents', $recipient ? ['client' => $recipient->getId()] : []);
    }

    #[Route('/admin/documents/{id}/revoke', name: 'office_admin_documents_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(Request $request, int $id): Response
    {
        $this->assertToken($request, 'office-documents-'.$id);
        $document = $this->documents->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(AudienceResolverInterface::REVOKE, $document);
        $this->vault->revoke($document, $this->getUser());
        $this->addFlash('success', $this->translator->trans('share.flash.revoked', [], 'office'));

        return $this->redirect((string) ($request->headers->get('referer') ?: $this->generateUrl('office_admin_documents')));
    }

    /** Who looked at what: for the coordination only. */
    #[Route('/admin/access-log', name: 'office_admin_access_log', methods: ['GET'], defaults: ['_nest' => true])]
    #[IsGranted('ROLE_ADMIN')]
    public function log(Request $request, AccessLogRepository $logs): Response
    {
        return $this->page('@Office/admin/access_log.html.twig', [
            'logs' => $logs->findLatest(300, $request->query->getInt('document') ?: null),
            'document' => $request->query->getInt('document') ?: null,
        ]);
    }
}
