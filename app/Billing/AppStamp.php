<?php

namespace App\Billing;

/**
 * The `app` metadata this environment stamps on what it creates in Stripe. Environments may share one Stripe account
 * (local and production on the same test-mode keys, `stripe listen` forwarding everything), so the stamp tells whose
 * checkout or subscription an event is about. It is the environment's idempotency-key prefix, which is unique for the
 * same reason.
 */
final class AppStamp
{
    public const KEY = 'app';

    public static function value(): string
    {
        return (string) config('fanbox.billing.idempotency_prefix');
    }

    /**
     * Whether the metadata carries this environment's stamp.
     *
     * @param  array<string, string>  $metadata
     */
    public static function isOurs(array $metadata): bool
    {
        return ($metadata[self::KEY] ?? null) === self::value();
    }

    /**
     * Whether the metadata carries another environment's stamp (metadata with no stamp is neither ours nor foreign).
     *
     * @param  array<string, string>  $metadata
     */
    public static function isForeign(array $metadata): bool
    {
        return isset($metadata[self::KEY]) && ! self::isOurs($metadata);
    }
}
