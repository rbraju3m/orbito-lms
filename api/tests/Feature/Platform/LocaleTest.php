<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Notification;

/*
 * Which language a reader gets (docs/I18N.md §2). One resolver; these walk its
 * order — `?locale=`, the person's choice, the academy's CHOSEN default, the
 * browser, the fallback — and the two writes that feed it.
 */
beforeEach(function (): void {
    seedRegistry();

    // The harness's OWN instance: the middleware reads `tenant()`, and a
    // second copy saved here would leave it holding stale settings.
    $this->academy = tenancy()->tenant;
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

function academySpeaks(Tenant $academy, ?string $default, ?array $enabled = null): void
{
    $academy->default_locale = $default;
    $academy->enabled_locales = $enabled;
    $academy->save();
}

it('speaks the fallback to somebody nobody has decided for', function (): void {
    $this->actingAs($this->student)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('data.locale.code', 'en')
        ->assertJsonPath('data.locale.direction', 'ltr')
        ->assertJsonPath('data.locale.available.*.code', ['en', 'bn'])
        ->assertJsonPath('data.locale.available.1.native_name', 'বাংলা');
});

it('follows the browser when neither the person nor the academy chose', function (): void {
    $this->actingAs($this->student)
        ->withHeaders(['Accept-Language' => 'bn-BD,bn;q=0.9,en;q=0.8'])
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.locale.code', 'bn');
});

it('puts the academy default ahead of the browser', function (): void {
    academySpeaks($this->academy, 'bn');

    $this->actingAs($this->student)
        ->withHeaders(['Accept-Language' => 'en-US'])
        ->getJson('/api/v1/auth/me')
        ->assertHeader('Content-Language', 'bn')
        ->assertJsonPath('data.locale.code', 'bn');
});

it('puts the person\'s own choice ahead of the academy', function (): void {
    academySpeaks($this->academy, 'bn');
    $this->student->forceFill(['locale' => 'en'])->save();

    $this->actingAs($this->student)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.locale.code', 'en');
});

it('lets one request ask for a language with ?locale=', function (): void {
    $this->actingAs($this->student)->getJson('/api/v1/auth/me?locale=bn')
        ->assertJsonPath('data.locale.code', 'bn');
});

/*
 * A preference that cannot be honoured falls through rather than failing, so
 * an academy switching a language off breaks nobody who had chosen it.
 */
it('falls through a choice the academy no longer offers', function (): void {
    academySpeaks($this->academy, 'en', ['en']);
    $this->student->forceFill(['locale' => 'bn'])->save();

    $this->actingAs($this->student)->getJson('/api/v1/auth/me?locale=bn')
        ->assertJsonPath('data.locale.code', 'en')
        ->assertJsonPath('data.locale.available.*.code', ['en']);
});

it('ignores a language the product does not speak', function (): void {
    $this->actingAs($this->student)
        ->withHeaders(['Accept-Language' => 'fr-FR,fr'])
        ->getJson('/api/v1/auth/me?locale=xx')
        ->assertJsonPath('data.locale.code', 'en');
});

it('saves a language the academy offers, and hands the choice back with null', function (): void {
    $this->actingAs($this->student)->patchJson('/api/v1/account/profile', ['locale' => 'bn'])->assertOk();
    expect($this->student->fresh()->locale)->toBe('bn');

    $this->actingAs($this->student)->patchJson('/api/v1/account/profile', ['locale' => null])->assertOk();
    expect($this->student->fresh()->locale)->toBeNull();
});

it('refuses a language the academy does not offer', function (): void {
    academySpeaks($this->academy, 'en', ['en']);

    $this->actingAs($this->student)
        ->patchJson('/api/v1/account/profile', ['locale' => 'bn'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.0.field', 'locale');
});

it('records no choice for somebody who registers without making one', function (): void {
    $this->academy->registration_mode = 'open';
    $this->academy->save();

    Notification::fake();

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/register', [
        'name' => 'New Learner',
        'email' => 'new@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'academy' => 'test-academy',
    ])->assertCreated();

    expect(User::where('email', 'new@example.com')->value('locale'))->toBeNull();
});

describe('the academy\'s languages', function (): void {
    beforeEach(function (): void {
        $this->admin = User::factory()->withRole(RoleKey::Admin)->create();
    });

    it('shows what the academy speaks and what it could', function (): void {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/academy')
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'en')
            ->assertJsonPath('data.enabled_locales', ['en', 'bn'])
            ->assertJsonPath('data.locales.*.code', ['en', 'bn']);
    });

    it('makes Bengali the default', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/academy', ['default_locale' => 'bn', 'enabled_locales' => ['bn']])
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'bn')
            ->assertJsonPath('data.enabled_locales', ['bn']);

        expect($this->academy->refresh()->defaultLocale()->value)->toBe('bn');
    });

    it('refuses a default the academy does not offer', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/academy', ['default_locale' => 'bn', 'enabled_locales' => ['en']])
            ->assertStatus(422);
    });

    /* Judged on the merged result: the stored default is still English. */
    it('refuses switching off the language that is the default', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/academy', ['enabled_locales' => ['bn']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'enabled_locales');
    });

    it('refuses a language the product does not speak', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/academy', ['enabled_locales' => ['en', 'fr']])
            ->assertStatus(422);
    });

    it('denies the languages to an instructor', function (): void {
        $this->actingAs(User::factory()->instructor()->create())
            ->patchJson('/api/v1/admin/academy', ['default_locale' => 'bn'])
            ->assertStatus(403);
    });
});
