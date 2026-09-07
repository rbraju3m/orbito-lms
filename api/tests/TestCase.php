<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Http\RequestId;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The request id is process-static; without this a test can observe the
        // id from the previous one.
        RequestId::reset();
    }
}
