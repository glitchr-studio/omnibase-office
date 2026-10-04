<?php

namespace Base\Office\Command;

use Base\Office\Enum\AppointmentStatus;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Visio\Rooms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The day closed, from the cron container each night: a confirmed
 * appointment now past is DONE - a video one whose guest never came into
 * the room is a NO_SHOW (in person, the staff marks it) - and the closed
 * rooms' handshakes are purged.
 */
#[AsCommand(name: 'office:booking:close-day', description: 'Close past appointments (done, or no-show for video ones never joined) and purge closed video rooms')]
class CloseDayCommand extends Command
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly RoomRepository $rooms,
        private readonly Rooms $roomService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = $missed = 0;
        foreach ($this->appointments->findToClose(new \DateTimeImmutable()) as $appointment) {
            $room = $appointment->isVideo() ? $this->rooms->findOneBySubject($appointment->getVisioKey()) : null;
            if ($appointment->isVideo() && (null === $room || null === $room->getGuestSeenAt())) {
                $appointment->setStatus(AppointmentStatus::NO_SHOW);
                ++$missed;
            } else {
                $appointment->setStatus(AppointmentStatus::DONE);
                ++$done;
            }
        }
        $this->entityManager->flush();
        $purged = $this->roomService->purge();
        $output->writeln(sprintf('%d done, %d no-show, %d signal(s) purged.', $done, $missed, $purged));

        return Command::SUCCESS;
    }
}
