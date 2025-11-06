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

            ]);

            $validatedData['user_id'] = $request->user()->id; // Assuming the user is authenticated

            info($validatedData);

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
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'whatsapp' => 'sometimes|nullable|string|max:20',
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
}
