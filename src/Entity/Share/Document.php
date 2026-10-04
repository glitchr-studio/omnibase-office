<?php

namespace Base\Office\Entity\Share;

use App\Entity\User;
use Base\Office\Entity\Office;
use Base\Office\Enum\DocumentKind;
use Base\Office\Repository\Share\DocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A document kept for someone: a result, a prescription, a report, a deed.
 * Its file is encrypted on the private storage (libsodium secretstream, a
 * key per file, wrapped by the master key), its title and file name are
 * encrypted in the database; what stays readable is who it is for, who
 * sent it, its kind, size and dates - enough for the access rules and the
 * log, nothing of its content.
 *
 * `recipient` is whose document it is: the client it was sent to, or the
 * client who deposited it. `confidential` keeps its content from the staff
 * who did not write it (a secretary sees that it exists, not what it says).
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'office_document')]
#[ORM\Index(columns: ['createdAt'], name: 'office_document_created_idx')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $sender = null;

    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Office $office = null;

    #[ORM\Column(type: 'text')]
    protected string $titleCipher = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $filenameCipher = null;

    #[ORM\Column(length: 16, enumType: DocumentKind::class)]
    protected DocumentKind $kind = DocumentKind::OTHER;

    /** Where the encrypted file is, on the vault's storage. */
    #[ORM\Column(length: 255)]
    protected string $storagePath = '';

    /** The file's own key, encrypted by the master key (base64). */
    #[ORM\Column(type: 'text')]
    protected string $wrappedKey = '';

    /** SHA-256 of the clear file: checked again after each decryption. */
    #[ORM\Column(length: 64)]
    protected string $sha256 = '';

    #[ORM\Column(type: 'integer')]
    protected int $size = 0;

    #[ORM\Column(length: 120)]
    protected string $mimeType = 'application/octet-stream';

    #[ORM\Column(type: 'boolean')]
    protected bool $confidential = true;

    /** Where it came from: "mailbox:12", "visio:abc", "health:result". */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $context = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    public function __construct(?User $recipient = null, ?User $sender = null, DocumentKind $kind = DocumentKind::OTHER)
    {
        $this->recipient = $recipient;
        $this->sender = $sender;
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('Document #%d', $this->id);
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): ?User { return $this->recipient; }
    public function getSender(): ?User { return $this->sender; }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function getTitleCipher(): string { return $this->titleCipher; }
    public function setTitleCipher(string $cipher): self { $this->titleCipher = $cipher; return $this; }
    public function getFilenameCipher(): ?string { return $this->filenameCipher; }
    public function setFilenameCipher(?string $cipher): self { $this->filenameCipher = $cipher; return $this; }
    public function getKind(): DocumentKind { return $this->kind; }
    public function setKind(DocumentKind $kind): self { $this->kind = $kind; return $this; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getWrappedKey(): string { return $this->wrappedKey; }
    public function getSha256(): string { return $this->sha256; }
    public function getSize(): int { return $this->size; }
    public function getMimeType(): string { return $this->mimeType; }

    /** Set once, by the vault, after the file is written. */
    public function setFile(string $storagePath, string $wrappedKey, string $sha256, int $size, string $mimeType): self
    {
        $this->storagePath = $storagePath;
        $this->wrappedKey = $wrappedKey;
        $this->sha256 = $sha256;
        $this->size = $size;
        $this->mimeType = $mimeType;

        return $this;
    }

    public function isConfidential(): bool { return $this->confidential; }
    public function setConfidential(bool $confidential): self { $this->confidential = $confidential; return $this; }
    public function getContext(): ?string { return $this->context; }
    public function setContext(?string $context): self { $this->context = $context; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeInterface $at): self { $this->expiresAt = $at ? \DateTimeImmutable::createFromInterface($at) : null; return $this; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function markRead(): self { $this->readAt ??= new \DateTimeImmutable(); return $this; }
    public function isRead(): bool { return null !== $this->readAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(): self { $this->revokedAt ??= new \DateTimeImmutable(); return $this; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Still to be read at all: neither revoked nor expired. */
    public function isAvailable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function isImage(): bool
    {
        return \in_array($this->mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
