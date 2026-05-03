<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingInvoice;
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

    public function markPaid($invoiceId)
    {
        $invoice = BookingInvoice::findOrFail($invoiceId);

        if ($invoice->status !== 'issued') {
            return response()->json([
                'message' => "Invoice is already {$invoice->status}.",
            ], 409);
        }

        $invoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        return response()->json(['data' => $invoice->fresh()]);
    }
}
