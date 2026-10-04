<?php

namespace Base\Office\Security\Voter;

use Base\Office\Entity\Share\Document;
use Base\Office\Share\AudienceResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who may see that a document exists (VIEW: its title, its date), read it
 * (READ: open, download) or withdraw it (REVOKE):
 *
 * - the recipient sees and reads what is theirs; the sender too, and only
 *   the sender withdraws it;
 * - a revoked or expired document is read by nobody;
 * - anyone else only through the regime's AudienceResolverInterface - a
 *   refusal there wins over any grant. Without one, nobody else: not the
 *   site's administrators either, by design.
 */
final class DocumentVoter extends Voter
{
    /** @var iterable<AudienceResolverInterface> */
    private readonly iterable $resolvers;

    /** @param iterable<AudienceResolverInterface> $resolvers */
    public function __construct(#[AutowireIterator('office.share_audience')] iterable $resolvers = [])
    {
        $this->resolvers = $resolvers;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Document && \in_array($attribute, [AudienceResolverInterface::VIEW, AudienceResolverInterface::READ, AudienceResolverInterface::REVOKE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface || !$subject instanceof Document) {
            return false;
        }

        if (AudienceResolverInterface::READ === $attribute && !$subject->isAvailable()) {
            return false;
        }

        $isRecipient = self::same($subject->getRecipient(), $user);
        $isSender = self::same($subject->getSender(), $user);

        if (AudienceResolverInterface::REVOKE === $attribute) {
            return $isSender && !$subject->isRevoked();
        }
        if ($isRecipient || $isSender) {
            return true;
        }

        $granted = false;
        foreach ($this->resolvers as $resolver) {
            $decision = $resolver->decide($attribute, $subject, $user);
            if (false === $decision) {
                return false;
            }
            $granted = $granted || true === $decision;
        }

        return $granted;
    }

    private static function same(?object $a, object $b): bool
    {
        return null !== $a && method_exists($a, 'getId') && method_exists($b, 'getId') && null !== $a->getId() && $a->getId() === $b->getId();
    }
}
