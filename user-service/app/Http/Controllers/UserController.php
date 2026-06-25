<?php

namespace App\Http\Controllers;

use App\Events\UserCreated;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::all();
        return response()->json(['data' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($validated);

        // Dispatch Kafka event (added in Step 2.9)
        event(new UserCreated($user));

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
        ]);

        $user->update($validated);

        return response()->json(['data' => $user]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['message' => 'User deleted'], 200);
    }
}