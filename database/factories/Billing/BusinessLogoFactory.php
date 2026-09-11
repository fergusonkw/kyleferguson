<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Models\Billing\BusinessLogo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessLogo>
 */
final class BusinessLogoFactory extends Factory
{
    protected $model = BusinessLogo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contents = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        return [
            'mime_type' => 'image/svg+xml',
            'contents_base64' => base64_encode($contents),
            'sha256' => hash('sha256', $contents),
            'byte_size' => mb_strlen($contents, '8bit'),
        ];
    }
}
