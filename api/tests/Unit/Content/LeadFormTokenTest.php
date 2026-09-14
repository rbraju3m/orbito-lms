<?php

declare(strict_types=1);

use App\Domain\Content\Enums\FormTokenVerdict;
use App\Domain\Content\Support\LeadFormToken;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['orbito.leads.min_seconds' => 3, 'orbito.leads.token_ttl_hours' => 24]);

    $this->tokens = app(LeadFormToken::class);
    $this->issued = Carbon::parse('2026-09-17 10:00:00');
    $this->token = $this->tokens->mint('dhaka-art-school', $this->issued);
});

it('accepts a form a person had time to fill in', function (): void {
    expect($this->tokens->check($this->token, 'dhaka-art-school', $this->issued->copy()->addSeconds(20)))
        ->toBe(FormTokenVerdict::Valid);
});

it('calls a form posted inside the minimum wait too fast', function (): void {
    expect($this->tokens->check($this->token, 'dhaka-art-school', $this->issued->copy()->addSecond()))
        ->toBe(FormTokenVerdict::TooFast);
});

it('calls a token from a clock running ahead too fast, not forged', function (): void {
    expect($this->tokens->check($this->token, 'dhaka-art-school', $this->issued->copy()->subSeconds(5)))
        ->toBe(FormTokenVerdict::TooFast);
});

it('calls a form older than its lifetime expired', function (): void {
    expect($this->tokens->check($this->token, 'dhaka-art-school', $this->issued->copy()->addHours(24)->addSecond()))
        ->toBe(FormTokenVerdict::Expired);
});

it('refuses a token minted for another academy', function (): void {
    expect($this->tokens->check($this->token, 'another-academy', $this->issued->copy()->addSeconds(20)))
        ->toBe(FormTokenVerdict::Invalid);
});

it('refuses anything it did not encrypt', function (): void {
    expect($this->tokens->check('not-a-token', 'dhaka-art-school', $this->issued->copy()->addSeconds(20)))
        ->toBe(FormTokenVerdict::Invalid);
});

it('does not let a script read the issue time out of the token', function (): void {
    // Encrypted, not signed: the minimum wait cannot be learned from the token.
    expect($this->token)->not->toContain((string) $this->issued->getTimestamp())
        ->and(base64_decode($this->token, true) ?: '')->not->toContain((string) $this->issued->getTimestamp());
});
