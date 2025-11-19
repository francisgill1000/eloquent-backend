<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // Get all leads
    public function index()
    {
        return Lead::with(['customer', 'agent', 'activities.user'])
            ->when(request('status'), function ($query) {
                $query->where('status', request('status'));
            })
            ->where('status', '!=', Lead::STATUS_CONVERTED_TO_DEAL)
            ->orderBy('id', 'desc')
            ->paginate(request('per_page', 10));
    }

    // Store new lead
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:users,id',
            'agent_id' => 'nullable|exists:users,id',
            'source' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $lead = Lead::create($request->all());

        return response()->json(['message' => 'Lead created successfully', 'lead' => $lead], 201);
    }

    // Update lead
    public function update(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);

        $lead->update($request->only(['agent_id', 'source', 'status']));

        return response()->json(['message' => 'Lead updated successfully', 'lead' => $lead]);
    }

    // Delete lead
    public function destroy($id)
    {
        $lead = Lead::findOrFail($id);

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully']);
    }

    public function summary()
    {
        $leadCounts = Lead::selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')  // returns ['New' => 40, 'Contacted' => 25, ...]
            ->toArray();

        // Ensure all statuses exist in response, even if 0
        $allStatuses = Lead::statuses();
        foreach ($allStatuses as $status) {
            if (!isset($leadCounts[$status])) {
                $leadCounts[$status] = 0;
            }
        }

        return response()->json($leadCounts);
    }

    public function countPerAgent()
    {
        // Get all users who have leads assigned as agent
        $agents = User::withCount('leadsAsAgent')->get();

        $result = $agents->map(function ($agent) {
            return [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'lead_count' => $agent->leads_as_agent_count, // automatically provided by withCount
            ];
        });

        return response()->json($result);
    }
}
