<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee?->id,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            // Roles/permissions are not stored yet: no roles, and every authenticated user
            // currently holds every permission (see App\Enums\Permission).
            'roles' => [],
            'permissions' => Permission::values(),
        ];
    }
}
