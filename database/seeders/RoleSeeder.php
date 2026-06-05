<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleEnum::cases() as $role) {
            Role::updateOrCreate(
                ['slug' => $role->slug()],
                [
                    'name' => $role->value,
                    'description' => $role->description(),
                    'level' => $role->level(),
                ],
            );
        }
    }
}
