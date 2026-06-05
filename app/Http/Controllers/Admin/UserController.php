<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin-v2.users.index');
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = (string) $request->input('search.value', '');
        $orderColumnIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

        $columns = ['id', 'name', 'email', 'status', 'roles', 'created_at', 'actions'];
        $orderColumn = $columns[$orderColumnIndex] ?? 'id';

        $currentUser = auth()->user();

        $query = User::query()->with('roles');

        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue): void {
                $q->where('name', 'like', "%{$searchValue}%")
                    ->orWhere('email', 'like', "%{$searchValue}%");
            });
        }

        $totalRecords = User::count();
        $filteredRecords = $query->count();

        if (! in_array($orderColumn, ['actions', 'status', 'roles'], true)) {
            $query->orderBy($orderColumn, $orderDirection);
        }

        $users = $query->skip($start)->take($length)->get();

        $data = $users->map(function (User $user) use ($currentUser): array {
            $roleBadges = $user->roles
                ->map(fn (Role $role): string => '<span class="badge bg-primary me-1">'.e($role->name).'</span>')
                ->join('');

            $statusBadges = [];
            if ($user->is_locked) {
                $statusBadges[] = '<span class="badge bg-danger">Locked</span>';
            }
            if ($user->is_disabled) {
                $statusBadges[] = '<span class="badge bg-warning">Disabled</span>';
            }
            if ($user->isActive()) {
                $statusBadges[] = '<span class="badge bg-success">Active</span>';
            }

            return [
                'id' => $user->id,
                'name' => e($user->name),
                'email' => e($user->email),
                'status' => implode(' ', $statusBadges),
                'roles' => $roleBadges ?: '<em class="text-default-400">No roles</em>',
                'created_at' => $user->created_at?->format('Y-m-d'),
                'actions' => view('admin-v2.users.partials.actions', [
                    'user' => $user,
                    'currentUser' => $currentUser,
                ])->render(),
            ];
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
            ]);

            $user->syncRoles($request->input('roles', []));

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user->load('roles'),
        ]);
    }

    public function edit(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load('roles');

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_disabled' => $user->is_disabled,
                'is_locked' => $user->is_locked,
                'role_ids' => $user->roles->pluck('id'),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $userData = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ];

            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->input('password'));
            }

            $user->update($userData);

            if ($request->has('roles')) {
                $this->authorize('assignRoles', User::class);
                $user->syncRoles($request->input('roles', []));
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user->fresh()->load('roles'),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    public function disable(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot disable your own account.',
            ], 403);
        }

        $this->authorize('disable', $user);

        if ($user->is_disabled) {
            $user->enable();
            $message = 'User enabled successfully.';
        } else {
            $user->disable(auth()->user());
            $message = 'User disabled successfully.';
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function lock(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot lock your own account.',
            ], 403);
        }

        $this->authorize('lock', $user);

        if ($user->is_locked) {
            $user->unlock();
            $message = 'User unlocked successfully.';
        } else {
            $user->lock(auth()->user());
            $message = 'User locked successfully.';
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function roles(): JsonResponse
    {
        $this->authorize('assignRoles', User::class);

        $roles = Role::query()
            ->orderByDesc('level')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'level' => $role->level,
            ]);

        return response()->json(['success' => true, 'data' => $roles]);
    }
}
