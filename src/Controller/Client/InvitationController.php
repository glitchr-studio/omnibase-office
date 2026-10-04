<?php

namespace Base\Office\Controller\Client;

use Base\Office\Service\ClientInvitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The invitation's link: choose an identifier and a password, the account is opened. */
class InvitationController extends AbstractController
{
    #[Route('/invitation/{token}', name: 'office_invitation', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{48}'])]
    public function accept(Request $request, string $token, ClientInvitations $invitations, EntityManagerInterface $entityManager, Security $security, TranslatorInterface $translator): Response
    {
        $invitation = $invitations->find($token);
        if (null === $invitation) {
            return $this->render('@Office/client/invitation.html.twig', ['invitation' => null, 'errors' => []], new Response(null, Response::HTTP_GONE));
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('office_invitation', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $username = trim((string) $request->request->get('username'));
            $password = (string) $request->request->get('password');
            if (!preg_match('/^[A-Za-z0-9._\-]{3,40}$/', $username)) {
                $errors[] = $translator->trans('invitation.error.username', [], 'office');
            } elseif (null !== $entityManager->getRepository(\App\Entity\User::class)->findOneBy(['username' => $username])) {
                $errors[] = $translator->trans('invitation.error.taken', [], 'office');
            }
            if (mb_strlen($password) < 10) {
                $errors[] = $translator->trans('invitation.error.password', [], 'office');
            }
            if (null !== $entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $invitation->getEmail()])) {
                $errors[] = $translator->trans('invitation.error.exists', [], 'office');
            }
            if ([] === $errors) {
                $user = $invitations->accept($invitation, $username, $password);
                $this->addFlash('success', $translator->trans('invitation.flash.welcome', [], 'office'));
                try {
                    // Signed in at once where the firewall has a single way in; else through the sign-in page.
                    $security->login($user);
                } catch (\Throwable) {
                    return $this->redirectToRoute('security_login');
                }

                return $this->redirectToRoute($this->getParameter('office.booking.space_route'));
            }
        }

        return $this->render('@Office/client/invitation.html.twig', ['invitation' => $invitation, 'errors' => $errors], new Response(null, [] !== $errors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
