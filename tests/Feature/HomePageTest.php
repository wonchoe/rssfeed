<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_marketing_content(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Feed Ingestion Platform');
        $response->assertSee('Create Account');
    }
}
