<?php

use App\Enums\SupportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'creator_id']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }

    /**
     * Supporting now follows automatically, so current supporters get the follow they would have had; otherwise
     * ending their support would also drop the creator's free posts from their feed.
     */
    public function backfill(): void
    {
        DB::table('follows')->insertOrIgnoreUsing(
            ['user_id', 'creator_id', 'created_at'],
            DB::table('supports')
                ->where('status', SupportStatus::Active->value)
                ->select('user_id', 'creator_id', DB::raw('CURRENT_TIMESTAMP'))
                ->distinct(),
        );
    }
};
