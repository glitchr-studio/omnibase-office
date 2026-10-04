<?php

namespace Base\Office\Entity\Share;

use App\Entity\User;
use Base\Office\Enum\AccessAction;
use Base\Office\Repository\Share\AccessLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who did what with a document, when, from where: each view, download,
 * deposit, revocation - and each refusal. Rows are never edited; the
 * document id stays when the document itself is gone.
 */
#[ORM\Entity(repositoryClass: AccessLogRepository::class)]
#[ORM\Table(name: 'office_access_log')]
#[ORM\Index(columns: ['documentId'], name: 'office_access_log_document_idx')]
#[ORM\Index(columns: ['createdAt'], name: 'office_access_log_created_idx')]
class AccessLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(type: 'integer')]
    protected int $documentId;

    /** Whose document it was, kept for the patient's own history even after deletion. */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $recipientId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $user = null;

    /** The account's name at the time: it stays readable if the account is deleted. */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $userLabel = null;

    #[ORM\Column(length: 16, enumType: AccessAction::class)]
    protected AccessAction $action;

    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $userAgent = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    public function __construct(int $documentId, AccessAction $action, ?User $user = null, ?string $ip = null, ?string $userAgent = null, ?int $recipientId = null)
    {
        $this->documentId = $documentId;
        $this->action = $action;
        $this->user = $user;
        $this->userLabel = $user ? mb_substr((string) $user, 0, 180) : null;
        $this->ip = $ip;
        $this->userAgent = $userAgent ? mb_substr($userAgent, 0, 255) : null;
        $this->recipientId = $recipientId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocumentId(): int { return $this->documentId; }
    public function getRecipientId(): ?int { return $this->recipientId; }
    public function getUser(): ?User { return $this->user; }
    public function getUserLabel(): ?string { return $this->userLabel; }
    public function getAction(): AccessAction { return $this->action; }
    public function getIp(): ?string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
