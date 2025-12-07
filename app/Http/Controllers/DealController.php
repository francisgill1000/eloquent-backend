<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class DealController extends Controller
{
    public function index()
    {
        return Deal::search(['customer.name'])->with(['customer', 'agent', 'lead'])
            ->when(request('status'), function ($query) {
                $query->where('status', request('status'));
            })
            ->orderBy('id', 'desc')
            // ->filterByKey("agent_id")
            ->paginate(request('per_page', 10));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lead_id' => 'required|exists:leads,id',
            'customer_id' => 'required|exists:users,id',
            'agent_id' => 'required|exists:users,id',
            'deal_title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'status' => 'required|in:Open,Negotiation,Closed-Won,Closed-Lost',
            'expected_close_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deal = Deal::create($validator->validated());

        $lead = Lead::findOrFail($request->lead_id);

        $lead->status = Lead::STATUS_CONVERTED_TO_DEAL;
        $lead->updated_at = now();
        $lead->save();


        return response()->json([
            'message' => 'Deal created successfully',
            'data' => $deal,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'lead_id' => 'required|exists:leads,id',
            'customer_id' => 'required|exists:users,id',
            'agent_id' => 'required|exists:users,id',
            'deal_title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'status' => 'required|in:Open,Negotiation,Closed-Won,Closed-Lost',
            'expected_close_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deal = Deal::find($id);

        $deal->update($validator->validated());

        return response()->json([
            'message' => 'Deal created successfully',
            'data' => $deal,
        ], 201);
    }

    // Delete lead
    public function destroy($id)
    {
        $lead = Deal::findOrFail($id);

        $lead->delete();

        return response()->json(['message' => 'Deal deleted successfully']);
    }

    public function summary()
    {
        $leadCounts = Deal::selectRaw("status, COUNT(*) as count")
            ->filterByKey("agent_id")
            ->groupBy('status')
            ->pluck('count', 'status')  // returns ['New' => 40, 'Contacted' => 25, ...]
            ->toArray();

        // Ensure all statuses exist in response, even if 0
        $allStatuses = Deal::statuses();
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
        $agents = User::filterByKey()->withCount('dealsAsAgent')->get();

        $result = $agents->map(function ($agent) {
            return [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'deal_count' => $agent->deals_as_agent_count, // automatically provided by withCount
            ];
        });

        return response()->json($result);
    }
}
