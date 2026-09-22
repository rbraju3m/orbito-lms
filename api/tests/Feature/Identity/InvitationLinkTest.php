<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Invitation;
use Tests\Concerns\SwitchesTenants;

/*
 * The invitation's preview on the PUBLIC surface: whoever holds a link can
 * see what it is for before choosing a password, with no account and no
 * academy open — the middleware finds it from the path, so the file ends
 * tenancy and switches tenants, like `LeadCaptureTest`. Fixtures are created
 * before the first request, while the harness still has the academy open.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
});

it('shows the holder of a link what it is for', function (): void {
    Invitation::factory()->instructor()->withToken('the-token')->create(['email' => 'ada@example.test']);

    tenancy()->end();

    $response = $this->postJson('/api/v1/public/test-academy/invitations/show', ['token' => 'the-token'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.test')
        ->assertJsonPath('data.role', 'instructor')
        ->assertJsonPath('data.academy_name', 'Test Academy');

    // Nothing about who sent it or how often: a second audience, a second
    // resource (ADR-06).
    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(['email', 'role', 'role_label', 'academy_name', 'expires_at']);
});

it('tells a stranger without the token nothing', function (): void {
    tenancy()->end();

    expect($this->postJson('/api/v1/public/test-academy/invitations/show', ['token' => 'guessed'])
        ->assertNotFound())->toBeApiError('invitation_invalid');
});

it('says a withdrawn link is withdrawn, to whoever holds it', function (): void {
    Invitation::factory()->revoked()->withToken('the-token')->create(['email' => 'ada@example.test']);

    tenancy()->end();

    expect($this->postJson('/api/v1/public/test-academy/invitations/show', ['token' => 'the-token'])
        ->assertStatus(410))->toBeApiError('invitation_revoked');
});
