<?php

namespace App\Services;

use App\Models\Course;
use App\Models\SaleBatch;

/** Quản lý đợt bán (sale_batch): tạo với các giá trị mặc định và cập nhật kèm audit log. */
class BatchService
{
    public function __construct(private AuditLogger $audit) {}

    /** Tạo đợt mới cho course: slots_taken khởi tạo 0, tiền JPY. */
    public function create(Course $course, array $data): SaleBatch
    {
        return $course->batches()->create([
            ...$data,
            'slots_taken' => 0,
            'currency'    => 'JPY',
        ]);
    }

    /** Cập nhật đợt; nếu status thay đổi thì ghi audit log với actor admin. */
    public function update(SaleBatch $batch, array $data, int $adminId): void
    {
        $from = $batch->status;
        $batch->update($data);

        if ($from !== $batch->status) {
            $this->audit->record($batch, $from, $batch->status, 'admin', $adminId);
        }
    }
}
