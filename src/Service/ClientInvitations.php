<?php

namespace Base\Office\Service;

use App\Entity\User;
use Base\Office\Entity\Invitation;
use Base\Office\Event\ClientAccountEvent;
use Base\Service\Invitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Clients are invited, on omnibase's Invitations: a link mailed (the token
 * is never stored, only its hash), valid two weeks; accepting it creates
 * the account - the link proved the address - and attaches the
 * appointments taken for that address before the account existed.
 */
class ClientInvitations
{
    public const DAYS = 14;

    public function __construct(
        private readonly Invitations $invitations,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%office.sender%')] private readonly string $sender,
    ) {
    }

    public function invite(string $email, string $name, ?string $phone = null, ?User $by = null): Invitation
    {
        $token = Invitations::token();
        $invitation = new Invitation(mb_strtolower(trim($email)), trim($name), Invitations::hash($token), Invitations::expiry(self::DAYS));
        $invitation->setPhone($phone)->setInvitedBy($by);
        $this->invitations->save($invitation);

        $this->mailer->send((new TemplatedEmail())
            ->from($this->sender)
            ->to($invitation->getEmail())
            ->subject($this->translator->trans('invitation.email.subject', [], 'office'))
            ->htmlTemplate('@Office/email/invitation.html.twig')
            ->context(['invitation' => $invitation, 'url' => $this->urls->generate('office_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL), 'days' => self::DAYS]));

        return $invitation;
    }

    public function find(string $token): ?Invitation
    {
        return $this->invitations->findOpen(Invitation::class, $token);
    }

    public function accept(Invitation $invitation, string $username, string $plainPassword): User
    {
        $user = $this->invitations->createUser($invitation, $username, $plainPassword);
        $this->attach($user, $invitation->getEmail());
        $this->invitations->markAccepted($invitation);
        $this->dispatcher->dispatch(new ClientAccountEvent($user, $invitation), ClientAccountEvent::CREATED);

        return $user;
    }

    /** The appointments taken for that address, without an account, are theirs now. */
    public function attach(User $user, string $email): int
    {
        return $this->entityManager->createQuery('UPDATE '.\Base\Office\Entity\Booking\Appointment::class.' a SET a.client = :user WHERE a.client IS NULL AND LOWER(a.clientEmail) = :email')
            ->setParameter('user', $user)->setParameter('email', mb_strtolower($email))->execute();
    }
}
