<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial unique indexes enforcing BR-2 (db_design §10):
 *   - ≤ 1 "live" order per (user, batch)
 *   - ≤ 1 active reservation per (user, batch)
 *
 * Partial indexes are supported by sqlite and postgres. MySQL does not support
 * them — there the same invariant must be enforced in the checkout transaction
 * (which we also do, defence in depth), optionally with a generated column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX orders_live_unique
                 ON orders (sale_batch_id, user_id)
                 WHERE status IN ('pending', 'processing', 'paid')"
            );

            DB::statement(
                "CREATE UNIQUE INDEX reservations_active_unique
                 ON reservations (sale_batch_id, user_id)
                 WHERE status = 'active'"
            );

            DB::statement(
                "CREATE UNIQUE INDEX enrollments_active_course_unique
                 ON enrollments (user_id, course_id)
                 WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        foreach (['orders_live_unique', 'reservations_active_unique', 'enrollments_active_course_unique'] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
