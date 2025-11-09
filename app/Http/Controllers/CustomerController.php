<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Customer::search(['name'])
            ->where('user_id', request()->user()->id)
            ->orderBy('id', 'desc')
            ->with('invoices')
            ->withCount('invoices')
            ->withSum('invoices', 'total')
            ->paginate(request('per_page', 10));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',

                'phone' => [
                    'nullable',
                    'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
                ],
                'whatsapp' => [
                    'nullable',
                    'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
                ],

                'country' => 'required',
                'city' => 'required',
            ]);

            $validatedData['user_id'] = $request->user()->id; // Assuming the user is authenticated

            $customer = Customer::create($validatedData);

            return response()->json($customer, 201);
        } catch (\Exception $e) {
            info($request->user()->id);
            return response()->json($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        return response()->json($customer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',

            'phone' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
            ],
            'whatsapp' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
            ],

            'country' => 'required',
            'city' => 'required',
        ]);

        $customer->update($validatedData);

        return response()->json($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json(null, 204);
    }

    public function stats()
    {
        $customersPerMonth = Customer::selectRaw('strftime("%m", created_at) as month, COUNT(*) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // return $customersPerMonth = Customer::selectRaw('MONTH(created_at) as month, COUNT(*) as total')
        //     ->groupBy('month')
        //     ->orderBy('month')
        //     ->get();

        return $customersPerMonth->map(function ($item) {
            return [
                'month' => date('F', mktime(0, 0, 0, $item->month, 1)), // e.g., January
                'total' => $item->total,
            ];
        });
    }

    public function customersCityWise()
    {
        // Group customers by city and count them
        $data = Customer::select('city')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('city')
            ->get();

        // Return JSON suitable for chart [{name: "Dubai", value: 100}, ...]
        $formattedData = $data->map(function ($item) {
            return [
                'name'  => explode("_", $item->city)[1] ?? '-', // fallback if city not set
                'value' => $item->count,
            ];
        });

        return response()->json($formattedData);
    }
}
