<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_serves_the_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('TSPortal', escape: false);
    }

    #[Test]
    public function a_deep_link_reaches_the_app_rather_than_a_404(): void
    {
        $this->get('/cases/17')->assertOk();
        $this->get('/accounts/3')->assertOk();
    }

    #[Test]
    public function the_catch_all_does_not_swallow_the_api(): void
    {
        $this->getJson('/api/portal/nope')->assertNotFound();
        $this->getJson('/api/wiki/v1/nope')->assertNotFound();
    }

    #[Test]
    public function the_sign_in_url_is_rendered_before_anything_is_fetched(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('window.TSPortal', escape: false)
            ->assertSee('loginUrl', escape: false);
    }
}
