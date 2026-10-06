<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fanbox:init-storage')]
#[Description('Create the private and public buckets on the local S3-compatible storage (development only)')]
class InitStorage extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This command is for local development only.');

            return self::FAILURE;
        }

        foreach (['private', 'public'] as $disk) {
            $config = config("filesystems.disks.{$disk}");
            $client = new S3Client([
                'version' => 'latest',
                'region' => $config['region'],
                'endpoint' => $config['endpoint'],
                'use_path_style_endpoint' => (bool) $config['use_path_style_endpoint'],
                'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
            ]);

            $bucket = $config['bucket'];

            if (! $client->doesBucketExistV2($bucket)) {
                $client->createBucket(['Bucket' => $bucket]);
                $this->info("Created bucket [{$bucket}].");
            }

            if ($disk === 'public') {
                $client->putBucketPolicy([
                    'Bucket' => $bucket,
                    'Policy' => json_encode([
                        'Version' => '2012-10-17',
                        'Statement' => [[
                            'Effect' => 'Allow',
                            'Principal' => ['AWS' => ['*']],
                            'Action' => ['s3:GetObject'],
                            'Resource' => ["arn:aws:s3:::{$bucket}/*"],
                        ]],
                    ]),
                ]);
                $this->info("Granted public read on [{$bucket}].");
            }
        }

        return self::SUCCESS;
    }
}
