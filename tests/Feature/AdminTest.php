<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Admin (seller) flows: course/batch management and refund (spec §9 admin, §11). */
class AdminTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_refund_is_only_allowed_on_paid_orders(): void
    {
        $order = $this->reserve($this->onSaleBatch());   // still pending

        // Gateway must NOT be hit for a non-paid order.
        $this->mock(PaymentGateway::class)->shouldNotReceive('refund');

        // OrderPolicy::refund() gates on status === paid, so refunding a pending
        // order is forbidden at the authorization layer (403), not a soft redirect.
        $this->actingAs($this->admin())
            ->post(route('admin.orders.refund', $order->id))
            ->assertForbidden();
    }

    public function test_refund_calls_the_gateway_for_a_paid_order(): void
    {
        $order = $this->paidOrder();

        $this->mock(PaymentGateway::class)->shouldReceive('refund')->once()->with(
            \Mockery::on(fn ($o) => $o->id === $order->id)
        );

        $this->actingAs($this->admin())
            ->post(route('admin.orders.refund', $order->id))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_buyer_cannot_trigger_a_refund(): void
    {
        $order = $this->paidOrder();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.orders.refund', $order->id))
            ->assertForbidden();
    }

    public function test_admin_creates_a_course_with_generated_slug_and_parsed_outcomes(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.courses.store'), [
                'title' => 'Khóa Học Mới', 'summary' => 's', 'description' => 'd',
                'status' => 'published', 'outcomes' => "Hiểu A\nLàm B\n\n",
            ])
            ->assertRedirect(route('admin.courses.index'));

        $course = Course::firstOrFail();
        $this->assertEquals('khoa-hoc-moi', $course->slug);
        $this->assertEquals(['Hiểu A', 'Làm B'], $course->outcomes);
    }

    public function test_course_validation_rejects_missing_title(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.courses.store'), ['summary' => 's', 'description' => 'd', 'status' => 'published'])
            ->assertSessionHasErrors('title');
    }

    public function test_admin_creates_a_batch_under_a_course(): void
    {
        $course = Course::create([
            'title' => 'C', 'slug' => 'c-'.uniqid(), 'summary' => 's', 'description' => 'd', 'status' => 'published',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.courses.batches.store', $course->id), [
                'name' => 'Đợt 1', 'capacity' => 30, 'price' => 12000,
                'sale_starts_at' => now()->toDateTimeString(),
                'sale_ends_at' => now()->addWeek()->toDateTimeString(),
                'status' => 'scheduled',
            ])
            ->assertRedirect(route('admin.courses.batches.index', $course->id));

        $this->assertDatabaseHas('sale_batches', [
            'course_id' => $course->id, 'name' => 'Đợt 1', 'capacity' => 30,
            'price' => 12000, 'slots_taken' => 0, 'currency' => 'JPY',
        ]);
    }

    public function test_stats_report_taken_remaining_and_revenue(): void
    {
        $batch = $this->onSaleBatch(capacity: 5);

        // Two paid orders + one still-pending reservation on the same batch.
        $this->paidOrder($batch, User::factory()->create());
        $this->paidOrder($batch, User::factory()->create());
        $this->reserve($batch->fresh(), User::factory()->create());

        $response = $this->actingAs($this->admin())
            ->get(route('admin.batches.stats', $batch->id))
            ->assertOk();

        $stats = $response->viewData('stats');
        $this->assertEquals(5, $stats['capacity']);
        $this->assertEquals(3, $stats['taken']);          // 2 paid + 1 active reservation
        $this->assertEquals(2, $stats['remaining']);
        $this->assertEquals(2, $stats['paid']);
        $this->assertEquals(1, $stats['active_reservations']);
        $this->assertEquals(20000, $stats['revenue']);    // 2 × 10000, paid only
    }

    public function test_updating_a_batch_status_writes_an_audit_log(): void
    {
        $admin = $this->admin();
        $batch = $this->onSaleBatch();

        $this->actingAs($admin)
            ->patch(route('admin.batches.update', $batch->id), [
                'name' => $batch->name, 'capacity' => $batch->capacity, 'price' => $batch->price,
                'sale_starts_at' => $batch->sale_starts_at->toDateTimeString(),
                'sale_ends_at' => $batch->sale_ends_at->toDateTimeString(),
                'status' => SaleBatch::STATUS_CLOSED,
            ])
            ->assertRedirect();

        $this->assertEquals(SaleBatch::STATUS_CLOSED, $batch->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'sale_batches', 'subject_id' => $batch->id,
            'from_status' => SaleBatch::STATUS_ON_SALE, 'to_status' => SaleBatch::STATUS_CLOSED,
            'actor' => 'admin', 'actor_id' => $admin->id,
        ]);
    }
}
