<?php

use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Post;
use App\Models\User;

it('reports admin and suspension state', function () {
    expect(User::factory()->create()->isAdmin())->toBeFalse()
        ->and(User::factory()->admin()->create()->isAdmin())->toBeTrue()
        ->and(User::factory()->create()->isSuspended())->toBeFalse()
        ->and(User::factory()->suspended()->create()->isSuspended())->toBeTrue();
});

it('reports closed creators and hidden posts', function () {
    expect(Creator::factory()->create()->isClosed())->toBeFalse()
        ->and(Creator::factory()->closed()->create()->isClosed())->toBeTrue()
        ->and(Post::factory()->create()->isHidden())->toBeFalse()
        ->and(Post::factory()->hidden()->create()->isHidden())->toBeTrue();
});

it('excludes hidden posts from the visible scope', function () {
    $shown = Post::factory()->create();
    Post::factory()->hidden()->create();

    expect(Post::visible()->pluck('id')->all())->toBe([$shown->id]);
});

it('excludes closed and suspended-owner creators from the open scope', function () {
    $open = Creator::factory()->create();
    Creator::factory()->closed()->create();
    Creator::factory()->for(User::factory()->suspended())->create();

    expect(Creator::open()->pluck('id')->all())->toBe([$open->id]);
});

it('computes the net amount after refunds', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    expect($payment->netAmount())->toBe(1000);

    $payment->forceFill(['refunded_amount' => 400])->save();
    expect($payment->fresh()->netAmount())->toBe(600);
});

it('records admin actions with a short target type', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->create();

    $action = AdminAction::record($admin, 'post.hide', $post, '理由');

    expect($action->fresh())
        ->admin_id->toBe($admin->id)
        ->action->toBe('post.hide')
        ->target_type->toBe('post')
        ->target_id->toBe($post->id)
        ->note->toBe('理由');
    expect(AdminAction::record($admin, 'user.suspend', $admin)->target_type)->toBe('user');
});
