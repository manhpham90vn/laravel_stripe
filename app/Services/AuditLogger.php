<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Ghi vết MỌI chuyển trạng thái của order / enrollment / sale_batch vào bảng
 * `audit_logs` (NFR-3, BR-10) để đối soát với Stripe và truy vết khi có sự cố.
 *
 * Dùng đa hình: `subject` là bất kỳ Model nào (Order/Enrollment/SaleBatch) —
 * lưu `subject_type` = tên bảng + `subject_id` = khóa chính, kèm from/to status,
 * người gây ra (`actor`: system|webhook|user + `actor_id`) và `meta` tùy ý.
 */
class AuditLogger
{
    /**
     * @param Model    $subject  Đối tượng bị đổi trạng thái
     * @param ?string  $from     Trạng thái cũ (null nếu vừa tạo mới)
     * @param string   $to       Trạng thái mới
     * @param string   $actor    Ai gây ra: 'system' | 'webhook' | 'user'
     * @param ?int     $actorId  Id user nếu do người dùng thao tác
     * @param array    $meta     Dữ liệu phụ (vd dispute_id, recovery, reason…)
     */
    public function record(
        Model $subject,
        ?string $from,
        string $to,
        string $actor = 'system',
        ?int $actorId = null,
        array $meta = [],
    ): void {
        AuditLog::create([
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor' => $actor,
            'actor_id' => $actorId,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
