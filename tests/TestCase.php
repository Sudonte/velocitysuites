<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuses to hand a test an application that points anywhere but in-memory sqlite. Each test gets a
     * brand-new in-memory database, so tests build their own schema and never need to drop tables.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        TestEnvironmentGuard::assertApplicationIsSafe($app);

        return $app;
    }
}
