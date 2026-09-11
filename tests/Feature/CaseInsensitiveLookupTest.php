<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Project;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lookups that MySQL's case-insensitive collation made forgiving, kept
 * forgiving on a database that compares exactly.
 *
 * These pass on SQLite and MySQL either way; run the suite against Postgres
 * (DB_CONNECTION=pgsql) to see them bite.
 */
final class CaseInsensitiveLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_users_email_is_stored_lowercase(): void
    {
        $user = User::factory()->create(['email' => '  Kyle@Example.TEST ']);

        $this->assertSame('kyle@example.test', $user->fresh()->email);
    }

    public function test_login_matches_the_email_however_it_is_capitalised(): void
    {
        $user = User::factory()->create(['email' => 'kyle@example.test', 'password' => 'password']);

        $this->post('/login', ['email' => 'Kyle@Example.test', 'password' => 'password'])
            ->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_reset_link_is_sent_however_the_email_is_capitalised(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'kyle@example.test']);

        $this->post('/password/email', ['email' => 'KYLE@example.test'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_creating_a_user_that_differs_only_by_case_is_a_validation_error(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.users.store'), [
                'name' => 'Duplicate',
                'email' => 'Taken@Example.test',
                'password' => 'Str0ng!Passw0rd#2026',
                'password_confirmation' => 'Str0ng!Passw0rd#2026',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_user_created_with_a_capitalised_email_is_stored_lowercase(): void
    {
        $this->actingAs($this->createAdmin())
            ->postJson(route('admin.users.store'), [
                'name' => 'New Person',
                'email' => 'New.Person@Example.test',
                'password' => 'Str0ng!Passw0rd#2026',
                'password_confirmation' => 'Str0ng!Passw0rd#2026',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'new.person@example.test']);
    }

    public function test_user_search_ignores_case(): void
    {
        User::factory()->create(['name' => 'Zebediah Quill']);

        $this->actingAs($this->createAdmin())
            ->getJson(route('admin.users.data', ['draw' => 1, 'length' => 50, 'search' => ['value' => 'zebediah']]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_client_search_ignores_case(): void
    {
        $business = Business::factory()->create();
        Client::factory()->for($business)->create(['name' => 'Acme Snow Removal']);
        Client::factory()->for($business)->create(['name' => 'Other Co']);
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.clients.data', ['draw' => 1, 'length' => 50, 'search' => ['value' => 'ACME snow']]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_project_search_ignores_case_through_the_client_name(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->for($business)->create(['name' => 'Acme Snow Removal']);
        Project::factory()->for($client)->create(['name' => 'Routing platform']);
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.billing.switch', $business));

        $this->actingAs($admin)
            ->getJson(route('admin.billing.projects.data', ['draw' => 1, 'length' => 50, 'search' => ['value' => 'acme']]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }
}
