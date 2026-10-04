---
title: Booking
order: 2
---

# Booking

## The model

| Entity | |
|---|---|
| `AppointmentType` | what an appointment is for: duration, buffer after it, channel (`in_person`, `video`, `home`, `phone`), who may book it online (`everyone`, `known`: already seen by that member, `staff`: by phone only), mode (`slots` or `request`), manual confirmation, instructions, a price as printed; the members who offer it |
| `Schedule` | a member receives on that ISO weekday from - to, at an office (none: home visits, remote), for some types (none listed: all), valid from - until |
| `Absence` | a member away from - to |
| `ServiceArea` | where the office goes: postcodes, towns, or a radius around an office - `contains($postcode, $town, $lat, $lng)` |
| `Appointment` | member, type, start and end (none yet for a request), the client's account or a name taken on the phone, a beneficiary, status (`requested`, `confirmed`, `cancelled`, `no_show`, `done`), source, the reason **encrypted**, a series id |

The whole office closed is a closed special day of omnibase's opening hours, not an absence.

## Free slots

```php
$slots = $slotFinder->find($member, $type, $from, $to);       // list<Slot>: start, end, office
$slotFinder->isFree($member, $type, $start);
$slotFinder->next($member, $type, 3);
```

Schedules, less absences, less closed days, less the appointments held and the buffer after each,
less what is closer than `min_notice` or further than `horizon` (clients only, not the staff).
`compute()` is the same on plain arrays, without a database. Times are walked in seconds from the
schedule's start written in the office's timezone: the night the clocks go back, a schedule from 1:00
to 4:00 lasts four hours and has its 2:00 slot twice. One member at a time: it is not a table planner.

## Booking

```php
$appointment = $booker->book($type, $member, $start, $client, AppointmentSource::ONLINE, $reason);
$booker->request($type, $member, $client, $message);          // no time: the office fixes it
$booker->confirm($appointment, $start);
$booker->move($appointment, $newStart);
$booker->cancel($appointment);                                // BookingException too_late within cancel_until
$booker->series($type, $member, $first, 10, new \DateInterval('P1D'), $client, $address);
```

A slot is taken under a `symfony/lock` lock on the member's day, after asking the `SlotFinder` again;
`Appointment::$slotKey` ("member|start", emptied when the appointment no longer holds its slot) has a
unique index, which is the last word if two servers race. A refusal is a `BookingException` whose key
is a translation (`booking.error.slot_taken`, `too_late`, `not_bookable`, `overlap`…).

The reason is sealed by `Base\Office\Share\Cipher` before it is stored: without a key, a booking
with a reason fails rather than store it clear.

## What the client is told

`AppointmentEvent` (`BOOKED`, `REQUESTED`, `CONFIRMED`, `MOVED`, `CANCELLED`, `REMINDER`).
`BookingMailer` listens: when, with whom, where, how (the channel), the `.ics` (omnibase's
`Calendar\Ics`) and the Google Calendar link, the link to cancel (a token mailed, only its hash kept).
**Never the reason, never the type's name.** Add a listener of your own for a push or an SMS.

A regime that lets clients book for someone else implements `BeneficiaryProviderInterface`.

## Cron

```
*/15 * * * *  bin/console office:booking:remind        # each reminder of office.booking.reminders, once
55 23 * * *   bin/console office:booking:close-day     # past: done; a video one never joined: no-show; closed rooms purged
```

## The agenda (`/admin/agenda`, `ROLE_STAFF`)

A day (a column a member) or a week (one member), drawn on a grid: the schedules in white, absences
hatched, appointments in the member's colour. Each opens to: done, absent, cancel, move. Below: the
quick booking taken on the phone - an existing account by its e-mail, or someone new who is then
invited -, and the requests waiting for a time. A member reads the reason of their own appointments;
anyone else only sees that the time is taken.
