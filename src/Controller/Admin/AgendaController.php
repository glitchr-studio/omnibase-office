<?php

namespace Base\Office\Controller\Admin;

use App\Entity\User;
use Base\Office\Booking\Booker;
use Base\Office\Booking\SlotFinder;
use Base\Office\Entity\Booking\Appointment;
use Base\Office\Enum\AppointmentSource;
use Base\Office\Enum\AppointmentStatus;
use Base\Office\Exception\BookingException;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Repository\Booking\AbsenceRepository;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\Booking\AppointmentTypeRepository;
use Base\Office\Repository\Booking\ScheduleRepository;
use Base\Office\Repository\MemberRepository;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Service\ClientInvitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The agenda, in the back office: a day (every member a column) or a week
 * (one member, a day a column), drawn by hand on a quarter-hour grid; the
 * quick booking the secretariat takes on the phone - an existing client or
 * a new one, who is then invited to open their account; done, no-show,
 * cancel, move. A member sees their own columns' reasons; the others see
 * that the time is taken.
 */
#[IsGranted('ROLE_STAFF')]
class AgendaController extends AbstractController
{
    use AdminPageTrait;

    public const START_HOUR = 7;
    public const END_HOUR = 20;

    public function __construct(
        private readonly MemberRepository $members,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentTypeRepository $types,
        private readonly ScheduleRepository $schedules,
        private readonly AbsenceRepository $absences,
        private readonly RoomRepository $rooms,
        private readonly SlotFinder $slots,
        private readonly Booker $booker,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/agenda', name: 'office_admin_agenda', methods: ['GET'], defaults: ['_nest' => true])]
    public function index(Request $request): Response
    {
        $tz = $this->slots->timezone();
        $view = 'week' === $request->query->get('view') ? 'week' : 'day';
        try {
            $date = new \DateTimeImmutable($request->query->get('date', 'today').' 00:00', $tz);
        } catch (\Exception) {
            $date = new \DateTimeImmutable('today', $tz);
        }
        $members = array_values(array_filter($this->members->findBy(['active' => true], ['position' => 'ASC', 'displayName' => 'ASC']), static fn ($m) => $m->isBookable()));
        $mine = $this->members->findOneByUser($this->getUser());
        $selected = $request->query->getInt('member') ? $this->members->find($request->query->getInt('member')) : ($mine ?? ($members[0] ?? null));

        if ('week' === $view) {
            $from = $date->modify('monday this week');
            $columns = [];
            for ($i = 0; $i < 7; ++$i) {
                $day = $from->modify("+$i days");
                $columns[] = ['day' => $day, 'member' => $selected, 'label' => null];
            }
            $to = $from->modify('+7 days');
        } else {
            $from = $date;
            $to = $from->modify('+1 day');
            $columns = array_map(static fn ($m) => ['day' => $date, 'member' => $m, 'label' => $m->getDisplayName()], $members);
        }

        $waiting = [];
        foreach ($this->rooms->findWaiting() as $room) {
            $waiting[$room->getSubjectKey()] = $room;
        }

        foreach ($columns as &$column) {
            $column['blocks'] = [];
            $column['open'] = [];
            if (null === $column['member']) {
                continue;
            }
            $dayStart = $column['day'];
            $dayEnd = $dayStart->modify('+1 day');
            foreach ($this->schedules->findForMember($column['member']) as $schedule) {
                if ($schedule->appliesOn($dayStart)) {
                    $column['open'][] = $this->place($dayStart, $schedule->getStartsAt(), $schedule->getEndsAt());
                }
            }
            foreach ($this->absences->findOverlapping($column['member'], $dayStart, $dayEnd) as $absence) {
                $column['blocks'][] = ['kind' => 'absence'] + $this->placeMoments($dayStart, $absence->getStartsAt(), $absence->getEndsAt());
            }
            foreach ($this->appointments->findBetween($dayStart, $dayEnd, $column['member']) as $appointment) {
                $column['blocks'][] = [
                    'kind' => 'appointment',
                    'appointment' => $appointment,
                    'own' => null !== $mine && $appointment->getMember()->getId() === $mine->getId(),
                    'waiting' => isset($waiting[$appointment->getVisioKey()]),
                ] + $this->placeMoments($dayStart, $appointment->getStartsAt(), $appointment->getEndsAt());
            }
        }
        unset($column);

        return $this->page('@Office/admin/agenda.html.twig', [
            'view' => $view,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'columns' => $columns,
            'members' => $members,
            'selected' => $selected,
            'types' => $this->types->findBy(['active' => true], ['position' => 'ASC']),
            'requests' => $this->appointments->findRequested(),
            'start_hour' => self::START_HOUR,
            'end_hour' => self::END_HOUR,
            'timezone' => $tz->getName(),
            'statuses' => AppointmentStatus::cases(),
        ]);
    }

    /** The quick booking: taken on the phone or at the desk. */
    #[Route('/admin/agenda', name: 'office_admin_agenda_book', methods: ['POST'])]
    public function book(Request $request, ClientInvitations $invitations): Response
    {
        $this->assertToken($request, 'office-agenda');
        $member = $this->members->find($request->request->getInt('member'));
        $type = $this->types->find($request->request->getInt('type'));
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $request->request->getString('date').' '.$request->request->getString('time'), $this->slots->timezone());
        $back = $this->redirectToRoute('office_admin_agenda', ['date' => $request->request->getString('date'), 'view' => $request->request->getString('view', 'day'), 'member' => $request->request->getInt('member')]);
        if (null === $member || null === $type || false === $start) {
            $this->addFlash('danger', $this->translator->trans('admin.agenda.flash.incomplete', [], 'office'));

            return $back;
        }

        $email = mb_strtolower(trim($request->request->getString('email')));
        $name = trim($request->request->getString('name'));
        $phone = trim($request->request->getString('phone')) ?: null;
        $client = '' !== $email ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) : null;

