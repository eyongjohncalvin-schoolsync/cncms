<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` is the public home page for guests (App\Http\Controllers\
     * SiteController); signed-in users still go to their dashboard.
     */
    public function test_the_application_shows_guests_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee(route('login'), false);
    }
}
