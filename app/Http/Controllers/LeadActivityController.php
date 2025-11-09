<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Http\Request;

class LeadActivityController extends Controller
{
    // Get all activities for a specific lead
    public function index($leadId)
    {
        return LeadActivity::with(['agent'])->orderBy('id', 'desc')->paginate(request('per_page', 10));
    }

    // Store a new activity for a lead
    public function store(Request $request, $leadId)
    {
        $request->validate([
            'agent_id' => 'nullable|exists:users,id',
            'type' => 'required|string|max:255',
            'description' => 'required|string',
            'follow_up_date' => 'nullable|date',
        ]);

        $lead = Lead::findOrFail($leadId);

        $activity = $lead->activities()->create($request->only([
            'agent_id',
            'type',
            'description',
            'follow_up_date',
        ]));

        return response()->json(['message' => 'Activity added successfully', 'activity' => $activity], 201);
    }

    // Update a specific activity
    public function update(Request $request, $leadId, $activityId)
    {
        $activity = LeadActivity::where('lead_id', $leadId)->findOrFail($activityId);

        $activity->update($request->only([
            'type',
            'description',
            'follow_up_date',
        ]));

        return response()->json(['message' => 'Activity updated successfully', 'activity' => $activity]);
    }

    // Delete an activity
    public function destroy($leadId, $activityId)
    {
        $activity = LeadActivity::where('lead_id', $leadId)->findOrFail($activityId);
        $activity->delete();

        return response()->json(['message' => 'Activity deleted successfully']);
    }
}
