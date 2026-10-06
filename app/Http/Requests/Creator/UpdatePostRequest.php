<?php

namespace App\Http\Requests\Creator;

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Services\Tiptap\TiptapDocumentValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
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
        /** @var Post $post */
        $post = $this->route('post');

        return [
            'action' => ['required', Rule::in(['save', 'publish', 'unpublish'])],
            'title' => [Rule::requiredIf($this->input('action') === 'publish'), 'nullable', 'string', 'max:100'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'array'],
            'required_price' => ['required', 'integer', Rule::in(self::priceOptions($post))],
            'cover_file_id' => [
                'nullable', 'integer',
                Rule::exists('post_files', 'id')->where('post_id', $post->id)->where('kind', PostFileKind::Image->value),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $body = $this->input('body');

            if (! is_array($body) || $validator->errors()->has('body')) {
                return;
            }

            /** @var Post $post */
            $post = $this->route('post');

            try {
                app(TiptapDocumentValidator::class)->validate($body, $post->images()->pluck('id')->all());
            } catch (ValidationException $e) {
                $validator->errors()->add('body', $e->validator->errors()->first('body'));
            }
        }];
    }

    /**
     * 0 (free), the creator's plan prices, and the post's current value even if its plan is gone.
     *
     * @return list<int>
     */
    public static function priceOptions(Post $post): array
    {
        $prices = [0, $post->required_price, ...$post->creator->plans()->pluck('price')->all()];
        $prices = array_values(array_unique(array_map('intval', $prices)));
        sort($prices);

        return $prices;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'タイトル', 'excerpt' => '公開部分（抜粋）', 'body' => '本文', 'required_price' => '閲覧に必要な金額', 'cover_file_id' => 'カバー画像'];
    }
}
