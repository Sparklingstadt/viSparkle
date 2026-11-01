<?php

namespace Tests\Feature\Settings;

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }

    public function test_creator_with_active_supporters_cannot_delete_their_account()
    {
        $creator = Creator::factory()->payable()->create();
        $plan = Plan::factory()->create(['creator_id' => $creator->id]);
        Support::factory()->forPlan($plan)->billing()->create();

        $response = $this
            ->actingAs($creator->user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors(['password' => '有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。'])
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($creator->user->fresh());
    }

    public function test_user_with_only_dummy_or_ended_supports_can_delete_their_account()
    {
        $creator = Creator::factory()->create();
        $plan = Plan::factory()->create(['creator_id' => $creator->id]);
        Support::factory()->forPlan($plan)->ended()->create();
        Support::factory()->forPlan($plan)->billing()->ended()->create();
        Support::factory()->create(['user_id' => $creator->user_id]);
        Support::factory()->billing()->ended()->create(['user_id' => $creator->user_id]);

        $response = $this
            ->actingAs($creator->user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($creator->user->fresh());
    }
}
