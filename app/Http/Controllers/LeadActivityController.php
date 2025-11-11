<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadActivityController extends Controller
{
    // Get all activities for a specific lead
    public function index($leadId)
    {
        return LeadActivity::with(['user', "lead"])->orderBy('id', 'desc')->paginate(request('per_page', 10));
    }

    public function activitiesByLead($leadId)
    {
        return LeadActivity::with(['user', "lead"])->where("lead_id", $leadId)->orderBy('id', 'desc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => 'required',
            'note' => 'required|string',
        ]);

        $activity = LeadActivity::create([
            'lead_id' => $data['lead_id'],
            'note' => $data['note'],
            'follow_up_date' => date("Y-m-d") ?? null,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Activity added successfully',
            'activity' => $activity->load(['user', 'lead'])
        ], 201);
    }
}
