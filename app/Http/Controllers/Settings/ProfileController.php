<?php

namespace App\Http\Controllers\Settings;

use App\Billing\BillingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Support;
use App\Services\Billing\AccountDeletion;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Listed in the deletion dialog: these are cancelled at once when the account is deleted.
            'billingSupports' => Support::active()
                ->where('user_id', $request->user()->id)
                ->whereNotNull('stripe_subscription_id')
                ->with('creator')
                ->orderBy('id')
                ->get()
                ->map(fn (Support $support) => ['creator' => $support->creator->display_name, 'price' => $support->price])
                ->values(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, AccountDeletion $deletion): RedirectResponse
    {
        $user = $request->user();

        try {
            $deletion->delete($user);
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '解約の処理に失敗したため、アカウントを削除できませんでした。時間をおいてお試しください。']);

            return back();
        }

        // The user row is gone: logging out must not save it again (as cycling the remember token would).
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
