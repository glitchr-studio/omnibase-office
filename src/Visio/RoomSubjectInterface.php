<?php

namespace Base\Office\Visio;

use App\Entity\User;

/**
 * What a video room is for: who hosts, who is the guest, when it starts
 * and ends, and a key naming it ("appointment:12"). An Appointment is one;
 * a regime may have others (a meeting of the partners with a client).
 */
interface RoomSubjectInterface
{
    public function getVisioHost(): ?User;

    public function getVisioGuest(): ?User;

    public function getVisioStartsAt(): ?\DateTimeImmutable;

    public function getVisioEndsAt(): ?\DateTimeImmutable;

    public function getVisioKey(): string;
}
