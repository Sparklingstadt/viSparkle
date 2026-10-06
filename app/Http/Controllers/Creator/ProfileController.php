<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\UpdateCreatorProfileRequest;
use App\Models\Creator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $creator = $request->user()->creator;

        return Inertia::render('creator/profile', [
            'creator' => [
                'slug' => $creator->slug,
                'display_name' => $creator->display_name,
                'bio' => $creator->bio,
                'avatar_url' => $creator->avatarUrl(),
                'header_url' => $creator->headerUrl(),
            ],
        ]);
    }

    public function update(UpdateCreatorProfileRequest $request): RedirectResponse
    {
        $creator = $request->user()->creator;
        $replaced = [];

        foreach (['avatar', 'header'] as $field) {
            if ($request->hasFile($field)) {
                $replaced[] = $creator->{"{$field}_path"};
                $creator->{"{$field}_path"} = $this->storeImage($creator, $field, $request->file($field));
            }
        }

        $creator->fill($request->safe()->only(['display_name', 'bio']))->save();

        Storage::disk('public')->delete(array_filter($replaced));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'プロフィールを更新しました。']);

        return to_route('creator.profile.edit');
    }

    private function storeImage(Creator $creator, string $field, UploadedFile $file): string
    {
        $name = Str::lower((string) Str::ulid()).'.'.$file->extension();

        return $file->storeAs("creators/{$creator->id}/{$field}", $name, 'public');
    }
}
