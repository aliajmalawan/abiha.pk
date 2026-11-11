<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Symfony keeps trusted hosts in static state; a test that simulates production must not leak it into the next.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }
}
