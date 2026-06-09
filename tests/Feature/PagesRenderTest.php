<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\CourseCatalogSeeder;
use Database\Seeders\DemoOrdersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Renders every Blade page against seeded data to catch template errors. */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->seed([CourseCatalogSeeder::class, DemoOrdersSeeder::class]);
    }

    public function test_guest_and_buyer_pages_render(): void
    {
        $this->get('/courses')->assertOk();
        $this->get('/courses/laravel-from-zero')->assertOk();
        $this->get('/batches/1')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/ui-kit')->assertOk();

        $buyer = User::where('email', 'test@example.com')->first();
        $this->actingAs($buyer);
        $this->get('/my/courses')->assertOk();
        foreach (Order::pluck('id') as $id) {
            $this->get("/orders/{$id}")->assertOk();
        }
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        $this->get('/admin/courses')->assertOk();
        $this->get('/admin/courses/create')->assertOk();
        $this->get('/admin/courses/1/edit')->assertOk();
        $this->get('/admin/courses/1/batches')->assertOk();
        $this->get('/admin/batches/1/stats')->assertOk();
    }

    public function test_buyer_blocked_from_admin(): void
    {
        $this->actingAs(User::where('email', 'test@example.com')->first());
        $this->get('/admin/courses')->assertForbidden();
    }
}
