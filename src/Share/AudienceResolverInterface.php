<?php

namespace Base\Office\Share;

use Base\Office\Entity\Share\Document;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who else may see or read a document, as a regime decides: health lets the
 * care team read a patient's results (unless the patient objected) and the
 * secretariat see that they exist; a notary's office lets the partners read
 * the deeds. Autoconfigured (tag office.share_audience).
 *
 * decide() answers true (granted), false (refused: a refusal wins over any
 * grant) or null (no opinion).
 */
interface AudienceResolverInterface
{
    public const VIEW = 'OFFICE_DOCUMENT_VIEW';
    public const READ = 'OFFICE_DOCUMENT_READ';
    public const REVOKE = 'OFFICE_DOCUMENT_REVOKE';

    public function decide(string $attribute, Document $document, UserInterface $user): ?bool;
}
