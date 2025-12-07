<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
            ->filterByKey("agent_id")
            ->paginate(request('per_page', 10));
    }

    // Store new lead
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'agent_id' => 'nullable|exists:users,id',
            'source' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:255',
            'attachment' => 'nullable|string',
            'lat' => 'nullable',
            'lon' => 'nullable',
            'address' => 'nullable'
        ]);

       $validated['agent_id'] = $request->user_id ?? 0;

        $storedFiles = [];

        if ($request->attachments && is_array($request->attachments)) {

            foreach ($request->attachments as $file) {

                // Extract MIME type
                preg_match('/data:(.*?);base64,/', $file, $matches);
                $mimeType = $matches[1];

                $extension = match ($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    default => 'bin',
                };

                // Remove Base64 prefix
                $fileData = preg_replace('/^data:(.*?);base64,/', '', $file);
                $fileData = str_replace(' ', '+', $fileData);

                // Generate filename
                $fileName = 'attachment_' . uniqid() . '.' . $extension;

                // Store file
                Storage::disk('public')->put($fileName, base64_decode($fileData));

                // Add to list
                $storedFiles[] = $fileName;
            }
        }

        // store in database as JSON array
        $validated['attachments'] = json_encode($storedFiles);

        $lead = Lead::create($validated);

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
            ->filterByKey("agent_id")
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
        $agents = User::filterByKey()->withCount('leadsAsAgent')->get();

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
