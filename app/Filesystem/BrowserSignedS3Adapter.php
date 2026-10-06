<?php

namespace App\Filesystem;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Presigns temporary URLs with a client pointed at the browser-facing endpoint.
 *
 * SigV4 signatures cover the Host header, so a URL signed for the in-network
 * endpoint (e.g. "rustfs:9000" inside Docker) is rejected when the browser
 * requests it via "localhost:9000". Presigning is a local computation, so the
 * signing client never needs to reach that endpoint itself.
 */
class BrowserSignedS3Adapter extends AwsS3V3Adapter
{
    public function __construct(AwsS3V3Adapter $base, array $config, protected S3Client $signingClient)
    {
        parent::__construct($base->getDriver(), $base->getAdapter(), $config, $base->getClient());
    }

    public function temporaryUrl($path, $expiration, array $options = [])
    {
        $command = $this->signingClient->getCommand('GetObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        return (string) $this->signingClient->createPresignedRequest($command, $expiration, $options)->getUri();
    }
}
