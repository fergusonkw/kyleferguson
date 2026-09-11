<?php

declare(strict_types=1);

use App\Enums\Billing\MarkupType;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `hybrid` markup type is a flat fee *plus* a percentage, but Phase 1 gave
 * markup a single value column, so hybrid had nowhere to put its second
 * component.
 *
 * Splitting them normalizes the meaning: `markup_value` is always the percent
 * and `markup_fee` is always the flat fee, for every type. Existing
 * `fixed_fee` rows stored their fee in the value column, so they are moved
 * across — `down()` reverses that move before dropping the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->decimal('default_markup_fee', 14, 4)->default(0)->after('default_markup_value');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->decimal('markup_fee', 14, 4)->nullable()->after('markup_value');
        });

        Client::query()
            ->where('default_markup_type', MarkupType::FixedFee->value)
            ->update([
                'default_markup_fee' => DB::raw('default_markup_value'),
                'default_markup_value' => 0,
            ]);

        Project::query()
            ->where('markup_type', MarkupType::FixedFee->value)
            ->update([
                'markup_fee' => DB::raw('markup_value'),
                'markup_value' => 0,
            ]);
    }

    public function down(): void
    {
        Client::query()
            ->where('default_markup_type', MarkupType::FixedFee->value)
            ->update(['default_markup_value' => DB::raw('default_markup_fee')]);

        Project::query()
            ->where('markup_type', MarkupType::FixedFee->value)
            ->update(['markup_value' => DB::raw('markup_fee')]);

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('default_markup_fee');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('markup_fee');
        });
    }
};
