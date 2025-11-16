<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Support\Facades\Validator;

class DealController extends Controller
{
    public function index()
    {
        return Deal::with(['customer', 'agent', 'lead'])
            ->when(request('status'), function ($query) {
                $query->where('status', request('status'));
            })
            ->orderBy('status', 'desc')
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

    // Delete lead
    public function destroy($id)
    {
        $lead = Deal::findOrFail($id);

        $lead->delete();

        return response()->json(['message' => 'Deal deleted successfully']);
    }
}
