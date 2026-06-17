<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Services\Ziina;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingInvoiceController extends Controller
{
    public function show($bookingId)
    {
        $booking = Booking::with(['shop', 'staff:id,name', 'invoice'])->findOrFail($bookingId);

        if (!$booking->invoice) {
            return response()->json(['message' => 'No invoice for this booking.'], 404);
        }

        return response()->json(['data' => $booking->invoice]);
    }

    public function pdf($bookingId)
    {
        $booking = Booking::with(['shop', 'invoice'])->findOrFail($bookingId);

        if (!$booking->invoice) {
            abort(404, 'No invoice for this booking.');
        }

        $pdf = Pdf::loadView('invoices.booking-invoice', [
            'booking' => $booking,
            'invoice' => $booking->invoice,
            'shop'    => $booking->shop,
        ]);

        // Allow @font-face to fetch fonts from CDN (Inter via JSDelivr)
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->stream("{$booking->invoice->invoice_number}.pdf");
    }

    public function markPaid($invoiceId)
    {
        $invoice = BookingInvoice::findOrFail($invoiceId);

        if (!$invoice->markPaid()) {
            return response()->json([
                'message' => "Invoice is already {$invoice->status}.",
            ], 409);
        }

        return response()->json(['data' => $invoice->fresh()]);
    }

    /**
     * Create (or reuse) a Ziina payment intent for this booking's invoice and
     * return the hosted-page redirect URL. The webhook is what actually marks
     * the invoice paid — this just gets the customer to the payment page.
     */
    public function pay($bookingId, Ziina $ziina)
    {
        $booking = Booking::with('invoice')->findOrFail($bookingId);
        $invoice = $booking->invoice;

        if (!$invoice) {
            return response()->json(['message' => 'No invoice for this booking.'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice is already paid.'], 409);
        }

        if ($invoice->status !== 'issued') {
            return response()->json(['message' => "Invoice is {$invoice->status}."], 409);
        }

        // Ziina rejects transfers under 2 AED — fail fast with a clear message.
        if ((float) $invoice->total < 2) {
            return response()->json(['message' => 'Amount is below the 2 AED minimum for online payment.'], 422);
        }

        // Stable idempotency key — one per invoice, so retries never double-charge.
        if (empty($invoice->ziina_operation_id)) {
            $invoice->update(['ziina_operation_id' => (string) Str::uuid()]);
        }

        $base = rtrim((string) config('services.ziina.return_base'), '/');
        $return = "{$base}/booking/{$booking->id}";

        try {
            $intent = $ziina->createIntent($invoice, [
                'success_url' => "{$return}?pay=success",
                'cancel_url'  => "{$return}?pay=cancel",
                'failure_url' => "{$return}?pay=failed",
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Ziina createIntent failed: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id,
            ]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $invoice->update(['ziina_intent_id' => $intent['id'] ?? null]);

        return response()->json([
            'data' => [
                'redirect_url' => $intent['redirect_url'] ?? null,
                'intent_id'    => $intent['id'] ?? null,
                'status'       => $intent['status'] ?? null,
            ],
        ]);
    }
}
