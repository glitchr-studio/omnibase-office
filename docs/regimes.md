---
title: What a regime builds on
order: 50
---

# What a regime builds on

A regime - `omnibase/notary`, `omnibase/lawyer`, `omnibase/health` - is the
profession's own rules on top of omnibase/office. What two regimes need is
written here once; a regime keeps what is its alone (its texts, its
compliance checks, its table names).

## Fields of practice: `Area`

`Base\Office\Entity\Area` is a mapped superclass: the name, the slug, a
summary, the page's text, whether it is shown and where, who of the team
follows it, and the methods around them (`getParagraphs()`,
`getPublicText()` for the wording check, `getItemsText()` / `setItemsText()`
for a form that edits the list one a line). A regime's entity extends it,
names its table and maps the two things whose names are its own - the list
of what is done in the field, and the join table of the members:

```php
namespace Base\Notary\Entity;

#[ORM\Entity(repositoryClass: AreaRepository::class)]
#[ORM\Table(name: 'notary_area')]
class Area extends \Base\Office\Entity\Area
{
    #[ORM\Column(type: 'json')]
    protected array $deeds = [];                        // a lawyer's: $matters

    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'notary_area_member')]
    protected Collection $members;

    public function getItems(): array { return $this->deeds; }
    public function setItems(?array $items): static { $this->deeds = self::lines($items); return $this; }
}
```

Its repository extends `Base\Office\Repository\AreaRepository`
(`findActive()`, `findOneActiveBySlug()`, `findForMember()`) and names the
entity in its constructor.

`Base\Notary\Entity\Area` and `Base\Lawyer\Entity\Area` were two copies of
that class; they extend it since 2026-10-05 with the same tables, columns and
methods (`getDeeds()`, `getMatters()`...): no migration, nothing to change in
an application.

## The wording guard: `Wording`

`Base\Office\Guard\Wording::scan($text, $extra, $patterns)` finds what the
communication of a regulated practice must not carry - comparison with
colleagues, disparagement, advertising's superlatives ("le meilleur",
"numéro 1", "moins cher", "nos concurrents", "résultat garanti"...) - on the
text lowered and stripped of its accents and tags (`Wording::plain()`).

- `$extra`: expressions of the site's own, matched as they are written;
- `$patterns`: a regime's own, `label => regular expression`, after the
  common ones. The lawyers add the mention of former judicial functions.

A regime keeps its own `Wording` (`Base\Notary\Guard\Wording`,
`Base\Lawyer\Guard\Wording`: the same `scan()`, `isClean()`, `plain()` as
before) citing its texts, and delegates here. A list of expressions helps the
person who writes; it judges nothing.

## A first appointment asked without an account

A practice's clients are invited, and the booking pages ask to sign in: someone
new has no way in. `Base\Office\Booking\AppointmentRequests` is that way -
what can be asked for (`offer()`: the appointment types in request mode open to
everyone, and who offers them), the form as it opens (`prepare()`: the member a
link named with `?avec=their-slug`, the only type), and `send()`, which
registers an appointment in request mode through `Booker::request()` under the
name, the e-mail and the phone given - the few words encrypted, the e-mails
those of any request.

The form is `Base\Office\Form\AppointmentRequestType` on
`Base\Office\Form\Model\AppointmentRequest`: the type, with whom, name,
e-mail, phone, a message, a field that only robots fill, and omnibase's
`PrivacyType` (the data-protection notice and its box). Its labels are keys
under `label_prefix` (`@office.appointment_request`).

- **The bundle's own page**: `office.booking.public_request: true` opens
  `/rendez-vous/demande` (`office_request`,
  `@Office/client/request.html.twig`, or `office.templates.request`). Off by
  default.
- **A regime's page** extends `AbstractRequestController` and answers with
  `respond()`: its route, its template, its words.

```php
class RequestController extends \Base\Office\Controller\Client\AbstractRequestController
{
    #[Route('/rendez-vous/demande', name: 'notary_request', methods: ['GET', 'POST'], priority: 10)]
    public function request(Request $request): Response
    {
        return $this->respond($request, '@Notary/client/request.html.twig', 'notary', '@notary.request');
    }
}
```

The template receives `form`, `sent`, `available` and `office`. omnibase/notary
and omnibase/lawyer each had the form, its model and the whole action; they
keep their route (`notary_request`, `lawyer_request`), their page and their
translations.

## The practice's name

`office.vocabulary.practice` ([Configuration](index.md)) gives every text
`{the_practice}`, `{at_the_practice}`, `{of_the_practice}`...: a regime's own
translations may use them rather than write "le cabinet" or "l’étude".
