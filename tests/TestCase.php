<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTransactions re-resolves the DEFAULT connection at teardown;
     * once a test initializes tenancy that default is `tenant`, so the
     * central `pgsql` transaction was never rolled back and its leaked
     * session held row locks that hung the next test. Pinning it here
     * applies tests/Feature/Api/AuthTest.php's fix to every test.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = ['pgsql'];

    /**
     * Refuses to run against anything but the dedicated `cncms_testing`
     * database (phpunit.xml) — the real `cncms` database holds live SWECOM
     * data. Trips if config is cached or DB_DATABASE is otherwise overridden.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.pgsql.database');

        if ($database !== 'cncms_testing') {
            throw new RuntimeException("Refusing to run tests against database [{$database}]; expected [cncms_testing].");
        }
    }
}
