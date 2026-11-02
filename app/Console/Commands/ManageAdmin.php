<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('visparkle:admin {email : Email of the member} {--revoke : Remove admin rights instead of granting them}')]
#[Description('Grant or revoke admin rights for a member')]
class ManageAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("{$email} のアカウントが見つかりません。");

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        $user->forceFill(['is_admin' => ! $revoke])->save();

        $this->info($revoke ? "{$email} の管理者権限を外しました。" : "{$email} を管理者にしました。");

        return self::SUCCESS;
    }
}
