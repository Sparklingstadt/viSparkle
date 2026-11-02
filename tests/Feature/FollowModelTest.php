<?php

use App\Models\Creator;
use App\Models\Follow;
use App\Models\Support;
use App\Models\User;

it('drops the follows of a deleted user', function () {
    $creator = Creator::factory()->create();
    $fan = User::factory()->create();
    $creator->follow($fan);

    $fan->delete();

    expect(Follow::query()->count())->toBe(0);
});

it('backfills follows for active supporters only', function () {
    $active = Support::factory()->active()->create();
    Support::factory()->ended()->create();
    $already = Support::factory()->active()->create();
    $already->creator->follow($already->user);

    // Only the DML half of the migration: DDL would commit RefreshDatabase's transaction on MySQL.
    (require database_path('migrations/2026_11_02_000007_create_follows_table.php'))->backfill();

    expect(Follow::query()->get()->map->only('user_id', 'creator_id')->all())
        ->toEqualCanonicalizing([
            ['user_id' => $active->user_id, 'creator_id' => $active->creator_id],
            ['user_id' => $already->user_id, 'creator_id' => $already->creator_id],
        ]);
});
