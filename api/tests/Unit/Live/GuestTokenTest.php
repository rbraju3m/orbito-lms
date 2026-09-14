<?php

declare(strict_types=1);

use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Support\GuestToken;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['orbito.guest_registration.confirm_ttl_hours' => 24]);

    $this->tokens = app(GuestToken::class);
    $this->now = Carbon::parse('2026-09-20 10:00:00');
    $this->webinar = (new Webinar)->forceFill(['uuid' => 'webinar-uuid']);
});

it('reads back what a confirmation link was minted with', function (): void {
    $token = $this->tokens->confirmation('dhaka-art-school', $this->webinar, 'ada@example.test', 'Ada', $this->now);

    expect($this->tokens->readConfirmation($token, 'dhaka-art-school', $this->now->copy()->addHour()))
        ->toBe(['webinar' => 'webinar-uuid', 'email' => 'ada@example.test', 'name' => 'Ada']);
});

it('lets a confirmation link lapse', function (): void {
    $token = $this->tokens->confirmation('dhaka-art-school', $this->webinar, 'ada@example.test', null, $this->now);

    expect($this->tokens->readConfirmation($token, 'dhaka-art-school', $this->now->copy()->addHours(25)))->toBeNull();
});

it('refuses a link on another academy\'s site', function (): void {
    $token = $this->tokens->confirmation('dhaka-art-school', $this->webinar, 'ada@example.test', null, $this->now);

    expect($this->tokens->readConfirmation($token, 'another-academy', $this->now))->toBeNull();
});

it('never reads a confirmation link as a manage link, or the other way round', function (): void {
    $registration = (new WebinarRegistration)->forceFill(['id' => 7, 'email' => 'ada@example.test']);
    $confirmation = $this->tokens->confirmation('dhaka-art-school', $this->webinar, 'ada@example.test', null, $this->now);
    $place = $this->tokens->place('dhaka-art-school', $registration, null, $this->now);

    // The purpose is inside the encryption: a link cannot be replayed as the other kind.
    expect($this->tokens->readPlace($confirmation, 'dhaka-art-school', $this->now))->toBeNull()
        ->and($this->tokens->readConfirmation($place, 'dhaka-art-school', $this->now))->toBeNull()
        ->and($this->tokens->readPlace($place, 'dhaka-art-school', $this->now))
        ->toBe(['registration' => 7, 'email' => 'ada@example.test']);
});

it('keeps a manage link working until a day after the event ends', function (): void {
    $registration = (new WebinarRegistration)->forceFill(['id' => 7, 'email' => 'ada@example.test']);
    $session = (new LiveSession)->forceFill(['ends_at' => $this->now->copy()->addDays(3)]);
    $token = $this->tokens->place('dhaka-art-school', $registration, $session, $this->now);

    // The reminder's link has to still work for somebody who clicks it late.
    expect($this->tokens->readPlace($token, 'dhaka-art-school', $this->now->copy()->addDays(3)->addHours(12)))->not->toBeNull()
        ->and($this->tokens->readPlace($token, 'dhaka-art-school', $this->now->copy()->addDays(4)->addMinute()))->toBeNull();
});
