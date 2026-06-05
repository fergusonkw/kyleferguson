<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_is_shown_to_unverified_users(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }

    public function test_verified_user_is_redirected_from_notice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertRedirect(route('admin.home'));
    }

    public function test_email_can_be_verified_via_signed_link(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create();

        $url = URL::signedRoute('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('admin.home'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_verification_fails_with_an_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::signedRoute('verification.verify', [
            'id' => $user->id,
            'hash' => sha1('wrong-email@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_notification_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
