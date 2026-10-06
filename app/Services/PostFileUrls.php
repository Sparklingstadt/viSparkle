<?php

namespace App\Services;

use App\Enums\PostFileKind;
use App\Models\PostFile;
use Illuminate\Support\Facades\Storage;

/**
 * Short-lived signed URLs for private post files. Only call this for viewers who passed Entitlement.
 */
class PostFileUrls
{
    public function url(PostFile $file): string
    {
        $options = $file->kind === PostFileKind::Attachment
            ? ['ResponseContentDisposition' => "attachment; filename*=UTF-8''".rawurlencode($file->original_name)]
            : [];

        return $this->sign($file->path, $options);
    }

    public function thumbUrl(PostFile $file): string
    {
        return $file->thumb_path === null ? $this->url($file) : $this->sign($file->thumb_path);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function sign(string $path, array $options = []): string
    {
        return Storage::disk('private')->temporaryUrl(
            $path,
            now()->addMinutes(config('fanbox.signed_url_minutes')),
            $options,
        );
    }
}
