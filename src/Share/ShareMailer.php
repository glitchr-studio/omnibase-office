<?php

namespace Base\Office\Share;

use Base\Office\Event\DocumentEvent;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "A new document awaits you in your space": the recipient is told, and
 * sent to sign in. The e-mail never holds the document, its title, its
 * kind, nor a link that would open it without signing in - an e-mail is
 * not a safe place for anything confidential (and for health data, not a
 * lawful one).
 */
final class ShareMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire('%office.sender%')] private readonly string $sender,
        #[Autowire('%office.share.space_route%')] private readonly string $spaceRoute,
    ) {
    }

    #[AsEventListener(event: DocumentEvent::DEPOSITED)]
    public function onDeposited(DocumentEvent $event): void
    {
        $recipient = $event->document->getRecipient();
        if (!$event->notify || null === $recipient || !$recipient->getEmail()) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->from($this->sender)
            ->to($recipient->getEmail())
            ->subject($this->translator->trans('share.email.subject', [], 'office'))
            ->htmlTemplate('@Office/email/document.html.twig')
            ->context([
                'space_url' => $this->urls->generate($this->spaceRoute, [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }
}
