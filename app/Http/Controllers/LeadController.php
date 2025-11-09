<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // Get all leads
    public function index()
    {
        return Lead::with(['customer', 'agent'])->orderBy('id', 'desc')->paginate(request('per_page', 10));
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
}
