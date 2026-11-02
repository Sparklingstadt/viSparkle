<?php

namespace App\Console\Commands;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Billing\StripeGateway;
use App\Models\Creator;
use App\Services\Billing\AccountSync;
use Database\Seeders\DemoCreatorsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Opens Stripe test-mode payout accounts for the demo creators meant to accept supports, and prints an onboarding
 * link for each one that still needs it. Nobody can log in as a demo creator, so the operator finishes onboarding
 * from these links. Safe to re-run: it reuses existing accounts and reports which ones are ready.
 *
 * With --detach it forgets those accounts instead, so the demo creators go back to "準備中". Run it before switching
 * to live keys: a test-mode account does not exist in live mode, and checkout against it would fail.
 */
#[Signature('fanbox:demo-payout-accounts {--detach : Forget the payout accounts of the demo creators so they stop accepting supports}')]
#[Description('Open Stripe test-mode payout accounts for the demo creators and print their onboarding links')]
class DemoPayoutAccounts extends Command
{
    public function handle(PaymentGateway $gateway, AccountSync $sync): int
    {
        $slugs = DemoCreatorsSeeder::acceptingSupports();

        if ($this->option('detach')) {
            $detached = Creator::query()->whereIn('slug', $slugs)->whereNotNull('stripe_account_id')
                ->update(['stripe_account_id' => null, 'payouts_enabled' => false, 'details_submitted' => false]);
            $this->info("入金口座を外しました（{$detached}人）。");

            return self::SUCCESS;
        }

        if (StripeGateway::usesLiveKeys()) {
            $this->error('本番キー（_live_）ではデモ用の口座を作りません。');

            return self::FAILURE;
        }

        $creators = Creator::query()->whereIn('slug', $slugs)->with('user')->orderBy('id')->get();
        $ready = 0;

        foreach ($slugs as $slug) {
            if (! $creators->contains('slug', $slug)) {
                $this->warn("{$slug}: クリエイターが見つかりません。先に DemoCreatorsSeeder を実行してください。");
            }
        }

        try {
            foreach ($creators as $creator) {
                if ($creator->stripe_account_id === null) {
                    $creator->forceFill(['stripe_account_id' => $gateway->createConnectAccount($creator)])->save();
                }

                $accountId = (string) $creator->stripe_account_id;
                $creator = $sync->sync($accountId) ?? $creator;

                if ($creator->canAcceptSupports()) {
                    $ready++;
                    $this->line("{$creator->slug}: 支援可");

                    continue;
                }

                $page = route('creators.show', $creator->slug);
                $this->line("{$creator->slug}: 登録手続きが必要です");
                $this->line('  '.$gateway->createAccountOnboardingLink($accountId, $page, $page));
            }
        } catch (BillingException $e) {
            $this->error("決済サービスに接続できませんでした: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("支援可 {$ready}/".count($slugs));

        return self::SUCCESS;
    }
}
