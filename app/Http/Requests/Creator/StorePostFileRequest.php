<?php

namespace App\Http\Requests\Creator;

use App\Enums\PostFileKind;
use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostFileRequest extends FormRequest
{
    /**
     * Another creator's post is reported as missing (404), never as forbidden.
     */
    public function authorize(): bool
    {
        abort_unless($this->user()->can('manage', $this->route('post')), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $file = $this->input('kind') === PostFileKind::Image->value
            ? ['required', 'file', 'mimes:'.implode(',', config('fanbox.image_mimes')), 'max:'.config('fanbox.image_max_kb')]
            : ['required', 'file', 'max:'.config('fanbox.attachment_max_kb')];

        return [
            'kind' => ['required', Rule::enum(PostFileKind::class)],
            'file' => $file,
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $kind = PostFileKind::tryFrom((string) $this->input('kind'));

            if ($kind === null) {
                return;
            }

            /** @var Post $post */
            $post = $this->route('post');
            $limit = $kind === PostFileKind::Image ? config('fanbox.images_per_post') : config('fanbox.attachments_per_post');

            if ($post->files()->where('kind', $kind)->count() >= $limit) {
                $label = $kind === PostFileKind::Image ? '画像' : '添付ファイル';
                $validator->errors()->add('file', "{$label}は1投稿につき{$limit}個までです。");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'ファイル', 'kind' => '種類'];
    }
}
