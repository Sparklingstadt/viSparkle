<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('visparkle:creator {email : The account to make the creator} {--revoke : Take the creator role back instead}')]
#[Description('Make an account the creator who manages plans and posts (or take it back)')]
class ManageCreator extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }

        // The site has one creator: hand the role over by revoking it from the current holder first.
        $current = User::query()->where('is_creator', true)->whereKeyNot($user->id)->value('email');

        if (! $this->option('revoke') && $current !== null) {
            $this->error("{$current} is already the creator. Revoke it first: php artisan visparkle:creator {$current} --revoke");

            return self::FAILURE;
        }

        $user->forceFill(['is_creator' => ! $this->option('revoke')])->save();

        $this->info($user->is_creator ? "{$user->email} is now the creator." : "{$user->email} is no longer the creator.");

        return self::SUCCESS;
    }
}
