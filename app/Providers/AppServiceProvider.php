<?php

namespace App\Providers;

use App\Filesystem\BrowserSignedS3Adapter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePrivateUrlSigning();
    }

    /**
     * Presign S3 URLs against the browser-facing endpoint when it differs from the app's (e.g. Docker).
     */
    protected function configurePrivateUrlSigning(): void
    {
        Storage::extend('s3', function (Application $app, array $config) {
            $manager = new FilesystemManager($app);
            $base = $manager->createS3Driver($config);

            if (empty($config['signing_endpoint'])) {
                return $base;
            }

            $signingClient = $manager->createS3Driver([...$config, 'endpoint' => $config['signing_endpoint']])->getClient();

            return new BrowserSignedS3Adapter($base, $config, $signingClient);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
