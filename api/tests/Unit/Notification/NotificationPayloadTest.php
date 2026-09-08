<?php

declare(strict_types=1);

use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;

it('survives a round trip through the stored JSON', function (): void {
    $payload = new NotificationPayload(
        type: NotificationType::DiscussionReplied,
        title: 'New reply: what does the third stanza mean?',
        body: 'Close.',
        actionLabel: 'Read the reply',
        actionPath: '/learn/abc/discussions/def',
        meta: ['course_id' => 'abc'],
    );

    $restored = NotificationPayload::fromArray($payload->toArray());

    expect($restored->type)->toBe(NotificationType::DiscussionReplied)
        ->and($restored->title)->toBe($payload->title)
        ->and($restored->actionPath)->toBe('/learn/abc/discussions/def')
        ->and($restored->meta)->toBe(['course_id' => 'abc']);
});

it('builds the absolute url at render time, never stores one', function (): void {
    $payload = new NotificationPayload(
        type: NotificationType::CertificateIssued,
        title: 'Ready',
        body: 'Ready',
        actionPath: '/certificates',
    );

    expect($payload->toArray()['action_path'])->toBe('/certificates')
        ->and($payload->url())->toBe(rtrim(frontend_url(), '/').'/certificates');
});

it('has no url when there is nowhere to go', function (): void {
    $payload = new NotificationPayload(
        type: NotificationType::CertificateIssued,
        title: 'Ready',
        body: 'Ready',
    );

    expect($payload->url())->toBeNull();
});

it('flattens author html into one readable line', function (): void {
    $html = "<p>The turn happens\n   in the <strong>third</strong> stanza.</p>";

    expect(NotificationPayload::excerpt($html))
        ->toBe('The turn happens in the third stanza.');
});

it('truncates at the limit rather than mid-paragraph', function (): void {
    $excerpt = NotificationPayload::excerpt('<p>'.str_repeat('a', 500).'</p>', 20);

    expect(mb_strlen($excerpt))->toBe(20)
        ->and($excerpt)->toEndWith('…');
});

it('handles an empty body without producing whitespace', function (): void {
    expect(NotificationPayload::excerpt(null))->toBe('')
        ->and(NotificationPayload::excerpt('<p>  </p>'))->toBe('');
});

it('locks the in-app channel and nothing else', function (): void {
    /*
     * The asymmetry the whole preferences design rests on: silencing the
     * inbox destroys the record, silencing email quiets the interruption.
     */
    expect(NotificationChannel::Database->isLocked())->toBeTrue()
        ->and(NotificationChannel::Mail->isLocked())->toBeFalse()
        ->and(NotificationChannel::switchable())->toBe([NotificationChannel::Mail]);
});

it('gives every type a label, a description and a group', function (): void {
    foreach (NotificationType::cases() as $type) {
        expect($type->label())->not->toBe('')
            ->and($type->description())->not->toBe('')
            // A type with no default channel could never be delivered at all.
            ->and($type->defaultChannels())->not->toBeEmpty();
    }
});
