<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    // ---------- enrollment ----------

    public function test_enabling_generates_a_pending_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.settings.two-factor.enable'))
            ->assertRedirect(route('admin.settings.index'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_confirming_with_a_valid_code_enables_two_factor(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAA-BBBB'],
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)->post(route('admin.settings.two-factor.confirm'), [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertRedirect(route('admin.settings.index'))->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirming_with_an_invalid_code_does_not_enable(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)->post(route('admin.settings.two-factor.confirm'), [
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_requires_the_current_password(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        // Wrong password is rejected.
        $this->actingAs($user)->delete(route('admin.settings.two-factor.disable'), [
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        // Correct password disables it.
        $this->actingAs($user)->delete(route('admin.settings.two-factor.disable'), [
            'current_password' => 'password',
        ])->assertRedirect(route('admin.settings.index'));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    // ---------- login challenge ----------

    public function test_login_with_two_factor_redirects_to_the_challenge(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_challenge_screen_requires_a_pending_login(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    public function test_valid_totp_code_completes_the_login(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->withSession([TwoFactorChallengeController::SESSION_USER => $user->id])
            ->post(route('two-factor.login.store'), [
                'code' => $this->google2fa()->getCurrentOtp($secret),
            ])->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_completes_the_login_and_is_consumed(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        $this->withSession([TwoFactorChallengeController::SESSION_USER => $user->id])
            ->post(route('two-factor.login.store'), [
                'recovery_code' => 'AAAA-BBBB',
            ])->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('AAAA-BBBB', $user->fresh()->twoFactorRecoveryCodes());
    }

    public function test_invalid_code_does_not_complete_the_login(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        $this->withSession([TwoFactorChallengeController::SESSION_USER => $user->id])
            ->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    private function google2fa(): Google2FA
    {
        return app(Google2FA::class);
    }

    private function userWithTwoFactor(string $secret): User
    {
        return User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAA-BBBB', 'CCCC-DDDD'],
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
