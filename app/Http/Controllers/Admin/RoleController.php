<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class RoleController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Role::class);

        return view('admin-v2.roles.index');
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = (string) $request->input('search.value', '');

        $currentUser = auth()->user();

        $query = Role::query()->withCount(['permissions', 'users']);

        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue): void {
                $q->where('name', 'like', "%{$searchValue}%")
                    ->orWhere('slug', 'like', "%{$searchValue}%");
            });
        }

        $totalRecords = Role::count();
        $filteredRecords = $query->count();

        $roles = $query->orderByDesc('level')->skip($start)->take($length)->get();

        $data = $roles->map(fn (Role $role): array => [
            'id' => $role->id,
            'name' => e($role->name).(Role::isCore($role->slug) ? ' <span class="badge bg-secondary ms-1">core</span>' : ''),
            'slug' => e($role->slug),
            'level' => $role->level,
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
            'actions' => view('admin-v2.roles.partials.actions', [
                'role' => $role,
                'currentUser' => $currentUser,
            ])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
        ]);
    }

    public function permissions(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $grouped = Permission::query()
            ->orderBy('resource_type')
            ->orderBy('name')
            ->get()
            ->groupBy('resource_type')
            ->map(fn ($permissions) => $permissions->map(fn (Permission $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
            ])->values());

        return response()->json(['success' => true, 'data' => $grouped]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->input('name'),
            'slug' => $this->uniqueSlug($request->input('name')),
            'description' => $request->input('description'),
            'level' => $request->integer('level'),
        ]);

        $role->permissions()->sync($request->input('permissions', []));

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $role,
        ]);
    }

    public function edit(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return response()->json([
            'success' => true,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'level' => $role->level,
                'is_core' => Role::isCore($role->slug),
                'permission_ids' => $role->permissions()->pluck('permissions.id'),
            ],
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        // Slug is stable once created — renaming must not break gate/role references.
        $role->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'level' => $request->integer('level'),
        ]);

        $role->permissions()->sync($request->input('permissions', []));

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $role->fresh(),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        // Enforced here (not only in the policy) because super admins bypass
        // policy checks via Gate::before — core roles must never be deletable.
        if (Role::isCore($role->slug)) {
            return response()->json([
                'success' => false,
                'message' => 'Built-in roles cannot be deleted.',
            ], 422);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This role is still assigned to users. Reassign them before deleting.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 1;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
