<?php

declare(strict_types=1);

use App\Support\Http\RequestId;

it('generates an id lazily and returns the same one within a request', function (): void {
    RequestId::reset();

    $first = RequestId::current();

    expect($first)->not->toBeEmpty()
        ->and(RequestId::current())->toBe($first);
});

it('uses an explicitly set id', function (): void {
    RequestId::set('ORBITO-123');

    expect(RequestId::current())->toBe('ORBITO-123');
});
