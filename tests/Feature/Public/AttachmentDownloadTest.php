<?php

use App\Enums\PostFileKind;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\PostFileStorer;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = User::factory()->creator()->create();
    $this->plan = Plan::factory()->price(500)->create();
    $this->post = Post::factory()->published()->requiring(500)->create();
    $this->file = app(PostFileStorer::class)->store($this->post, UploadedFile::fake()->createWithContent('a.zip', 'zip'), PostFileKind::Attachment);
    $this->url = route('posts.files.show', [$this->post, $this->file]);
});

it('redirects entitled viewers to a freshly signed download url', function () {
    $user = User::factory()->create();
    Support::factory()->for($user)->forPlan($this->plan)->active()->create();
    $this->freezeTime();

    $this->actingAs($user)->get($this->url)
        ->assertRedirect("https://signed.test/{$this->file->path}?expires=".now()->addMinutes(5)->getTimestamp().'&cd=1');
});

it('hides files from viewers who cannot see the post', function () {
    $this->get($this->url)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($this->url)->assertNotFound();
});

it('404s a file that belongs to another post', function () {
    $other = Post::factory()->published()->create();

    $this->actingAs($this->creator)
        ->get(route('posts.files.show', [$other, $this->file]))
        ->assertNotFound();
});

it('links attachments through the stable download route', function () {
    $this->actingAs($this->creator)
        ->get(route('posts.show', $this->post))
        ->assertInertia(fn ($page) => $page->where('post.attachments.0.url', $this->url));
});
