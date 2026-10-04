<?php

namespace Base\Office\Controller\Client;

use App\Entity\User;
use Base\Office\Entity\Share\Document;
use Base\Office\Enum\AccessAction;
use Base\Office\Enum\DocumentKind;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Exception\ShareException;
use Base\Office\Repository\Share\DocumentRepository;
use Base\Office\Share\AudienceResolverInterface;
use Base\Office\Share\DocumentVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The client's documents: the list, a download, a deposit. A file only
 * ever leaves through here: signed in, allowed by the DocumentVoter,
 * written in the access log, sent with no-store, nosniff and as an
 * attachment (an image may be shown inline, sandboxed, in a conversation).
 * Someone else's document answers 404, and the refusal is logged.
 */
#[IsGranted('ROLE_USER')]
class ShareController extends AbstractController
{
    public function __construct(
        private readonly DocumentVault $vault,
        private readonly DocumentRepository $documents,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/espace/documents', name: 'office_space_documents', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('@Office/client/space/documents.html.twig', [
            'documents' => $this->documents->findForRecipient($user),
            'ready' => $this->vault->isReady(),
            'kinds' => [DocumentKind::PRESCRIPTION, DocumentKind::UPLOAD, DocumentKind::OTHER],
        ]);
    }

    #[Route('/espace/documents/{id}/telecharger', name: 'office_document_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $document = $this->documents->find($id);
        if (null === $document) {
            throw $this->createNotFoundException();
        }
        if (!$this->isGranted(AudienceResolverInterface::READ, $document)) {
            $this->vault->log($document, AccessAction::DENIED, $user);
            throw $this->createNotFoundException();
        }

        $inline = $request->query->getBoolean('inline') && $document->isImage();
        $stream = $this->vault->open($document);
        $this->vault->log($document, $inline ? AccessAction::VIEW : AccessAction::DOWNLOAD, $user);
        if ($document->getRecipient()?->getId() === $user->getId()) {
            $document->markRead();
            $this->entityManager->flush();
        }

        $response = new StreamedResponse(static function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $inline ? $document->getMimeType() : 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $this->vault->filename($document), 'document-'.$document->getId()));
        $response->headers->set('Content-Length', (string) $document->getSize());
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** A deposit by the client: a prescription to bring, an insurance card - theirs, shown to whom the regime says. */
    #[Route('/espace/documents/deposer', name: 'office_document_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('office_deposit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();
        $file = $request->files->get('file');
        $kind = DocumentKind::tryFrom((string) $request->request->get('kind')) ?? DocumentKind::UPLOAD;
        if (!\in_array($kind, [DocumentKind::PRESCRIPTION, DocumentKind::UPLOAD, DocumentKind::OTHER], true)) {
            $kind = DocumentKind::UPLOAD;
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', $this->translator->trans('share.error.empty', [], 'office'));

            return $this->redirectToRoute('office_space_documents');
        }
        try {
            $this->vault->deposit($file, $user, $user, (string) $request->request->get('title', ''), $kind, confidential: true, context: 'deposit');
            $this->addFlash('success', $this->translator->trans('share.flash.deposited', [], 'office'));
        } catch (ShareException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
        } catch (KeyMissingException) {
            $this->addFlash('danger', $this->translator->trans('share.error.unavailable', [], 'office'));
        }

        return $this->redirectToRoute('office_space_documents');
    }

    #[Route('/espace/documents/{id}/retirer', name: 'office_document_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(Request $request, Document $document): Response
    {
        if (!$this->isCsrfTokenValid('office_revoke_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isGranted(AudienceResolverInterface::REVOKE, $document)) {
            throw $this->createNotFoundException();
        }
        $this->vault->revoke($document, $this->getUser());
        $this->addFlash('success', $this->translator->trans('share.flash.revoked', [], 'office'));

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('office_space_documents'));
    }
}
