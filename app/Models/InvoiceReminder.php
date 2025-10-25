<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceReminder extends Model
{
    const Methods = ['Email', 'Whatsapp', 'SMS'];

    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Generate Email Reminder Message
     */
    public static function emailTemplate($invoice)
    {
        $companyName = config('app.name');
        $companyEmail = config('mail.from.address');
        $companyPhone = env('COMPANY_PHONE', 'N/A');

        $date = $invoice->date_only;

        $message = <<<EOT
Dear {$invoice->customer->name},

I hope this message finds you well.

This is a friendly reminder that Invoice #{$invoice->invoice_number}, with a total of AED {$invoice->total}, is currently marked as {$invoice->status} and is due on {$date}.

We would greatly appreciate it if you could arrange payment at your earliest convenience.
If you’ve already made the payment, please disregard this message.

Thank you for your continued partnership.

Best regards,
{$companyName}
{$companyEmail}
{$companyPhone}
EOT;

        return $message;
    }

    /**
     * Generate WhatsApp Reminder Message
     */
    public static function whatsappTemplate($invoice)
    {
        $companyName = config('app.name');
        $date = date('M d, Y', strtotime($invoice->due_date));
        $date = $invoice->date_only;
        $message = <<<EOT
Hello {$invoice->customer->name} 👋

Just a quick reminder — Invoice #{$invoice->invoice_number} (AED {$invoice->total}) is {$invoice->status} and due on $date.

You can make the payment using your preferred method or contact us if you’ve already completed it.

Thank you 🙏
— {$companyName}
EOT;

        return $message;
    }

    /**
     * Generate SMS Reminder Message
     */
    public static function smsTemplate($invoice)
    {
        $companyName = config('app.name');

        $invoice_number = $invoice->invoice_number;
        $total = $invoice->total;
        $status = $invoice->status;
        $date = $invoice->date_only;;

        $message = "Reminder: Invoice #$invoice_number  (AED $total) is $status and due on $date. Please arrange payment soon. — $companyName";

        return $message;
    }

    /**
     * General Template Dispatcher
     */
    public static function generateTemplate($invoice, $method)
    {

        return match ($method) {
            'Email' => self::emailTemplate($invoice),
            'Whatsapp' => self::whatsappTemplate($invoice),
            'SMS' => self::smsTemplate($invoice),
            default => throw new \InvalidArgumentException("Invalid reminder method: {$method}"),
        };
    }
}
