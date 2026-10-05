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
use Omnimeet\Direct\Signaling;
use Omnimeet\Exception\OmnimeetException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
 *
 * What carries the call is the room's gateway (glitchr/omnimeet): the page
 * asks it how this participant enters and renders that - omnimeet/direct's
 * engine on these endpoints, a provider's frame, or a button to its
 * address. Who gets in, who is there and when it ends are judged here, on
 * the Room, whatever the gateway.
 */
#[IsGranted('ROLE_USER')]
class VisioController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly Rooms $service,
        private readonly Signaling $signaling,
        private readonly TranslatorInterface $translator,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('/visio/{token}', name: 'office_visio_room', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function room(Request $request, string $token): Response
    {
        $room = $this->find($token, false);
        /** @var User $user */
        $user = $this->getUser();

        // How this participant enters, asked of the room's gateway - while the room is open only.
        $access = null;
        $unavailable = false;
        if ($room->isOpen()) {
            try {
                $access = $this->service->access($room, $user, $request->getLocale());
            } catch (OmnimeetException $e) {
                $this->logger?->error('The video gateway "{gateway}" could not let a participant in: {error}', ['gateway' => $room->getGateway(), 'error' => $e->getMessage(), 'exception' => $e]);
                $unavailable = true;
            }
        }

        $response = $this->render('@Office/client/visio/room.html.twig', [
            'room' => $room,
            'is_host' => $room->isHost($user),
            'open' => $room->isOpen() && null !== $access,
            'early' => new \DateTimeImmutable() < $room->getOpensAt(),
            'unavailable' => $unavailable,
            'access' => $access,
            'third_parties' => array_map(static fn (string $origin) => (string) parse_url($origin, \PHP_URL_HOST), $access?->origins ?? []),
        ]);
        // The page may use the camera and the microphone - and so may the gateway's frame, when it has one; nothing else.
        $allowed = 'self'.implode('', array_map(static fn (string $origin) => ' "'.$origin.'"', $access?->origins ?? []));
        $response->headers->set('Permissions-Policy', sprintf('camera=(%1$s), microphone=(%1$s), display-capture=(%1$s)', $allowed));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** The script of the room's gateway (omnimeet/direct's engine, the script that drives a provider's frame), served from its package. */
    #[Route('/visio/{token}/engine.js', name: 'office_visio_engine', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function engine(string $token): Response
    {
        $room = $this->find($token);
        try {
            $script = $this->service->access($room, $this->getUser())->script;
        } catch (OmnimeetException) {
            $script = null;
        }
        if (null === $script || !is_file($script)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($script, 200, ['Content-Type' => 'text/javascript; charset=utf-8'], false, null, true, true);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/visio/{token}/ice', name: 'office_visio_ice', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function ice(string $token): JsonResponse
    {
        $room = $this->find($token);
        try {
            $servers = $this->service->access($room, $this->getUser())->options['iceServers'] ?? [];
        } catch (OmnimeetException) {
            $servers = [];
        }

        return $this->json(['iceServers' => $servers], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/visio/{token}/signal', name: 'office_visio_signal_send', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function send(Request $request, string $token): JsonResponse
    {
        $room = $this->find($token);
        $data = json_decode($request->getContent(), true);
        // The handshake is relayed for a call from browser to browser only: another gateway's goes through its provider.
        if (!\is_array($data) || !isset($data['type']) || !$this->service->isDirect($room)) {
            return $this->json(['error' => 'bad_request'], 400);
        }
        try {
            $signal = $this->signaling->send($this->service->reference($room), Rooms::participantId($this->getUser()), (string) $data['type'], json_encode($data['payload'] ?? null, \JSON_THROW_ON_ERROR));
        } catch (\InvalidArgumentException|\JsonException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        if ($signal->connects()) {
            $this->service->connected($room);
        }

        return $this->json(['id' => $signal->id], 201);
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
            'signals' => $this->service->isDirect($room) ? $this->signaling->receive($this->service->reference($room), Rooms::participantId($user), $request->query->getInt('after')) : [],
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
