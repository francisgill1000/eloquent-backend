<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingInvoice;
use App\Services\Ziina;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

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

    public function markPaid(Request $request, $invoiceId)
    {
        $invoice = BookingInvoice::with('booking:id,shop_id')->findOrFail($invoiceId);

        abort_unless($request->user() && $invoice->booking && $invoice->booking->shop_id === $request->user()->id, 403, 'This action is not permitted.');

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

        $result = $ziina->paymentLinkForBooking($booking);

        if (!$result['ok']) {
            $code = match ($result['reason']) {
                'below_minimum' => 422,
                'error'         => 502,
                default         => 409, // cancelled / paid / invalid_status
            };
            return response()->json(['message' => $result['message']], $code);
        }

        return response()->json([
            'data' => [
                'redirect_url' => $result['url'],
                'intent_id'    => $result['intent_id'],
                'status'       => $result['status'],
            ],
        ]);
    }
}
