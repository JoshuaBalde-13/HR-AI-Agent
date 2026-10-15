<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page redirects to the HR dashboard.
     */
    public function test_the_home_page_redirects_to_the_hr_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('hr.dashboard'));
    }
}