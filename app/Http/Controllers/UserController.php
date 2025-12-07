<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreRequent;
use App\Http\Requests\User\UpdateRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return User::search(['name'])
            ->filterByKey('user_created_by_id')
            ->where('user_type', request("user_type", "customer"))
            ->orderBy('id', 'desc')
            ->paginate(request('per_page', 10));
    }

    public function store(StoreRequent $request)
    {
        $validatedData = $request->validated();

        $password = !empty($validatedData['password']) ? $validatedData['password'] : "welcome123";

        $validatedData['password'] = ($password);

        $validatedData['user_created_by_id'] = Auth::id();

        $user = User::create($validatedData);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    public function show($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json($user);
    }

    public function update(UpdateRequest $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validatedData = $request->validated();

        if (!empty($validatedData['password'])) {
            $validatedData['password'] = $validatedData['password'];
        } else {
            unset($validatedData['password']);
        }

        $user->update($validatedData);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }


    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function dropDown()
    {
        $users = User::orderBy('id', 'desc')
            ->where('user_type', request("user_type", "customer"))
            ->where('user_created_by_id', request("user_id"))
            ->get();

        return response()->json($users);
    }
}
