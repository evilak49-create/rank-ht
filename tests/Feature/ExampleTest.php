<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_a_configured_application_opens_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
