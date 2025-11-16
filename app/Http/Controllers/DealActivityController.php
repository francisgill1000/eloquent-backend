<?php

namespace App\Http\Controllers;

use App\Models\DealActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DealActivityController extends Controller
{
    // Get all activities for a specific deal
    public function index()
    {
        return DealActivity::with(['user', "deal"])->orderBy('id', 'desc')->paginate(request('per_page', 10));
    }

    public function activitiesByDeaL($dealId)
    {
        return DealActivity::with(['user', "deal"])->where("deal_id", $dealId)->orderBy('id', 'desc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'deal_id' => 'required',
            'note' => 'required|string',
            'contact_method' => 'required',
            'follow_up_date' => 'nullable|date',
        ]);

        $activity = DealActivity::create([
            'deal_id' => $data['deal_id'],
            'note' => $data['note'],
            'contact_method' => $data['contact_method'],
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Activity added successfully',
            'activity' => $activity->load(['user', 'deal'])
        ], 201);
    }
}
