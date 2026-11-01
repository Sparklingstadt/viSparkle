<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserSuspensionRequest;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\Billing\CreatorSuspension;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('creator')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereHas('creator', fn ($c) => $c->where('slug', 'like', '%'.addcslashes($q, '%_\\').'%'))))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $users->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'creator_slug' => $user->creator?->slug,
            'suspended' => $user->isSuspended(),
            'suspended_at' => $user->suspended_at,
            'suspended_reason' => $user->suspended_reason,
            'created_at' => $user->created_at,
        ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'q' => $q === '' ? null : $q,
        ]);
    }

    public function update(UpdateUserSuspensionRequest $request, User $user, CreatorSuspension $suspension): RedirectResponse
    {
        $admin = $request->user();

        if (! $request->boolean('suspended')) {
            $user->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
            AdminAction::record($admin, 'user.unsuspended', $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => '利用停止を解除しました。']);

            return back();
        }

        $reason = (string) $request->validated('reason');

        // Suspend first, so the account is locked out (and new checkouts refused) before the billing work starts.
        // Suspending an already-suspended user re-runs the billing work for whatever is still active, keeping suspended_at.
        $user->forceFill(['suspended_at' => $user->suspended_at ?? now(), 'suspended_reason' => $reason])->save();
        AdminAction::record($admin, 'user.suspended', $user, $reason);

        // Billing calls run outside any transaction; each failure is logged and counted, never thrown.
        $fan = $suspension->cancelFanSupports($user);
        $creator = $user->creator === null
            ? ['cancelled' => 0, 'refunded' => 0, 'failed' => 0]
            : $suspension->suspend($user->creator, $admin);

        $cancelled = $fan['cancelled'] + $creator['cancelled'];
        $failed = $fan['failed'] + $creator['failed'];
        $summary = "解約 {$cancelled}件・返金 {$creator['refunded']}件・失敗 {$failed}件";

        if ($cancelled + $creator['refunded'] + $failed > 0) {
            AdminAction::record($admin, 'user.suspension_billing', $user, $summary);
        }

        Inertia::flash('toast', [
            'type' => $failed === 0 ? 'success' : 'error',
            'message' => "利用停止にしました。（{$summary}）",
        ]);

        return back();
    }
}
