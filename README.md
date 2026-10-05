# omnibase/office

A professional practice on its own site, whatever the profession - a health centre, a notary's or a
lawyer's office - on [glitchr/omnibase](https://github.com/glitchr-studio/omnibase). One bundle, four parts:

| Part | What it is |
|---|---|
| **Core** | The office (`Office`: address, access, accessibility), the team (`Member`, read again from their professional register), the walk-in hours, the contact form, the compliance checks. The opening hours are omnibase's. |
| **Booking** | Appointment types, weekly schedules, absences, service areas; free slots (`SlotFinder`), bookings taken under a lock (`Booker`), requests without a time, series; e-mails with the `.ics`; the agenda in the back office. |
| **Share** | A document vault per recipient: files encrypted with libsodium (a key per file, wrapped by a master key), fail closed without a key; who may read decided by a voter a regime extends; every access logged. |
| **Visio** | A video room per appointment, with a waiting room. The call is carried by a gateway of [glitchr/omnimeet](https://github.com/glitchr-studio/omnimeet): by default from browser to browser (`omnimeet/direct`: WebRTC, the handshake relayed over HTTP, temporary TURN credentials for your own coturn, nothing through a third party); a Jitsi instance or another provider by configuration. Who enters, who waits and when it ends stay the room's. |

Nothing here knows health, law or payment. A regime puts its trade on top: `omnibase/health` does.

```sh
composer require omnibase/office
```

Documentation: [docs/](docs/index.md). License: LGPL-3.0-or-later.
