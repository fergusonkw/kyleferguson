<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * The invoice and email templates a business may choose between.
 *
 * Discovered from the filesystem rather than a hardcoded list, so dropping a
 * new template beside the default makes it selectable without a code change —
 * which is the point of the templates being per-business at all.
 */
final class InvoiceTemplateRegistry
{
    private const INVOICE_NAMESPACE = 'admin-v2.billing.invoices.templates';

    private const EMAIL_NAMESPACE = 'emails.invoices';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @return array<string, string> view name => label
     */
    public function invoiceTemplates(): array
    {
        return $this->discover(
            resource_path('views/admin-v2/billing/invoices/templates'),
            self::INVOICE_NAMESPACE,
        );
    }

    /**
     * @return array<string, string> view name => label
     */
    public function emailTemplates(): array
    {
        // The plain-text counterparts are companions, not choices.
        return $this->discover(resource_path('views/emails/invoices'), self::EMAIL_NAMESPACE, '-text');
    }

    public function invoiceTemplateExists(string $view): bool
    {
        return array_key_exists($view, $this->invoiceTemplates());
    }

    public function emailTemplateExists(string $view): bool
    {
        return array_key_exists($view, $this->emailTemplates());
    }

    /**
     * @return array<string, string>
     */
    private function discover(string $directory, string $namespace, ?string $excludeSuffix = null): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $templates = [];

        foreach (glob($directory.'/*.blade.php') ?: [] as $file) {
            $name = basename($file, '.blade.php');

            if ($excludeSuffix !== null && str_ends_with($name, $excludeSuffix)) {
                continue;
            }

            $view = $namespace.'.'.$name;

            if (! $this->views->exists($view)) {
                continue;
            }

            $templates[$view] = $this->label($name);
        }

        return $templates;
    }

    private function label(string $name): string
    {
        return str($name)->replace(['-', '_'], ' ')->title()->toString();
    }
}
