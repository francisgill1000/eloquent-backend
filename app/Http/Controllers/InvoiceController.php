<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Throwable;

class InvoiceController extends Controller
{
    public function index()
    {
        return Invoice::when(request('search'), function ($q) {
            $search = request('search');
            $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']);
        })
            ->where('user_id', request()->user()->id)
            ->with('customer')
            ->orderBy('id', 'desc')
            ->paginate(request('per_page', 10));

        // return response()->json($customers);
    }

    public function store(Request $request)
    {
        try {
            // ✅ Validate request
            $validated = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'due_date' => 'required|date',
                'status' => 'required|string|in:Draft,Paid,Pending,Overdue',
                'discount' => 'nullable|numeric|min:0',
                'subtotal' => 'required|numeric|min:0',
                'total' => 'required|numeric|min:0',
                'items' => 'required|array|min:1',
                'items.*.description' => 'nullable|string',
                'items.*.qty' => 'required|numeric|min:1',
                'items.*.unitPrice' => 'required|numeric|min:0',
            ])->validate();

            // ✅ Use transaction for safety
            DB::beginTransaction();

            // Create invoice
            $invoice = Invoice::create([
                'customer_id' => $validated['customer_id'],
                'user_id' => $request->user()->id,
                'due_date' => date('Y-m-d', strtotime($validated['due_date'])),
                'status' => $validated['status'],
                'discount' => $validated['discount'] ?? 0,
                'subtotal' => $validated['subtotal'],
                'total' => $validated['total'],
            ]);

            // Create related items
            foreach ($validated['items'] as $item) {
                $invoice->items()->create([
                    'description' => $item['description'] ?? '',
                    'qty' => $item['qty'],
                    'unit_price' => $item['unitPrice'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Invoice created successfully!',
                'invoice' => $invoice->load('items', 'customer'),
            ], 201);
        } catch (Throwable $e) {
            // Rollback if any error occurs
            DB::rollBack();

            // Log error for debugging
            Log::error('Invoice creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create invoice.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $invoice = Invoice::findOrFail($id);

        return response()->json($invoice->load('items', 'customer'));
    }

    /**
     * Generates a PDF invoice based on a mock dataset.
     */
    public function generatePdf($id)
    {
        // 1. Prepare Mock Invoice Data (Replace with real database data in a production app)
        // $invoiceData = Invoice::getMockInvoiceData();

        $invoice = Invoice::findOrFail($id);

        $invoiceData = $invoice->load('items', 'customer', 'user');

        $result = [
            /* color: #0b2f50; */
            /* color: #37053e; */

            'invoice' => [
                'invoice_num' => 'INV-'.str_pad($invoiceData->id, 6, '0', STR_PAD_LEFT),
                'date' => $invoiceData->created_at->format('d M, Y'),
                'due_date' => Carbon::parse($invoiceData->due_date)->format('d M, Y'),
                'terms' => 'Due on Receipt',
                'discount' => number_format($invoiceData->discount, 2),
                'subtotal' => number_format($invoiceData->subtotal, 2),
                'total' => number_format($invoiceData->total, 2),
                'tax_rate' => (0.05 * 100)  . "%", // 5% tax rate

                'grand_total' => number_format($invoiceData->total + ($invoiceData->total * 0.05),2),
            ],

            'primary_color' => '#37053e',
            'status' => Invoice::STATUS_PENDING, // Options: paid, pending, overdue
            'status_class' => (new Invoice)->getStatusClass(Invoice::STATUS_PENDING),
            'company' => [
                'name' => $invoiceData->user->name,
                'address_line1' => '123 Business Lane',
                'address_line2' => 'United Arab Emirates, Dubai, 00000',
                'email' => $invoiceData->user->email,
                'phone' => $invoiceData->user->phone,
            ],

            'items' => $invoiceData->items->map(function ($item) {
                return [
                    'description' => $item->description,
                    'detail' => $item->description,
                    'qty' => $item->qty,
                    'unit_price' => number_format($item->unit_price, 2),
                    'total' => number_format($item->qty * $item->unit_price, 2),
                ];
            })->toArray(),

            'client' => [
                'name' => $invoiceData->customer->name,
                'address' => $invoiceData->customer->address,
                'email' => $invoiceData->customer->email,
                'phone' => $invoiceData->customer->phone,
            ],
        ];

        // return $invoiceData;

        // 2. Load the Blade view and pass the data
        $pdf = Pdf::loadView('templates.basic', (array) $result);

        // 3. Return the PDF
        // Use ->stream() to display in the browser, or ->download('invoice.pdf') to force a download.
        return $pdf->download('Invoice-'.$invoiceData['id'].'.pdf');
    }
}
