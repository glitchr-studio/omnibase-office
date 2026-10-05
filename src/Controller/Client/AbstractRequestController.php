<?php

namespace Base\Office\Controller\Client;

use Base\Office\Booking\AppointmentRequests;
use Base\Office\Exception\BookingException;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Form\AppointmentRequestType;
use Base\Office\Repository\OfficeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page where a first appointment is asked without an account
 * (Base\Office\Booking\AppointmentRequests), for the bundle's own route and
 * for a regime's: the regime routes an action of its own and answers with
 * respond(), naming its template, the translation domain of its error and
 * the prefix of its labels.
 *
 *     #[Route('/rendez-vous/demande', name: 'notary_request', methods: ['GET', 'POST'], priority: 10)]
 *     public function request(Request $request): Response
 *     {
 *         return $this->respond($request, '@Notary/client/request.html.twig', 'notary', '@notary.request');
 *     }
 *
 * The template receives `form`, `sent`, `available` (something can be asked
 * for) and `office` (the main one).
 */
abstract class AbstractRequestController extends AbstractController
{
    protected AppointmentRequests $requests;
    protected OfficeRepository $offices;
    protected TranslatorInterface $translator;

    #[Required]
    public function setRequestServices(AppointmentRequests $requests, OfficeRepository $offices, TranslatorInterface $translator): void
    {
        $this->requests = $requests;
        $this->offices = $offices;
        $this->translator = $translator;
    }

    /**
     * @param string $domain         where $unavailableKey (the vault has no key: nothing can be kept) is translated
     * @param string $labelPrefix    the prefix of the form's labels (AppointmentRequestType's label_prefix)
     * @param string $unavailableKey that error's key
     */
    protected function respond(Request $request, string $template, string $domain = 'office', string $labelPrefix = '@office.appointment_request', string $unavailableKey = 'request.unavailable'): Response
    {
        $offer = $this->requests->offer();
        $user = $this->getUser();
        $model = $this->requests->prepare($offer, $user, (string) $request->query->get('avec'));

        $form = $this->createForm(AppointmentRequestType::class, $model, [
            'types' => $offer['types'],
            'members' => $offer['members'],
            'privacy_parameters' => ['months' => $this->getParameter('office.contact.retention_months')],
            'label_prefix' => $labelPrefix,
        ]);
        $form->handleRequest($request);
        $sent = false;

        if ($form->isSubmitted() && $form->isValid()) {
            if ($model->isRobot()) {
                $sent = true;      // told it went well, nothing kept
            } else {
                try {
                    $this->requests->send($model, $user);
                    $sent = true;
                } catch (BookingException $e) {
                    $form->addError(new FormError($this->translator->trans($e->getKey(), $e->getParameters(), 'office')));
                } catch (KeyMissingException) {
                    $form->addError(new FormError($this->translator->trans($unavailableKey, [], $domain)));
                }
            }
        }

        return $this->render($template, [
            'form' => $form,
            'sent' => $sent,
            'available' => [] !== $offer['types'],
            'office' => $this->offices->findMain(),
        ], new Response(null, $form->isSubmitted() && !$sent ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
