<?php

namespace Database\Seeders;

use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds the public catalog: courses + their sale batches.
 * Mirrors the content previously hard-coded in App\Support\DemoData.
 */
class CourseCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'title' => 'Laravel từ con số 0',
                'slug' => 'laravel-from-zero',
                'summary' => 'Xây dựng ứng dụng web hoàn chỉnh với Laravel: routing, Eloquent, Blade và thanh toán.',
                'description' => 'Khóa học đưa bạn từ người mới đến khả năng tự xây dựng một ứng dụng web thương mại điện tử hoàn chỉnh. Tập trung vào thực hành: bạn sẽ build một hệ thống bán khóa học có thanh toán Stripe thật.',
                'level' => 'Cơ bản → Trung cấp',
                'lessons_count' => 48,
                'duration_label' => '12 giờ',
                'outcomes' => [
                    'Cấu trúc dự án Laravel chuẩn',
                    'Eloquent ORM & quan hệ dữ liệu',
                    'Server-rendered UI với Blade',
                    'Tích hợp thanh toán Stripe',
                    'Transaction & row lock chống overselling',
                    'Background jobs & scheduler',
                ],
                'batches' => [
                    ['name' => 'Đợt 1 — Đang mở', 'status' => 'on_sale',   'price' => 29800, 'capacity' => 50, 'slots_taken' => 38, 'sale_starts_at' => '-5 days',  'sale_ends_at' => '+25 days'],
                    ['name' => 'Đợt 2 — Sắp mở',  'status' => 'scheduled', 'price' => 34800, 'capacity' => 50, 'slots_taken' => 0,  'sale_starts_at' => '+20 days', 'sale_ends_at' => '+50 days'],
                    ['name' => 'Đợt thử nghiệm',  'status' => 'closed',    'price' => 19800, 'capacity' => 30, 'slots_taken' => 30, 'sale_starts_at' => '-40 days', 'sale_ends_at' => '-25 days'],
                ],
            ],
            [
                'title' => 'Design System với Tailwind',
                'slug' => 'ui-design-systems',
                'summary' => 'Thiết kế bộ component nhất quán, có thể tái sử dụng cho sản phẩm thật.',
                'description' => 'Học cách xây dựng một design system bền vững: design token, component API, accessibility và tài liệu hóa.',
                'level' => 'Trung cấp',
                'lessons_count' => 32,
                'duration_label' => '8 giờ',
                'outcomes' => [
                    'Design token & theme',
                    'Component API rõ ràng',
                    'Accessibility cơ bản',
                    'Tài liệu hóa component',
                ],
                'batches' => [
                    ['name' => 'Đợt mở bán', 'status' => 'on_sale', 'price' => 24800, 'capacity' => 40, 'slots_taken' => 39, 'sale_starts_at' => '-3 days', 'sale_ends_at' => null],
                ],
            ],
            [
                'title' => 'Thanh toán với Stripe cho thị trường Nhật',
                'slug' => 'stripe-payments',
                'summary' => 'Checkout, webhook, Konbini, Pay-easy và xử lý bất đồng bộ đúng cách.',
                'description' => 'Đi sâu vào tích hợp Stripe cho thị trường Nhật Bản: hosted Checkout, webhook idempotent, các phương thức thanh toán bất đồng bộ.',
                'level' => 'Nâng cao',
                'lessons_count' => 26,
                'duration_label' => '7 giờ',
                'outcomes' => [
                    'Stripe Checkout hosted',
                    'Webhook là nguồn sự thật',
                    'Idempotency & reconcile',
                    'Konbini / Pay-easy async',
                ],
                'batches' => [
                    ['name' => 'Đợt 1', 'status' => 'sold_out', 'price' => 39800, 'capacity' => 30, 'slots_taken' => 30, 'sale_starts_at' => '-7 days', 'sale_ends_at' => '+20 days'],
                ],
            ],
        ];

        foreach ($catalog as $data) {
            $batches = $data['batches'];
            unset($data['batches']);

            $course = Course::create($data);

            foreach ($batches as $batch) {
                $course->batches()->create([
                    ...$batch,
                    'sale_starts_at' => Carbon::parse($batch['sale_starts_at']),
                    'sale_ends_at' => $batch['sale_ends_at'] ? Carbon::parse($batch['sale_ends_at']) : null,
                ]);
            }
        }
    }
}
