<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string $target_type
 * @property int $target_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['admin_id', 'action', 'target_type', 'target_id', 'note'])]
class AdminAction extends Model
{
    /** Target type is the model's short snake-case name: user, creator, post, payment, report, support. */
    public static function record(User $admin, string $action, Model $target, ?string $note = null): self
    {
        return self::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'target_type' => Str::snake(class_basename($target)),
            'target_id' => (int) $target->getKey(),
            'note' => $note,
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
