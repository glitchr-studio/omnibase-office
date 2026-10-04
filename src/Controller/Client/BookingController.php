<?php

namespace Base\Office\Controller\Client;

use App\Entity\User;
use Base\Office\Booking\Booker;
use Base\Office\Booking\BookingPolicy;
use Base\Office\Booking\CalendarEntries;
use Base\Office\Booking\SlotFinder;
use Base\Office\Entity\Booking\Appointment;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Enum\AppointmentSource;
use Base\Office\Enum\Channel;
use Base\Office\Exception\BookingException;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\Booking\AppointmentTypeRepository;
use Base\Office\Repository\MemberRepository;
use Base\Office\Booking\BeneficiaryProviderInterface;
use Base\Service\Calendar\GoogleCalendarLink;
use Base\Service\Calendar\Ics;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Booking online, one step a page so that it works on a phone and without
 * scripts: who → what for → when → (signed in) confirm → done. A type in
 * request mode asks instead of picking a slot. Cancelling needs the link
 * of the e-mail, or the client's space.
 */
class BookingController extends AbstractController
{
    /** @var iterable<BeneficiaryProviderInterface> */
    private readonly iterable $beneficiaries;

    /** @param iterable<BeneficiaryProviderInterface> $beneficiaries */
    public function __construct(
        private readonly MemberRepository $members,
        private readonly AppointmentTypeRepository $types,
        private readonly AppointmentRepository $appointments,
        private readonly SlotFinder $slots,
        private readonly BookingPolicy $policy,
        private readonly Booker $booker,
        private readonly TranslatorInterface $translator,
        #[AutowireIterator('office.beneficiary_provider')] iterable $beneficiaries = [],
        #[Autowire('%office.booking.horizon%')] private readonly int $horizon = 60,
    ) {
        $this->beneficiaries = $beneficiaries;
    }

    #[Route('/rendez-vous', name: 'office_booking', methods: ['GET'])]
    public function index(): Response
    {
        $groups = [];
        foreach ($this->members->findBookable() as $member) {
            if ([] !== array_filter($this->types->findForMember($member), fn (AppointmentType $t) => $this->policy->isListed($t, $member))) {
                $groups[$member->getCategory() ?? ''][] = $member;
            }
        }

        return $this->render('@Office/client/booking/index.html.twig', ['groups' => $groups]);
    }

    #[Route('/rendez-vous/{slug}', name: 'office_booking_member', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function member(#[MapEntity(mapping: ['slug' => 'slug'])] Member $member): Response
    {
        $types = array_values(array_filter($this->types->findForMember($member), fn (AppointmentType $t) => $this->policy->isListed($t, $member)));
        if (!$member->isBookable() || [] === $types) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Office/client/booking/member.html.twig', ['member' => $member, 'types' => $types]);
    }

    #[Route('/rendez-vous/{slug}/{type}', name: 'office_booking_slots', methods: ['GET', 'POST'], requirements: ['slug' => '[a-z0-9\-]+', 'type' => '[a-z0-9\-]+'])]
    public function slots(Request $request, #[MapEntity(mapping: ['slug' => 'slug'])] Member $member, #[MapEntity(mapping: ['type' => 'slug'])] AppointmentType $type): Response
    {
        if (!$this->policy->isListed($type, $member)) {
            throw $this->createNotFoundException();
        }
        if ($type->isRequest()) {
            return $this->requestForm($request, $member, $type);
        }

        $week = max(0, min((int) ceil($this->horizon / 7), $request->query->getInt('semaine')));
        $from = (new \DateTimeImmutable('now', $this->slots->timezone()))->setTime(0, 0)->modify(sprintf('+%d days', $week * 7));
        $to = $from->modify('+7 days');
        $days = [];
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = ['date' => $d, 'slots' => []];
        }
        foreach ($this->slots->find($member, $type, $from, $to) as $slot) {
            $days[$slot->start->format('Y-m-d')]['slots'][] = $slot;
        }

        return $this->render('@Office/client/booking/slots.html.twig', [
            'member' => $member,
            'type' => $type,
            'days' => $days,
            'week' => $week,
            'last_week' => (int) ceil($this->horizon / 7) - 1,
            'next' => array_sum(array_map(static fn ($d) => \count($d['slots']), $days)) ? null : $this->slots->next($member, $type, 1)[0] ?? null,
        ]);
    }

    #[Route('/rendez-vous/{slug}/{type}/{start}', name: 'office_booking_confirm', methods: ['GET', 'POST'], requirements: ['slug' => '[a-z0-9\-]+', 'type' => '[a-z0-9\-]+', 'start' => '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}'])]
    #[IsGranted('ROLE_USER')]
    public function confirm(Request $request, #[MapEntity(mapping: ['slug' => 'slug'])] Member $member, #[MapEntity(mapping: ['type' => 'slug'])] AppointmentType $type, string $start): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $start, $this->slots->timezone());
        if (false === $at || $type->isRequest() || !$this->policy->isListed($type, $member)) {
            throw $this->createNotFoundException();
        }
        if (!$this->policy->canBook($type, $member, $user)) {
            $this->addFlash('warning', $this->translator->trans('booking.error.not_bookable', [], 'office'));

            return $this->redirectToRoute('office_booking_member', ['slug' => $member->getSlug()]);
        }
        $beneficiaries = $this->beneficiaryChoices($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('office_booking', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $for = (string) $request->request->get('beneficiary', '');
            try {
                $appointment = $this->booker->book(
                    $type,
                    $member,
                    $at,
                    $user,
                    AppointmentSource::ONLINE,
                    (string) $request->request->get('reason', ''),
                    address: Channel::HOME === $type->getChannel() ? trim((string) $request->request->get('address')) ?: null : null,
                    details: ['phone' => trim((string) $request->request->get('phone')) ?: null, 'beneficiary' => $beneficiaries[$for]['label'] ?? null],
                    meta: isset($beneficiaries[$for]) ? $beneficiaries[$for]['meta'] : [],
                );

                return $this->redirectToRoute('office_booking_done', ['id' => $appointment->getId()]);
            } catch (BookingException $e) {
                $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));

                return $this->redirectToRoute('office_booking_slots', ['slug' => $member->getSlug(), 'type' => $type->getSlug()]);
            }
        }

