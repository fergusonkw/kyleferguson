<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emails are now stored lowercase and looked up lowercase. Rows written before
 * that could hold any casing, which MySQL's case-insensitive collation still
 * matched but Postgres would not — so they are normalized once here, before
 * the data moves.
 *
 * No two rows can collide: the unique index was already case-insensitive.
 * Not reversible, since the original casing is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereRaw('email <> LOWER(email)')
            ->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        //
    }
};
