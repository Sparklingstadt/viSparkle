<?php

namespace App\Services;

use App\Enums\PostFileKind;
use App\Models\PostFile;

/**
 * Short-lived signed URLs for private post files. Only call this for viewers who passed Entitlement.
 */
class PostFileUrls
{
    public function __construct(private readonly PrivateFileUrls $urls) {}

    public function url(PostFile $file): string
    {
        return $file->kind === PostFileKind::Attachment
            ? $this->urls->download($file->path, $file->original_name)
            : $this->urls->sign($file->path);
    }

    public function thumbUrl(PostFile $file): string
    {
        return $file->thumb_path === null ? $this->url($file) : $this->urls->sign($file->thumb_path);
    }
}
