<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'target_type' => ReportTarget::Post,
            'target_id' => Post::factory(),
            'reason' => ReportReason::Inappropriate,
            'body' => null,
            'status' => ReportStatus::Open,
        ];
    }
}
