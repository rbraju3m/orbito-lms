<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PublicSite\AcademyController;
use App\Http\Controllers\Api\V1\PublicSite\CourseController;
use App\Http\Controllers\Api\V1\PublicSite\FormTokenController;
use App\Http\Controllers\Api\V1\PublicSite\GuestRegistrationController;
use App\Http\Controllers\Api\V1\PublicSite\InvitationController;
use App\Http\Controllers\Api\V1\PublicSite\LeadController;
use App\Http\Controllers\Api\V1\PublicSite\PageController;
use App\Http\Controllers\Api\V1\PublicSite\PostController;
use App\Http\Controllers\Api\V1\PublicSite\WebinarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
| The ONLY anonymous surface in the product. Everything else resolves its
| academy from the authenticated user (§ Multi-tenancy); these routes carry
| the academy's SLUG in the path and `tenant.public` opens it.
|
| NO `auth:sanctum` and NO `tenant`. A route added here is a decision that a
| stranger seeing every field it emits is the intended outcome — the middleware
| docblock states the argument in full. Anything viewer-scoped, anything
| unpublished, and anything that WRITES belongs elsewhere — with TWO
| exceptions, each under an abuse story of its own: the lead form
| (docs/LEADS.md) and a guest's webinar place (docs/GUEST_REGISTRATION.md).
| A third anonymous write needs its own story too, not a borrowed one.
|
| Throttled per IP rather than per academy: a bucket shared by everybody
| reading one academy's site would let a script take that site down.
*/

Route::prefix('public/{academy}')
    ->middleware(['throttle:public', 'tenant.public'])
    ->name('public.')
    ->group(function (): void {
        Route::get('/', AcademyController::class)->name('academy');

        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::get('courses/{slug}', [CourseController::class, 'show'])->name('courses.show');

        Route::get('webinars', [WebinarController::class, 'index'])->name('webinars.index');
        Route::get('webinars/{slug}', [WebinarController::class, 'show'])->name('webinars.show');

        // The blog: published posts whose time has come (docs/BLOG.md).
        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('posts/{slug}', [PostController::class, 'show'])->name('posts.show');

        // Built pages, the chosen front page and the header's links (docs/PAGES.md).
        Route::get('home', [PageController::class, 'home'])->name('home');
        Route::get('navigation', [PageController::class, 'navigation'])->name('navigation');
        Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');

        /*
         * Lead capture — the one anonymous WRITE (docs/LEADS.md). The form is
         * fetched first: its token is what the POST is checked against.
         */
        Route::get('lead-form', [LeadController::class, 'form'])->name('leads.form');
        Route::post('leads', [LeadController::class, 'store'])
            ->middleware('throttle:leads')
            ->name('leads.store');

        /*
         * Guest webinar registration — the second anonymous write
         * (docs/GUEST_REGISTRATION.md). ASKING writes nothing: it mails a
         * confirmation link, and only that link, POSTed back, holds a place.
         * Every token travels in a request BODY, never in a URL.
         */
        Route::get('form-token', FormTokenController::class)->name('form-token');
        Route::post('webinars/{slug}/guest-registrations', [GuestRegistrationController::class, 'store'])
            ->middleware('throttle:guest-registrations')
            ->name('guest-registrations.store');
        Route::post('guest-registrations/confirm', [GuestRegistrationController::class, 'confirm'])
            ->middleware('throttle:20,1')
            ->name('guest-registrations.confirm');
        Route::post('guest-places/show', [GuestRegistrationController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('guest-places.show');
        Route::post('guest-places/cancel', [GuestRegistrationController::class, 'cancel'])
            ->middleware('throttle:20,1')
            ->name('guest-places.cancel');
        Route::post('guest-places/join', [GuestRegistrationController::class, 'join'])
            ->middleware('throttle:20,1')
            ->name('guest-places.join');

        /*
         * An invitation link, read by whoever holds it before they choose a
         * password (docs/INVITATIONS.md). Writes nothing — accepting is
         * `POST /auth/invitations/accept`, beside register.
         */
        Route::post('invitations/show', [InvitationController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('invitations.show');
    });
