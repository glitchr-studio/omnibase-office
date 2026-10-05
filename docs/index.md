---
title: omnibase/office
order: 1
---

# omnibase/office

## Installation

```sh
composer require omnibase/office
```

```php
// config/bundles.php
Base\Office\OfficeBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
office_controller:
    resource: "@OfficeBundle/src/Controller/Client"
    type: attribute
office_admin_controller:            # the agenda, the documents, the access log (needs omnibase/admin)
    resource: "@OfficeBundle/src/Controller/Admin"
    type: attribute
```

Then a migration (`doctrine:migrations:diff`): 14 tables, all prefixed `office_` (and `office`).
Moments are stored in UTC through glitchr/omnibase's `utc_datetime_immutable` type
(`Base\Database\Type\UtcDateTimeImmutableType`, registered by omnibase): omnibase sets PHP's
timezone per visitor, and an appointment must not move with it. In a query, a moment is bound as
`Base\Database\Type\Utc::from($moment)`. (The bundle had its own type of the same name and its own
`Base\Office\Database\Utc` until omnibase provided them: same name, same column, no migration.)

The master key of the vault, once, in the secrets vault:

```sh
php -r 'echo base64_encode(random_bytes(32));' | bin/console secrets:set SHARE_MASTER_KEY -
```

## Configuration

```yaml
# config/packages/office.yaml
office:
    timezone: Europe/Paris                 # schedules are written in it
    recipient: '%env(MAILER_CONTACT)%'     # where the contact form goes
    sender: '%env(MAILER_TECHNICAL)%'      # the From of the office's e-mails
    vocabulary:
        practice: cabinet                  # how the practice is named in the texts: cabinet | etude | office | maison_de_sante, or its forms
    contact:
        notice: '@office.contact.notice'   # the warning above the contact form (a regime chooses it)
        retention_months: 12
    templates:
        layout: layout/layout1.html.twig   # what the pages extend; their content is the block `content`
        team: '@Office/client/team.html.twig'
        member: '@Office/client/member.html.twig'
        hours: '@Office/client/hours.html.twig'
        contact: '@Office/client/contact.html.twig'
        request: '@Office/client/request.html.twig'   # a first appointment asked without an account
        space_nav: ~                       # a partial at the top of the client's pages
    booking:                               # enabled: false turns it off
        public_request: false              # true opens /rendez-vous/demande: a first appointment asked without an account
        min_notice: 120                    # minutes: nothing closer can be booked online
        horizon: 60                        # days ahead
        cancel_until: 24                   # hours before which a client may still cancel
        step: 0                            # minutes between slot starts; 0: the type's duration + buffer
        reminders: [24, 2]                 # hours before
        space_route: office_space_appointments
    share:
        storage: local.share               # a private Flysystem storage (declared by the bundle if you do not)
        master_key: '%env(default::SHARE_MASTER_KEY)%'
        max_size: 20971520
        mime_types: [application/pdf, image/jpeg, image/png, image/webp, image/heic, text/plain]
        space_route: office_space_documents
    visio:
        open_before: 10                    # minutes before the appointment the room opens
        close_after: 30
        turn_secret: '%env(default::TURN_SECRET)%'
        turn_urls: '%env(default::TURN_URLS)%'   # a list, or one comma-separated variable
        stun_urls: []                      # none by default: no third party is asked where the browser is
        turn_ttl: 3600
        turn_probe: ~                      # host:port the compliance check asks (a container's service name)
```

## Pages

| Route | Path | |
|---|---|---|
| `office_team`, `office_member` | `/equipe`, `/equipe/{slug}` | the team, grouped by `Member::category` |
| `office_hours` | `/horaires-acces` | omnibase's opening hours, the walk-in hours, each office |
| `office_contact` | `/contact` | omnibase's `ContactType`, kept as `ContactRequest` |
| `office_booking…` | `/rendez-vous…` | see [Booking](booking.md) |
| `office_request` | `/rendez-vous/demande` | a first appointment asked without an account, when `booking.public_request` is on ([What a regime builds on](regimes.md)) |
| `office_space_appointments`, `office_space_documents` | `/espace/rendez-vous`, `/espace/documents` | the client's own |
| `office_visio_room` | `/visio/{token}` | see [Visio](visio.md) |
| `office_invitation` | `/invitation/{token}` | a client invited opens their account |

## The practice's name in the texts

The bundle's texts name the practice - "appelez le cabinet", "Au cabinet", "Téléphone du cabinet". A
notary's is an étude or an office, a health centre a maison de santé:

```yaml
office:
    vocabulary:
        practice: etude                    # cabinet (default) | etude | office | maison_de_sante
        # or its forms, for another word (what is left out is built from the name with le / au / du):
        # practice: { name: agence, the: l’agence, at: à l’agence, of: de l’agence }
```

| In a text | cabinet | etude | maison_de_sante |
|---|---|---|---|
| `{practice}` | cabinet | étude | maison de santé |
| `{the_practice}`, `{The_practice}` | le cabinet | l’étude | la maison de santé |
| `{at_the_practice}`, `{At_the_practice}` | au cabinet | à l’étude | à la maison de santé |
| `{of_the_practice}` | du cabinet | de l’étude | de la maison de santé |

They are global translation parameters (`framework.translator.globals`, set by the bundle from this
option): available in every domain, so a regime's or an application's own texts may use them too. An
application that had overridden the bundle's texts one by one in `translations/office+intl-icu.fr.yaml`
to change the word can set the option and drop those lines; the overrides it keeps still win.

The stylesheet (`bundles/office/css/office.css`) reads the site's colours from CSS variables:
`--of-accent`, `--of-accent-ink`, `--of-ink`, `--of-muted`, `--of-line`, `--of-surface`, `--of-page`,
`--of-danger`, `--of-focus`, `--of-radius`.

## The team and its register

A `Member` has a `registryId` (an RPPS number, later a bar number). With `glitchr/omnistate` installed,
`Base\Office\Service\MemberRegistry::refresh($member)` asks whichever `ProfessionalRegistryInterface`
knows that identifier and keeps what it said on the member (`registryData`, `registryCheckedAt`) - the
back office's "Lire le registre".

## Clients are invited

`Base\Office\Service\ClientInvitations` extends omnibase's invitations (`Base\Service\Invitations`,
`Base\Entity\User\Invitation`): `invite($email, $name)` mails a link; accepting it creates the account,
attaches the appointments taken for that address before the account existed, and dispatches
`ClientAccountEvent::CREATED` for the regime (health makes the patient's profile).

## Compliance

`ComplianceCheckInterface` services (autoconfigured, tag `office.compliance_check`) answer a
`ComplianceResult` (ok, warning, missing, and what to do). The dashboard widget `office_compliance`
lists them. The office's own: the vault's key, the TURN relay (configured, and answering a STUN
request), the legal notice's host. A regime adds its trade's.

## Back office (with omnibase/admin)

CRUD screens for every entity; `/admin/agenda` (see [Booking](booking.md)); `/admin/documents` and
`/admin/access-log` (see [Share](share.md)); the widgets `office_today`, `office_waiting_room`,
`office_compliance`, `office_contact`; a settings section (host, data-protection contact, retention).

## More

[Booking](booking.md) · [Share](share.md) · [Visio](visio.md) · [What a regime builds on](regimes.md)