        if (!$this->slots->isFree($member, $type, $at)) {
            $this->addFlash('warning', $this->translator->trans('booking.error.slot_taken', [], 'office'));

            return $this->redirectToRoute('office_booking_slots', ['slug' => $member->getSlug(), 'type' => $type->getSlug()]);
        }

        return $this->render('@Office/client/booking/confirm.html.twig', ['member' => $member, 'type' => $type, 'start' => $at, 'beneficiaries' => $beneficiaries]);
    }

    #[Route('/rendez-vous/confirmation/{id}', name: 'office_booking_done', methods: ['GET'], requirements: ['id' => '\d+'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function done(Appointment $appointment, CalendarEntries $entries, GoogleCalendarLink $google): Response
    {
        $this->assertOwn($appointment);
        $entry = $entries->for($appointment);

        return $this->render('@Office/client/booking/done.html.twig', ['appointment' => $appointment, 'google' => $entry ? $google->for($entry) : null]);
    }

    #[Route('/rendez-vous/{id}.ics', name: 'office_booking_ics', methods: ['GET'], requirements: ['id' => '\d+'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function ics(Appointment $appointment, CalendarEntries $entries, Ics $ics, Request $request): Response
    {
        $this->assertOwn($appointment);
        $entry = $entries->for($appointment, $request->getHost()) ?? throw $this->createNotFoundException();

        return new Response($ics->calendar([$entry], $this->translator->trans('calendar.name', [], 'office')), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rendez-vous.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The link of the e-mail: no sign-in needed, the token is the proof. */
    #[Route('/rendez-vous/annuler/{token}', name: 'office_booking_cancel', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{48}'], priority: 10)]
    public function cancel(Request $request, string $token): Response
    {
        $appointment = $this->appointments->findOneByCancelToken($token);
        if (null === $appointment) {
            throw $this->createNotFoundException();
        }
        $cancelled = false;
        if ($request->isMethod('POST') && $appointment->isActive()) {
            if (!$this->isCsrfTokenValid('office_cancel', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $this->booker->cancel($appointment);
                $cancelled = true;
            } catch (BookingException $e) {
                $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
            }
        }

        return $this->render('@Office/client/booking/cancel.html.twig', ['appointment' => $appointment, 'token' => $token, 'cancelled' => $cancelled]);
    }

    #[Route('/espace/rendez-vous', name: 'office_space_appointments', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function space(): Response
    {
        return $this->render('@Office/client/space/appointments.html.twig', [
            'upcoming' => $this->appointments->findForClient($this->getUser(), true),
            'past' => $this->appointments->findForClient($this->getUser(), false, 20),
        ]);
    }

    #[Route('/espace/rendez-vous/{id}/annuler', name: 'office_space_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function spaceCancel(Request $request, Appointment $appointment): Response
    {
        $this->assertOwn($appointment);
        if (!$this->isCsrfTokenValid('office_cancel_'.$appointment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->booker->cancel($appointment);
            $this->addFlash('success', $this->translator->trans('booking.flash.cancelled', [], 'office'));
        } catch (BookingException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('office_space_appointments'));
    }

    private function requestForm(Request $request, Member $member, AppointmentType $type): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_USER');
            if (!$this->isCsrfTokenValid('office_request', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $beneficiaries = $this->beneficiaryChoices($this->getUser());
            $for = (string) $request->request->get('beneficiary', '');
            try {
                $appointment = $this->booker->request($type, $member, $this->getUser(), (string) $request->request->get('reason', ''), trim((string) $request->request->get('address')) ?: null, ['phone' => trim((string) $request->request->get('phone')) ?: null, 'beneficiary' => $beneficiaries[$for]['label'] ?? null], $beneficiaries[$for]['meta'] ?? []);

                return $this->redirectToRoute('office_booking_done', ['id' => $appointment->getId()]);
            } catch (BookingException $e) {
                $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
            }
        }

        return $this->render('@Office/client/booking/request.html.twig', ['member' => $member, 'type' => $type, 'beneficiaries' => $this->getUser() ? $this->beneficiaryChoices($this->getUser()) : []]);
    }

    /** @return array<string, array{label: string, meta: array}> */
    private function beneficiaryChoices(?object $user): array
    {
        $choices = [];
        if (null === $user) {
            return $choices;
        }
        foreach ($this->beneficiaries as $provider) {
            foreach ($provider->beneficiaries($user) as $key => $choice) {
                $choices[$key] = $choice;
            }
        }

        return $choices;
    }

    private function assertOwn(Appointment $appointment): void
    {
        $user = $this->getUser();
        if (null === $appointment->getClient() || null === $user || $appointment->getClient()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
