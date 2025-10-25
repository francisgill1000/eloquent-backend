<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceReminder;
use Illuminate\Http\Request;

class InvoiceReminderController extends Controller
{

    public function invoiceReminder(Request $request, $id)
    {
        $invoice = Invoice::find($id);

        $status = $invoice->status;

        if (!in_array($status, ['Pending', 'Overdue'])) {
            return response()->json([
                'success' => false,
                'message' => 'Reminders can only be sent for pending or overdue invoices.',
            ], 400);
        }

        $method = $request->method ?? "Email";

        if (!in_array($method, InvoiceReminder::Methods)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Support methods are Email Whatsapp SMS'
            ], 400);
        }

        $payload = [
            'invoice_id' => $invoice->id,
            'method' => $method,
            'message' => InvoiceReminder::generateTemplate($invoice, $method),
            'status' => 'sent',
        ];

        try {
            // Send the reminder logic (you can replace this with actual sending logic)
            if ($method === 'email') {
                // e.g. dispatch(new SendInvoiceEmailJob($invoice));
            } elseif ($method === 'whatsapp') {
                // e.g. dispatch(new SendInvoiceWhatsappJob($invoice));
            }

            $responsePayload = [
                'success' => true,
                'message' => ucfirst($method) . ' reminder sent successfully!',
                'statusCode' => 200,
            ];
        } catch (\Throwable $e) {
            // If failed, mark the payload as failed
            $payload['status'] = 'failed';
            $payload['message'] = "Failed to send {$method} reminder.";

            $responsePayload = [
                'success' => false,
                'message' => $payload['message'],
                'statusCode' => 500,
                'error' => $e->getMessage(),
            ];
        }

        // Log reminder attempt
        InvoiceReminder::create($payload);

        info($payload);

        return response()->json($responsePayload, $responsePayload['statusCode']);
    }
}
