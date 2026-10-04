<?php

namespace Base\Office\Share;

use App\Entity\User;
use Base\Office\Entity\Office;
use Base\Office\Entity\Share\AccessLog;
use Base\Office\Entity\Share\Document;
use Base\Office\Enum\AccessAction;
use Base\Office\Enum\DocumentKind;
use Base\Office\Event\DocumentEvent;
use Base\Office\Exception\CorruptedException;
use Base\Office\Exception\ShareException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The document vault: a file in, encrypted to the private storage, a
 * Document row and an access log line out; and back, decrypted, checked
 * against its SHA-256, for whoever the DocumentVoter lets read it. Who may
 * is decided before calling here: the vault does what it is told and
 * writes down that it did.
 */
class DocumentVault
{
    public function __construct(
        private readonly Cipher $cipher,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'office.share.storage')] private readonly FilesystemOperator $storage,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RequestStack $requestStack,
        #[Autowire('%office.share.max_size%')] private readonly int $maxSize = 20971520,
        #[Autowire('%office.share.mime_types%')] private readonly array $mimeTypes = [],
    ) {
    }

    public function isReady(): bool
    {
        return $this->cipher->isConfigured();
    }

    /**
     * Keep a file for $recipient. The cipher refuses first when there is no
     * key: nothing is written at all.
     *
     * @param File|string $file an upload, a file, or a path
     */
    public function deposit(
        File|string $file,
        User $recipient,
        ?User $sender,
        string $title,
        DocumentKind $kind = DocumentKind::OTHER,
        ?string $filename = null,
        bool $confidential = true,
        ?string $context = null,
        ?\DateTimeInterface $expiresAt = null,
        ?Office $office = null,
        bool $notify = true,
    ): Document {
        $path = $file instanceof File ? $file->getPathname() : $file;
        $filename ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        if (!is_file($path) || 0 === ($size = (int) filesize($path))) {
            throw new ShareException('share.error.empty');
        }
        if ($size > $this->maxSize) {
            throw new ShareException('share.error.too_large', ['max' => (int) round($this->maxSize / 1048576)]);
        }
        $mime = (new MimeTypes())->guessMimeType($path) ?? 'application/octet-stream';
        if ([] !== $this->mimeTypes && !\in_array($mime, $this->mimeTypes, true)) {
            throw new ShareException('share.error.type', ['type' => $mime]);
        }

        $document = new Document($recipient, $sender, $kind);
        $document->setTitleCipher((string) $this->cipher->encryptText(trim($title) ?: $filename))
            ->setFilenameCipher($this->cipher->encryptText(self::cleanName($filename)))
            ->setConfidential($confidential)
            ->setContext($context)
            ->setExpiresAt($expiresAt)
            ->setOffice($office);

        $in = fopen($path, 'rb');
        $encrypted = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+b');
        try {
            $result = $this->cipher->encryptStream($in, $encrypted);
            rewind($encrypted);
            $storagePath = sprintf('%s/%s.bin', date('Y/m'), bin2hex(random_bytes(16)));
            $this->storage->writeStream($storagePath, $encrypted);
        } finally {
            fclose($in);
            fclose($encrypted);
        }
        $document->setFile($storagePath, $result['wrappedKey'], $result['sha256'], $result['size'], $mime);

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->log($document, null !== $sender && $sender !== $recipient ? AccessAction::SHARE : AccessAction::UPLOAD, $sender);
        $this->dispatcher->dispatch(new DocumentEvent($document, $notify && null !== $sender && $sender->getId() !== $recipient->getId()), DocumentEvent::DEPOSITED);

        return $document;
    }

    /**
     * The clear content, decrypted to a temporary stream and checked against
     * its SHA-256 before anything of it is handed out.
     *
     * @return resource
     */
    public function open(Document $document)
    {
        $in = $this->storage->readStream($document->getStoragePath());
        $out = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        try {
            $sha = $this->cipher->decryptStream($in, $out, $document->getWrappedKey());
        } catch (\Throwable $e) {
            fclose($out);
            throw $e;
        } finally {
            if (\is_resource($in)) {
                fclose($in);
            }
        }
        if (!hash_equals($document->getSha256(), $sha)) {
            fclose($out);
            throw new CorruptedException('The decrypted file is not the one deposited.');
        }
        rewind($out);

        return $out;
    }

    public function read(Document $document): string
    {
        $stream = $this->open($document);
        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function title(Document $document): string
    {
        return (string) $this->cipher->decryptText($document->getTitleCipher());
    }

    public function filename(Document $document): string
    {
        return (string) ($this->cipher->decryptText($document->getFilenameCipher()) ?? ('document-'.$document->getId()));
    }

    public function revoke(Document $document, ?User $by): void
    {
        $document->revoke();
        $this->entityManager->flush();
        $this->log($document, AccessAction::REVOKE, $by);
        $this->dispatcher->dispatch(new DocumentEvent($document, false), DocumentEvent::REVOKED);
    }

    /** The file itself goes (expired, revoked long ago, the client's data erased); the log stays. */
    public function destroy(Document $document): void
    {
        if ($this->storage->fileExists($document->getStoragePath())) {
            $this->storage->delete($document->getStoragePath());
        }
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function log(Document $document, AccessAction $action, ?User $user): AccessLog
    {
        $request = $this->requestStack->getMainRequest();
        $log = new AccessLog((int) $document->getId(), $action, $user, $request?->getClientIp(), $request?->headers->get('User-Agent'), $document->getRecipient()?->getId());
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\/\\\\]/u', '', $name);

        return mb_substr('' !== trim($name) ? $name : 'document', 0, 180);
    }
}