        try {
            $appointment = $this->booker->book(
                $type,
                $member,
                $start,
                $client,
                'phone' === $request->request->getString('source') ? AppointmentSource::PHONE : AppointmentSource::STAFF,
                $request->request->getString('reason'),
                address: trim($request->request->getString('address')) ?: null,
                details: ['name' => $name ?: null, 'email' => $email ?: null, 'phone' => $phone, 'beneficiary' => trim($request->request->getString('beneficiary')) ?: null],
                by: $this->getUser(),
            );
            $this->addFlash('success', $this->translator->trans('admin.agenda.flash.booked', ['time' => $start->format('H:i'), 'member' => (string) $member], 'office'));
            if (null === $client && '' !== $email && $request->request->getBoolean('invite')) {
                $invitations->invite($email, $name ?: $email, $phone, $this->getUser());
                $this->addFlash('info', $this->translator->trans('admin.agenda.flash.invited', ['email' => $email], 'office'));
            }
        } catch (BookingException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
        } catch (KeyMissingException) {
            $this->addFlash('danger', $this->translator->trans('admin.agenda.flash.no_key', [], 'office'));
        }

        return $back;
    }

    /** done | no_show | cancel | confirm | move (with date and time). */
    #[Route('/admin/agenda/{id}/{action}', name: 'office_admin_agenda_action', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'done|no_show|cancel|confirm|move'])]
    public function action(Request $request, Appointment $appointment, string $action): Response
    {
        $this->assertToken($request, 'office-agenda-'.$appointment->getId());
        try {
            switch ($action) {
                case 'done':
                    $appointment->setStatus(AppointmentStatus::DONE);
                    $this->entityManager->flush();
                    break;
                case 'no_show':
                    $appointment->setStatus(AppointmentStatus::NO_SHOW);
                    $this->entityManager->flush();
                    break;
                case 'cancel':
                    $this->booker->cancel($appointment, true);
                    break;
                case 'confirm':
                case 'move':
                    $start = '' !== $request->request->getString('date')
                        ? \DateTimeImmutable::createFromFormat('Y-m-d H:i', $request->request->getString('date').' '.$request->request->getString('time'), $this->slots->timezone())
                        : null;
                    if (false === $start) {
                        throw new BookingException('booking.error.no_time');
                    }
                    'move' === $action && null !== $start ? $this->booker->move($appointment, $start) : $this->booker->confirm($appointment, $start);
                    break;
            }
            $this->addFlash('success', $this->translator->trans('admin.agenda.flash.'.$action, [], 'office'));
        } catch (BookingException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getKey(), $e->getParameters(), 'office'));
        }

        return $this->redirect((string) ($request->headers->get('referer') ?: $this->generateUrl('office_admin_agenda')));
    }

    /** @return array{top: float, height: float} percentages of the grid's height */
    private function place(\DateTimeImmutable $day, string $from, string $to): array
    {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));

        return $this->box($fh * 60 + $fm, $th * 60 + $tm);
    }

    private function placeMoments(\DateTimeImmutable $day, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $tz = $day->getTimezone();
        $start = max($day->getTimestamp(), $from->getTimestamp());
        $end = min($day->modify('+1 day')->getTimestamp(), $to->getTimestamp());
        $s = (new \DateTimeImmutable('@'.$start))->setTimezone($tz);
        $e = (new \DateTimeImmutable('@'.$end))->setTimezone($tz);
        $endMinutes = $e->format('Y-m-d') !== $s->format('Y-m-d') ? 1440 : (int) $e->format('G') * 60 + (int) $e->format('i');

        return $this->box((int) $s->format('G') * 60 + (int) $s->format('i'), $endMinutes);
    }

    private function box(int $fromMinutes, int $toMinutes): array
    {
        $first = self::START_HOUR * 60;
        $total = (self::END_HOUR - self::START_HOUR) * 60;
        $top = max(0, min($total, $fromMinutes - $first));
        $bottom = max(0, min($total, $toMinutes - $first));

        return ['top' => round($top / $total * 100, 3), 'height' => max(1.2, round(($bottom - $top) / $total * 100, 3))];
    }
}
