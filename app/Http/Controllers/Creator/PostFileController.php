<?php

namespace App\Http\Controllers\Creator;

use App\Enums\PostFileKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\StorePostFileRequest;
use App\Models\Post;
use App\Models\PostFile;
use App\Services\PostFileStorer;
use App\Services\PostFileUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PostFileController extends Controller
{
    public function store(StorePostFileRequest $request, Post $post, PostFileStorer $storer, PostFileUrls $urls): JsonResponse
    {
        $file = $storer->store($post, $request->file('file'), PostFileKind::from($request->validated('kind')));

        return response()->json([
            'id' => $file->id,
            'kind' => $file->kind,
            'url' => $urls->url($file),
            'thumb_url' => $urls->thumbUrl($file),
            'original_name' => $file->original_name,
            'size' => $file->size,
        ], 201);
    }

    public function destroy(Request $request, Post $post, PostFile $file, PostFileStorer $storer): Response
    {
        abort_unless($request->user()->can('manage', $post) && $file->post_id === $post->id, 404);

        if ($post->cover_file_id === $file->id) {
            $post->update(['cover_file_id' => null]);
        }

        $storer->delete($file);

        return response()->noContent();
    }
}
