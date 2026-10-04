---
title: Visio
order: 4
---

# Visio: one-to-one video

A `Room` belongs to a subject (`RoomSubjectInterface`: a host, a guest, a start, an end, a key - an
`Appointment` is one). `Rooms::forSubject($appointment)` makes it the first time it is asked for and
keeps its times in step; it opens `open_before` minutes before the start and closes `close_after`
minutes after the end. Its address is a random token; only its two participants get anything, and
only while it is open.

## How it connects

The media go from browser to browser (WebRTC, DTLS-SRTP: encrypted end to end between the two). The
room only relays their handshake, over plain HTTP - no WebSocket server to run:

| | |
|---|---|
| `GET /visio/{token}` | the page: camera check, waiting room, the call |
| `GET /visio/{token}/ice` | the ICE servers, with fresh TURN credentials |
| `POST /visio/{token}/signal` | `{type: hello|offer|answer|candidate|bye, payload}` |
| `GET /visio/{token}/signal?after=N` | what the other side sent since, who is there - asked every second while the call is set up, every five once it runs; it is also the "I am here" |
| `POST /visio/{token}/fin` | the host ends: the room closes, the handshake is purged |
| `POST /visio/{token}/document` | a file dropped during the call, kept in the vault for the guest |

`public/js/visio.js` has no dependency: the host offers, the guest answers; mute, camera off, screen
sharing (`getDisplayMedia`), a chat on a data channel (never stored).

## The waiting room

The guest who is there while the host is not shows in the agenda (the appointment is outlined) and in
the dashboard widget `office_waiting_room`.

## TURN

When a network forbids the direct way, the media go through your own relay - still encrypted, never
through a third party. `TurnCredentials` issues the "TURN REST API" credentials coturn checks with
`use-auth-secret`: the username is `<expiry>:<who>`, the password the base64 HMAC-SHA1 of it with the
shared secret. Nothing is stored; they lapse after `turn_ttl`.

```yaml
# docker-compose.yml
coturn:
    image: coturn/coturn:4.6
    ports: ["3478:3478/udp", "3478:3478/tcp", "49160-49200:49160-49200/udp"]
    command: [-n, --listening-port=3478, --min-port=49160, --max-port=49200, --use-auth-secret,
              "--static-auth-secret=${TURN_SECRET}", "--realm=${TURN_REALM}", --no-cli, --no-tls, --no-dtls,
              --no-multicast-peers, --denied-peer-ip=10.0.0.0-10.255.255.255, ...]
```

`/visio/{token}?relay=1` forces every packet through the relay: the way to check the relay itself.
No STUN server is configured by default (`stun_urls`): none of a third party's is asked.

## What it is not

A recording, a group call, or a teleconsultation solution listed by a health authority - a regime
says what its trade requires (omnibase/health says the built-in video is not ANS-listed).
