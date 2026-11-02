<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('visparkle:recount-post-likes')]
#[Description('Recompute posts.like_count from the post_likes rows')]
class RecountPostLikes extends Command
{
    public function handle(): int
    {
        $actual = '(select count(*) from post_likes where post_likes.post_id = posts.id)';

        // Raw update so recounting doesn't bump updated_at.
        $fixed = Post::query()
            ->whereRaw("like_count != {$actual}")
            ->toBase()
            ->update(['like_count' => DB::raw($actual)]);

        $this->info("Fixed {$fixed} post(s).");

        return self::SUCCESS;
    }
}
