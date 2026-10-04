<?php

namespace Base\Office\Controller\Client;

use Base\Form\Model\ContactModel;
use Base\Form\Type\ContactType;
use Base\Office\Entity\ContactRequest;
use Base\Office\Repository\MemberRepository;
use Base\Office\Repository\OfficeRepository;
use Base\Office\Repository\WalkInRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The office's public pages: the team, a member's page, the hours and how
 * to come, the contact form. Each template can be the regime's own
 * (office.templates.*): health shows practitioners with their fees.
 */
class OfficeController extends AbstractController
{
    public function __construct(
        private readonly OfficeRepository $offices,
        private readonly MemberRepository $members,
        #[Autowire('%office.templates.team%')] private readonly string $teamTemplate,
        #[Autowire('%office.templates.member%')] private readonly string $memberTemplate,
        #[Autowire('%office.templates.hours%')] private readonly string $hoursTemplate,
        #[Autowire('%office.templates.contact%')] private readonly string $contactTemplate,
    ) {
    }

    #[Route('/equipe', name: 'office_team', methods: ['GET'])]
    public function team(): Response
    {
        $members = $this->members->findVisible();
        $groups = [];
        foreach ($members as $member) {
            $groups[$member->getCategory() ?? ''][] = $member;
        }

        return $this->render($this->teamTemplate, ['members' => $members, 'groups' => $groups, 'office' => $this->offices->findMain()]);
    }

    #[Route('/equipe/{slug}', name: 'office_member', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function member(string $slug): Response
    {
        $member = $this->members->findOneVisibleBySlug($slug) ?? throw $this->createNotFoundException();

        return $this->render($this->memberTemplate, ['member' => $member, 'office' => $this->offices->findMain()]);
    }

    #[Route('/horaires-acces', name: 'office_hours', methods: ['GET'])]
    public function hours(WalkInRepository $walkIns): Response
    {
        return $this->render($this->hoursTemplate, ['offices' => $this->offices->findOrdered(), 'walk_ins' => $walkIns->findActive()]);
    }

    #[Route('/contact', name: 'office_contact', methods: ['GET', 'POST'])]
    public function contact(
        Request $request,
        FormFactoryInterface $forms,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        #[Autowire('%office.recipient%')] string $recipient,
        #[Autowire('%office.sender%')] string $sender,
        #[Autowire('%office.contact.notice%')] string $notice,
    ): Response {
        $message = new ContactModel();
        $form = $forms->createNamed('contact', ContactType::class, $message, [
            'phone' => true, 'subject' => false, 'attachments' => false, 'buttons' => false, 'trap' => true,
            'privacy' => '@office.contact.privacy',
            'privacy_parameters' => ['months' => $this->getParameter('office.contact.retention_months')],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$message->isRobot()) {
                $entityManager->persist(new ContactRequest((string) $message->name, (string) $message->email, (string) $message->message, $message->phone ?? null));
                $entityManager->flush();
                $mailer->send((new TemplatedEmail())
                    ->from($sender)
                    ->to($recipient)
                    ->replyTo(new Address((string) $message->email, (string) $message->name))
                    ->subject(sprintf('[Contact] %s', $message->name))
                    ->htmlTemplate('@Office/email/contact.html.twig')
                    ->context(['contact' => $message]));
            }

            return $this->render($this->contactTemplate, ['sent' => true, 'office' => $this->offices->findMain(), 'notice' => $notice]);
        }

        return $this->render($this->contactTemplate, [
            'form' => $form,
            'sent' => false,
            'office' => $this->offices->findMain(),
            'notice' => $notice,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
