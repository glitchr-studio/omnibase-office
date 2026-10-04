<?php

namespace Base\Office\Command;

use Base\Office\Event\AppointmentEvent;
use Base\Office\Repository\Booking\AppointmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The reminders, from the cron container every quarter of an hour: each
 * confirmed appointment gets one reminder per office.booking.reminders
 * entry (24 and 2 hours before by default), once.
 */
#[AsCommand(name: 'office:booking:remind', description: 'Send the appointment reminders that are due')]
class RemindCommand extends Command
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%office.booking.reminders%')] private readonly array $reminders = [24, 2],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $sent = 0;
        rsort($this->reminders);
        foreach ($this->appointments->findConfirmedStartingBetween($now, $now->modify(sprintf('+%d hours', max($this->reminders ?: [0])))) as $appointment) {
            $left = ($appointment->getStartsAt()->getTimestamp() - $now->getTimestamp()) / 3600;
            // The tightest reminder already due, once: a 2-hour reminder does not also send the 24-hour one late.
            $due = null;
            foreach ($this->reminders as $hours) {
                if ($left <= $hours) {
                    $due = $hours;
                }
            }
            if (null === $due || $appointment->hasReminderBeenSent($due)) {
                continue;
            }
            foreach ($this->reminders as $hours) {
                if ($hours >= $due) {
                    $appointment->markReminderSent($hours);
                }
            }
            $this->entityManager->flush();
            $this->dispatcher->dispatch(new AppointmentEvent($appointment, hoursBefore: $due), AppointmentEvent::REMINDER);
            ++$sent;
        }
        $output->writeln(sprintf('%d reminder(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
