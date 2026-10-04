---
title: Share
order: 3
---

# Share: the document vault

## What is stored, and how

A `Document` belongs to a `recipient` (whose document it is) and has a `sender`. Its file is
encrypted on a private Flysystem storage:

- libsodium `crypto_secretstream_xchacha20poly1305`, in 64 KiB chunks, each authenticated: a byte
  changed, a chunk missing or the last one cut off, and it does not decrypt (`CorruptedException`);
- a random key per file, kept in the row wrapped (`crypto_secretbox`) by a key derived from the
  master key (`crypto_kdf`);
- the title and the file name sealed the same way; the SHA-256 of the clear file is kept and checked
  again after each decryption.

What stays readable in the database: who it is for, who sent it, its kind, size, type and dates.

**Fail closed.** The master key (`office.share.master_key`: base64 of 32 bytes, from the secrets
vault) missing or unusable, every call of `Cipher` throws `KeyMissingException`: nothing is stored
clear as a fallback, nothing is read. `Cipher::generateKey()` makes one.

```php
$document = $vault->deposit($uploadedFile, $recipient, $sender, 'Compte rendu', DocumentKind::REPORT);
$stream = $vault->open($document);     // decrypted to a temporary stream, checked, then handed out
$vault->revoke($document, $by);
$vault->destroy($document);            // the file too; the log stays
```

`deposit()` refuses an empty file, one above `max_size`, a type outside `mime_types`
(`ShareException`).

## Who may read

`Base\Office\Security\Voter\DocumentVoter`, three attributes:

| Attribute | |
|---|---|
| `OFFICE_DOCUMENT_VIEW` | see that it exists, its title |
| `OFFICE_DOCUMENT_READ` | open it, download it - never a revoked or expired one |
| `OFFICE_DOCUMENT_REVOKE` | its sender only |

The recipient and the sender see and read theirs. **Nobody else** - not the site's administrators
either - unless a regime's `AudienceResolverInterface` (autoconfigured, tag `office.share_audience`)
grants it; a resolver's `false` wins over any grant:

```php
final class PartnersAudience implements AudienceResolverInterface
{
    public function decide(string $attribute, Document $document, UserInterface $user): ?bool
    {
        return self::READ === $attribute && $this->isPartner($user) ? true : null;
    }
}
```

## How a file leaves

Only through `ShareController::download`: signed in, allowed by the voter, logged, sent with
`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, a sandboxing `Content-Security-Policy`,
and as an attachment (`application/octet-stream`). An image may be shown inline (`?inline=1`) in a
conversation. Someone else's document answers 404, and the refusal is logged.

## The access log

`AccessLog`: document id, recipient id, who (and their name at the time), the action (`view`,
`download`, `upload`, `share`, `revoke`, `denied`), IP, user agent, when. Rows are never edited and
outlive the document. `/admin/access-log` shows it to `ROLE_ADMIN`.

## What the recipient is told

`DocumentEvent::DEPOSITED` → `ShareMailer`: "a new document awaits you in your space", with a link to
`office.share.space_route` - where one signs in. Neither the document, nor its title, nor a link that
would open it.
