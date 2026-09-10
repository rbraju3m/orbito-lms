<?php

declare(strict_types=1);

use App\Domain\Webhook\Support\WebhookSigner;

/*
 * The signature is a contract with receivers we do not control, and
 * docs/WEBHOOKS.md shows them how to check it. A known vector pins the
 * scheme: if this changes, every integration in the world stops verifying.
 */

it('signs "timestamp.body" with HMAC-SHA256, timestamp first', function (): void {
    $header = (new WebhookSigner)->header('whsec_test', '{"id":"1"}', 1_789_000_000);

    expect($header)->toBe('t=1789000000,v1='.hash_hmac('sha256', '1789000000.{"id":"1"}', 'whsec_test'));
});

it('changes the signature when the timestamp changes, so a replay cannot be re-dated', function (): void {
    $signer = new WebhookSigner;

    expect($signer->header('s', 'body', 1))->not->toBe($signer->header('s', 'body', 2));
});

it('mints secrets that are prefixed, long and never repeated', function (): void {
    $a = WebhookSigner::newSecret();
    $b = WebhookSigner::newSecret();

    expect($a)->toStartWith('whsec_')
        ->and(strlen($a))->toBe(6 + 48)
        ->and($a)->not->toBe($b);
});
