<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Billing\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the session-scoped current business and shares it (and the list of
 * available businesses) with every admin view. Applied to admin-billing routes.
 */
final class ShareCurrentBusiness
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function handle(Request $request, Closure $next): Response
    {
        $business = $this->currentBusiness->get();
        $available = $this->currentBusiness->available();

        View::share('currentBusiness', $business);
        View::share('availableBusinesses', $available);

        $request->attributes->set('currentBusiness', $business);

        return $next($request);
    }
}
