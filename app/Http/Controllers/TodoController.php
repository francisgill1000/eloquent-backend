<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TodoController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = Auth::user();

        return Todo::where('user_id', $user->id)->orderBy('id', 'desc')->paginate(request('per_page', 10));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $todo = Todo::create([
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        return response()->json([
            'message' => "Todo created successfully",
            'todo' => $todo,
        ], 201);
    }

    public function update(Request $request, Todo $todo)
    {
        $this->authorize('update', $todo);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'nullable|string',
            'status' => 'in:Pending,Hold,Done,Cancelled',
            'due_date' => 'nullable|date',
        ]);

        $todo->update($validated);

        return response()->json([
            'message' => 'Todo updated successfully',
            'todo' => $todo,
        ]);
    }

    public function destroy(Todo $todo)
    {
        $this->authorize('delete', $todo);

        $todo->delete();

        return response()->json(['message' => 'Todo deleted successfully']);
    }
}
