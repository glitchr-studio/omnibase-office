---
title: Visio
order: 4
---

# Visio: the video room

A `Room` belongs to a subject (`RoomSubjectInterface`: a host, a guest, a start, an end, a key - an
`Appointment` is one). `Rooms::forSubject($appointment)` makes it the first time it is asked for and
keeps its times in step; it opens `open_before` minutes before the start and closes `close_after`
minutes after the end. Its address is a random token; only its two participants get anything, and
only while it is open.

## The room is office's, the call a gateway's

What carries the call is a gateway of [glitchr/omnimeet](https://github.com/glitchr-studio/omnimeet):

| Stays here (the meeting and its data) | Is the family's (the factory and the engine) |
|---|---|
| `Entity\Visio\Room`: token, subject, host, guest, opening and closing, presence, waiting room, end - and the `gateway` that opened it, its `reference` there | `Meeting`, `Participant`, `Access`, `Capabilities`, the gateways' `Registry` |
| `Visio\RoomSubjectInterface`, `Visio\Rooms` (`forSubject`, `canEnter`, `access`, `heartbeat`, `close`, `purge`) | the requests `Open`, `Join`, `Close`, `Fetch`, `Notify` |
| `Entity\Visio\Signal` and its repository: where the handshake is **stored** | `omnimeet/direct`: `Signaling`, the handshake's **rules**, on the repository as its `SignalStoreInterface` |
| `VisioController` and the routes `office_visio_*`, the file dropped into the vault, the page, the waiting room's widget, the agenda | `omnimeet/direct`: `TurnCredentials` and the engine `visio.js` |
| `office.visio.{open_before, close_after, gateway, names, turn_probe}` | `omnimeet.gateways.<name>.options` |

- `Rooms::forSubject()` opens the room on `office.visio.gateway` and keeps its reference.
- The page asks `Rooms::access($room, $user)` - the gateway's `Join` - and renders the `Access`:
  omnimeet/direct's engine on the room's own endpoints, a provider's frame, or a button to its
  address.
- **Who may enter, who is there, the waiting room, the end are judged here, on the `Room`,
  whatever the gateway.** The guest's frame is not even loaded before the host is there.
- A gateway is told as little as it needs: the room's token for a key (never the subject, never a
  title), a role, `u<id>`, and the member's name as the team page gives it (`names: none` to
  withhold it). The client's name is never given.
- A room nobody has entered follows the configured gateway (change `gateway`, and the
  appointments to come change with it); a room someone has been in stays on the one it was
  opened on.

## The direct gateway (the default)

The media go from browser to browser (WebRTC, DTLS-SRTP: encrypted end to end between the two).
The room only relays their handshake, over plain HTTP - no WebSocket server to run:

| | |
|---|---|
| `GET /visio/{token}` | the page: camera check, waiting room, the call |
| `GET /visio/{token}/engine.js` | the gateway's script, served from its package (`office_visio_engine`) |
| `GET /visio/{token}/ice` | the ICE servers, with fresh TURN credentials |
| `POST /visio/{token}/signal` | `{type: hello|offer|answer|candidate|bye, payload}` |
| `GET /visio/{token}/signal?after=N` | what the other side sent since, who is there - asked every second while the call is set up, every five once it runs; it is also the "I am here" |
| `POST /visio/{token}/fin` | the host ends: the room closes, the handshake is purged |
| `POST /visio/{token}/document` | a file dropped during the call, kept in the vault for the guest |

The engine has no dependency: the host offers, the guest answers; mute, camera off, screen
sharing, a chat on a data channel (never stored). Its markup and its rules are documented with
[omnimeet/direct](https://github.com/glitchr-studio/omnimeet-direct).

### TURN

When a network forbids the direct way, the media go through your own relay - still encrypted, never
through a third party. The direct gateway issues the "TURN REST API" credentials coturn checks with
`use-auth-secret`; nothing is stored, they lapse after `turn_ttl`.

```yaml
# config/packages/omnimeet.yaml
omnimeet:
    gateways:
        direct:
            factory: direct
            options: { turn_secret: '%env(default::TURN_SECRET)%', turn_urls: '%env(default::TURN_URLS)%' }

# docker-compose.yml
coturn:
    image: coturn/coturn:4.6
    ports: ["3478:3478/udp", "3478:3478/tcp", "49160-49200:49160-49200/udp"]
    command: [-n, --listening-port=3478, --min-port=49160, --max-port=49200, --use-auth-secret,
              "--static-auth-secret=${TURN_SECRET}", "--realm=${TURN_REALM}", --no-cli, --no-tls, --no-dtls,
              --no-multicast-peers, --denied-peer-ip=10.0.0.0-10.255.255.255, ...]
```

`/visio/{token}?relay=1` forces every packet through the relay: the way to check the relay itself.
No STUN server is configured by default (`stun_urls`): none of a third party's is asked. The
compliance check "TURN relay" reads the direct gateway's options (`Visio\Gateways::turn()`); with
another gateway there is no relay of yours to check.

## Another gateway

```sh
composer require omnimeet/jitsi
```

```yaml
# config/packages/omnimeet.yaml (Omnimeet\Bridge\Symfony\OmnimeetBundle registered)
omnimeet:
    gateways:
        jitsi:
            factory: jitsi
            options: { domain: meet.example.org, app_id: '%env(JITSI_APP_ID)%', app_secret: '%env(JITSI_APP_SECRET)%' }

# config/packages/office.yaml
office:
    visio:
        gateway: jitsi
```

The room's page then shows the provider's frame, driven by the gateway's script, with the same
buttons, the same waiting room and the same end (`public/js/visio-frame.js`):

- the third party is **named before anything is loaded from it** (`visio.provider.notice`), and
  nothing is loaded before the visitor enters;
- with [omnibase/consent](https://github.com/glitchr-studio/omnibase-consent) on the site, it
  waits for the visitor's yes: the page declares the feature `VISIO` in the consent panel
  (`Consent.use('VISIO', ...)`), and entering is refused until it is on;
- the response's `Permissions-Policy` lets the provider's origin use the camera and the
  microphone; **your Content-Security-Policy must allow it** in `frame-src` and `script-src`;
- the host's "end" closes the room here whatever the provider does - the pages are told at their
  next "I am here" and drop the frame - and asks the provider's interface to end the conference
  where it can.

A gateway that only gives an address (`Access` of kind `link`) is rendered as a button, shown to
the guest once the host is there.

## Without glitchr/omnimeet's bundle

An application that does not register `Omnimeet\Bridge\Symfony\OmnimeetBundle` keeps its video
room: `DependencyInjection\Compiler\VisioGatewayPass` makes the registry with the direct gateway
alone, its relay read from `office.visio.{turn_secret, turn_urls, stun_urls, turn_ttl}` - the
former place of these options, still read for this version (a deprecation notice says where they
go). `Base\Office\Visio\TurnCredentials` and `Base\Office\Visio\Signaling` remain as deprecated
names for `Omnimeet\Direct\TurnCredentials` and `Omnimeet\Direct\Signaling`.

## Migration

`office_visio_room` gets two nullable columns, `gateway` and `reference`, and an index on
`reference` (`doctrine:migrations:diff`). A room made before them is opened on the configured
gateway the first time it is asked for.

## The waiting room

The guest who is there while the host is not shows in the agenda (the appointment is outlined) and in
the dashboard widget `office_waiting_room` - whatever the gateway: it is the room's presence.

## What it is not

A recording, a group call (the direct gateway takes two), or a teleconsultation solution listed by a
health authority - a regime says what its trade requires (omnibase/health says the built-in video is
not ANS-listed); such a solution would be one more gateway.
