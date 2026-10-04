<?php

namespace Base\Office\Controller\Client;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Enum\DocumentKind;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Exception\ShareException;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Share\DocumentVault;
use Base\Office\Visio\Rooms;
use Base\Office\Visio\Signaling;
use Base\Office\Visio\TurnCredentials;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The video room: its page (camera check, waiting room, the call), and the
 * small JSON endpoints its script asks - the ICE servers with fresh TURN
 * credentials, the handshake (post, poll), "I am here", a file dropped
 * during the call (kept in the vault for the guest). Only the room's two
 * participants get anything, and only while it is open.
 */
#[IsGranted('ROLE_USER')]
class VisioController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly Rooms $service,
        private readonly Signaling $signaling,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/visio/{token}', name: 'office_visio_room', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function room(string $token): Response
    {
        $room = $this->find($token, false);
        /** @var User $user */
        $user = $this->getUser();

        $response = $this->render('@Office/client/visio/room.html.twig', [
            'room' => $room,
            'is_host' => $room->isHost($user),
            'open' => $room->isOpen(),
            'early' => new \DateTimeImmutable() < $room->getOpensAt(),
        ]);
        // The page may use the camera and the microphone, and nothing else may frame it.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self)');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/visio/{token}/ice', name: 'office_visio_ice', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function ice(string $token, TurnCredentials $turn): JsonResponse
    {
        $this->find($token);

        return $this->json(['iceServers' => $turn->iceServers('u'.$this->getUser()->getId())], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/visio/{token}/signal', name: 'office_visio_signal_send', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function send(Request $request, string $token): JsonResponse
    {
        $room = $this->find($token);
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['type'])) {
            return $this->json(['error' => 'bad_request'], 400);
        }
        try {
            $signal = $this->signaling->send($room, $this->getUser(), (string) $data['type'], json_encode($data['payload'] ?? null, \JSON_THROW_ON_ERROR));
        } catch (\InvalidArgumentException|\JsonException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json(['id' => $signal->getId()], 201);
    }

    /** The other side's messages after `after`, and who is there. */
    #[Route('/visio/{token}/signal', name: 'office_visio_signal_poll', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function poll(Request $request, string $token): JsonResponse
    {
        // A closed room still answers its participants - that it is closed: the page then says so instead of waiting.
        $room = $this->find($token, false);
        /** @var User $user */
        $user = $this->getUser();
        if (!$room->isOpen()) {
            return $this->json(['signals' => [], 'host' => false, 'guest' => false, 'open' => false, 'ended' => true], 200, ['Cache-Control' => 'no-store']);
        }
        $this->service->heartbeat($room, $user);

        return $this->json([
            'signals' => $this->signaling->receive($room, $user, $request->query->getInt('after')),
            'host' => $room->isHostPresent(),
            'guest' => $room->isGuestPresent(),
            'open' => $room->isOpen(),
            'ended' => null !== $room->getEndedAt(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/visio/{token}/fin', name: 'office_visio_end', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function end(Request $request, string $token): Response
    {
        $room = $this->find($token, false);
        if (!$this->isCsrfTokenValid('office_visio_'.$room->getToken(), (string) $request->request->get('_token', $request->headers->get('X-CSRF-Token')))) {
            throw $this->createAccessDeniedException();
        }
        if ($room->isHost($this->getUser())) {
            $this->service->close($room);
        }

        return $request->isXmlHttpRequest() || 'json' === $request->getPreferredFormat() ? $this->json(['ended' => true]) : $this->redirectToRoute('office_visio_room', ['token' => $token]);
    }

    /** A file dropped during the call: kept in the vault for the guest, from whoever sent it. */
    #[Route('/visio/{token}/document', name: 'office_visio_document', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function document(Request $request, string $token, DocumentVault $vault): JsonResponse
    {
        $room = $this->find($token);
        if (!$this->isCsrfTokenValid('office_visio_'.$room->getToken(), (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'token'], 403);
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || null === $room->getGuest()) {
            return $this->json(['error' => 'empty'], 400);
        }
        try {
            $document = $vault->deposit($file, $room->getGuest(), $this->getUser(), (string) $request->request->get('title', $file->getClientOriginalName()), DocumentKind::OTHER, context: 'visio:'.$room->getId());
        } catch (ShareException $e) {
            return $this->json(['error' => $this->translator->trans($e->getKey(), $e->getParameters(), 'office')], 400);
        } catch (KeyMissingException) {
            return $this->json(['error' => $this->translator->trans('share.error.unavailable', [], 'office')], 503);
        }

        return $this->json(['id' => $document->getId(), 'url' => $this->generateUrl('office_document_download', ['id' => $document->getId()])], 201);
    }

    private function find(string $token, bool $mustBeOpen = true): Room
    {
        $room = $this->rooms->findOneByToken($token);
        if (null === $room || !$room->isParticipant($this->getUser())) {
            throw $this->createNotFoundException();
        }
        if ($mustBeOpen && !$room->isOpen()) {
            throw $this->createAccessDeniedException('The room is closed.');
        }

        return $room;
    }
}
