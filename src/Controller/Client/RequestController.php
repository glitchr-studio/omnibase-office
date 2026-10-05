<?php

namespace Base\Office\Controller\Client;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bundle's own page for a first appointment asked without an account,
 * for a practice whose regime has none of its own. Off by default
 * (office.booking.public_request: true opens it): the booking pages ask to
 * sign in, and whether someone unknown may ask is the practice's choice.
 *
 * A regime's own route at the same address (notary_request, lawyer_request,
 * priority 10) comes first.
 */
class RequestController extends AbstractRequestController
{
    public function __construct(
        #[Autowire('%office.booking.public_request%')] private readonly bool $enabled = false,
        #[Autowire('%office.templates.request%')] private readonly string $template = '@Office/client/request.html.twig',
    ) {
    }

    #[Route('/rendez-vous/demande', name: 'office_request', methods: ['GET', 'POST'], priority: 5)]
    public function request(Request $request): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }

        return $this->respond($request, $this->template, 'office', '@office.appointment_request', 'appointment_request.unavailable');
    }
}
