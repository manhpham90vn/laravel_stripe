<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bản ghi vết bất biến (append-only) cho mọi chuyển trạng thái (NFR-3, BR-10),
 * do AuditLogger ghi. Chỉ có created_at (không sửa nên không cần updated_at).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;   // chỉ created_at (append-only)

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',            // dữ liệu phụ dạng JSON
        'created_at' => 'datetime',
    ];
}
