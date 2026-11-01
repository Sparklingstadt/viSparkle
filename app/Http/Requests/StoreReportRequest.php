<?php

namespace App\Http\Requests;

use App\Enums\ReportReason;
use App\Enums\ReportTarget;
use App\Models\Creator;
use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::enum(ReportTarget::class)],
            'target_id' => ['required', 'integer'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'body' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['target_type' => '通報の対象', 'target_id' => '通報の対象', 'reason' => '理由', 'body' => '詳細'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ownerId = match (ReportTarget::from($this->string('target_type')->toString())) {
                ReportTarget::Creator => Creator::find($this->integer('target_id'))?->user_id,
                ReportTarget::Post => $this->reportablePost()?->creator->user_id,
            };

            if ($ownerId === null) {
                $validator->errors()->add('target_id', '通報の対象が見つかりません。');
            } elseif ($ownerId === $this->user()?->id) {
                $validator->errors()->add('target_id', '自分の投稿やページは通報できません。');
            }
        }];
    }

    /** A post that is not published, or that operations hid, cannot be reported. */
    private function reportablePost(): ?Post
    {
        return Post::query()->published()->visible()->with('creator')->find($this->integer('target_id'));
    }
}
