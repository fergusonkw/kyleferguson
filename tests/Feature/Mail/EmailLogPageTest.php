<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Billing\Business;
use App\Models\Billing\Client;
use App\Models\Billing\Invoice;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The email log pages, and the record of an invoice's emails on the invoice.
 */
final class EmailLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_lists_sent_email_to_those_allowed_to_see_it(): void
    {
        EmailMessage::factory()->create(['subject' => 'Invoice KF-0001 from Kyle Ferguson']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.email-log.index'))
            ->assertOk()
            ->assertSee('Invoice KF-0001 from Kyle Ferguson');

        $this->actingAs($this->createUserWithRole(RoleEnum::User->slug()))
            ->get(route('admin.email-log.index'))
            ->assertForbidden();
    }

    public function test_the_log_filters_by_status_and_search(): void
    {
        EmailMessage::factory()->status(EmailStatus::Delivered)->create(['subject' => 'Arrived safely', 'to_address' => 'one@client.test']);
        EmailMessage::factory()->status(EmailStatus::HardBounced)->create(['subject' => 'Went nowhere', 'to_address' => 'two@client.test']);

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.email-log.index', ['status' => 'hard_bounced']))
            ->assertOk()
            ->assertSee('Went nowhere')
            ->assertDontSee('Arrived safely');

        $this->actingAs($admin)
            ->get(route('admin.email-log.index', ['search' => 'ONE@Client']))
            ->assertOk()
            ->assertSee('Arrived safely')
            ->assertDontSee('Went nowhere');
    }

    public function test_an_email_shows_its_body_sandboxed_alongside_its_reports(): void
    {
        $message = EmailMessage::factory()->status(EmailStatus::HardBounced)->create([
            'html_body' => '<p>Hello Acme</p>',
            'error' => '550 5.1.1 No such user (mx.client.test)',
        ]);
        EmailEvent::factory()->for($message)->reporting('bounce', [
            'bounce' => 'hard',
            'message' => '550 5.1.1 No such user',
            'host' => 'mx.client.test',
        ])->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.email-log.show', $message))
            ->assertOk()
            ->assertSee('srcdoc="&lt;p&gt;Hello Acme&lt;/p&gt;"', false)
            ->assertSee('sandbox=""', false)
            ->assertSee('Why it did not arrive')
            ->assertSee('550 5.1.1 No such user (mx.client.test)');
    }

    public function test_a_withheld_body_says_why_it_is_missing(): void
    {
        $message = EmailMessage::factory()->withheld()->create();

        $this->actingAs($this->createAdmin())
            ->get(route('admin.email-log.show', $message))
            ->assertOk()
            ->assertSee('carried a sign-in or verification link')
            ->assertDontSee('srcdoc', false);
    }

    public function test_an_email_that_only_reached_the_log_mailer_says_so(): void
    {
        $message = EmailMessage::factory()->create(['mailer' => 'log', 'provider_message_id' => null]);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.email-log.show', $message))
            ->assertOk()
            ->assertSee('written down, not delivered')
            ->assertSee('SMTP2Go did not carry this email');
    }

    // ---- On the invoice ----------------------------------------------------------

    public function test_the_invoice_lists_the_emails_that_carried_it(): void
    {
        $invoice = $this->sentInvoice();
        $email = EmailMessage::factory()->about($invoice)->status(EmailStatus::Delivered)
            ->create(['to_address' => 'ap@client.test']);
        EmailEvent::factory()->for($email)->reporting('open')->create(['occurred_at' => '2026-09-12 10:30:00']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('ap@client.test')
            ->assertSee('Delivered')
            ->assertSee('Opened Sep 12, 10:30 AM')
            ->assertSee(route('admin.email-log.show', $email), false);
    }

    public function test_a_bounced_invoice_email_is_flagged_at_the_top_of_the_invoice(): void
    {
        $invoice = $this->sentInvoice();
        EmailMessage::factory()->about($invoice)->status(EmailStatus::HardBounced)->create([
            'to_address' => 'old@client.test',
            'error' => '550 5.1.1 No such user',
        ]);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('The last email did not reach old@client.test')
            ->assertSee('550 5.1.1 No such user — check the address and resend.');
    }

    public function test_a_later_successful_email_clears_the_flag(): void
    {
        $invoice = $this->sentInvoice();
        EmailMessage::factory()->about($invoice)->status(EmailStatus::HardBounced)->create(['to_address' => 'old@client.test']);
        EmailMessage::factory()->about($invoice)->status(EmailStatus::Delivered)->create(['to_address' => 'new@client.test']);

        $this->actingAs($this->createAdmin())
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('The last email did not reach');
    }

    public function test_a_billing_user_sees_the_emails_but_not_the_log_link(): void
    {
        $invoice = $this->sentInvoice();
        $email = EmailMessage::factory()->about($invoice)->create(['to_address' => 'ap@client.test']);

        $this->actingAs($this->userWith([Permission::ViewBilling]))
            ->get(route('admin.billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('ap@client.test')
            ->assertDontSee(route('admin.email-log.show', $email), false);
    }

    private function sentInvoice(): Invoice
    {
        $business = Business::factory()->create(['supported_currencies' => ['CAD'], 'payment_terms_days' => 30]);
        $client = Client::factory()->for($business)->create(['billing_currency' => 'CAD']);

        return Invoice::factory()->for($business)->for($client)
            ->status(InvoiceStatus::Sent)->withTotal(120.00)
            ->create([
                'issued_on' => now()->subDays(21)->toDateString(),
                'due_on' => now()->addDays(9)->toDateString(),
                'sent_at' => now()->subDays(21),
            ]);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scoped', 'slug' => 'scoped-'.uniqid()]);

        $role->permissions()->sync(
            PermissionModel::query()
                ->whereIn('slug', array_map(fn (Permission $p): string => $p->value, $permissions))
                ->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }
}
