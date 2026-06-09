<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every order/enrollment/batch state transition (NFR-3, BR-10).
 */
class AuditLogger
{
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
