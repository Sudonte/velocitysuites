<?php

/*
 * PHPUnit bootstrap: composer autoload, then the safety guard. If the environment could reach a real
 * database (production APP_ENV, a non-sqlite connection, a cached config...), the run is aborted HERE,
 * before the framework boots and before any test executes. See Tests\Support\TestEnvironmentGuard.
 */
require __DIR__.'/../vendor/autoload.php';

\Tests\Support\TestEnvironmentGuard::abortUnlessSafe(dirname(__DIR__));
