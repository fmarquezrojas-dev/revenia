<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateCompanyUser;
use App\Actions\Users\UpdateCompanyUser;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(
        Request $request,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return UserResource::collection($users);
    }

    public function store(
        StoreUserRequest $request,
        CreateCompanyUser $action,
    ): JsonResponse {
        $user = $action->handle(
            $request->user(),
            $request->validated(),
        );

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateUserRequest $request,
        UpdateCompanyUser $action,
    ): JsonResponse {
        $user = $action->handle(
            $request->user(),
            $request->targetUser(),
            $request->validated(),
        );

        return (new UserResource($user))->response();
    }
}
