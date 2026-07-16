<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home route redirects into the "next" SPA (the only frontend; the
     * legacy /app bundle was decommissioned).
     */
    public function test_the_home_route_redirects_into_the_next_app(): void
    {
        $this->get('/')->assertRedirect('/next');
    }

    /**
     * The old legacy /app path stays alive for existing bookmarks by redirecting
     * into /next, preserving whatever sub-path was requested.
     */
    public function test_the_legacy_app_path_redirects_into_next(): void
    {
        $this->get('/app/tasks')->assertRedirect('/next/tasks');
    }
}
