<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\SaleBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * "Khóa học của tôi" lists active enrollments granted by the webhook, independent
 * of the Stripe redirect (AC-4, US-4, spec §9). A revoked enrollment (refund /
 * dispute) drops off the list (BR-7).
 */
class MyCoursesTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    /** A batch under a course with a distinct, assertable title. */
    private function batchForCourse(string $title): SaleBatch
    {
        $course = Course::create([
            'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title).'-'.uniqid(),
            'summary' => 's', 'description' => 'd', 'status' => 'published',
        ]);

        return $course->batches()->create([
            'name' => 'Đợt', 'capacity' => 5, 'slots_taken' => 0,
            'price' => 10000, 'currency' => 'JPY',
            'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay(),
            'status' => SaleBatch::STATUS_ON_SALE,
        ]);
    }

    public function test_paid_course_appears_in_my_courses(): void
    {
        $user = User::factory()->create();
        $batch = $this->batchForCourse('Khóa Laravel Nâng Cao');
        $order = $this->paidOrder($batch, $user);

        // Enrollment was granted by the webhook, not the redirect.
        $this->assertDatabaseHas('enrollments', [
            'order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('my.courses'))
            ->assertOk()
            ->assertSee('Khóa Laravel Nâng Cao');
    }

    public function test_revoked_enrollment_drops_off_the_list(): void
    {
        $user = User::factory()->create();
        $batch = $this->batchForCourse('Khóa Đã Hoàn Tiền');
        $this->paidOrder($batch, $user);

        Enrollment::where('user_id', $user->id)
            ->update(['status' => Enrollment::STATUS_REVOKED]);

        $this->actingAs($user)
            ->get(route('my.courses'))
            ->assertOk()
            ->assertDontSee('Khóa Đã Hoàn Tiền')
            ->assertSee('Bạn chưa sở hữu khóa học nào');
    }

    public function test_only_my_own_enrollments_are_listed(): void
    {
        $me = User::factory()->create();
        $this->paidOrder($this->batchForCourse('Khóa Của Tôi'), $me);
        $this->paidOrder($this->batchForCourse('Khóa Người Khác'), User::factory()->create());

        $this->actingAs($me)
            ->get(route('my.courses'))
            ->assertOk()
            ->assertSee('Khóa Của Tôi')
            ->assertDontSee('Khóa Người Khác');
    }
}
