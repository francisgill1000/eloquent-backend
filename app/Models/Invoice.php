<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    const TAX_RATE = 0.05; // 5% tax rate

    protected $guarded = [];

    protected $appends = ['invoice_number','status_front_class','tax_amount','grand_total'];

    public function getTaxAmountAttribute()
    {
        return (self::TAX_RATE * $this->subtotal);
    }

    public function getGrandTotalAttribute()
    {
        return $this->total + $this->tax_amount;
    }

    public function getInvoiceNumberAttribute()
    {
        return 'INV-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    // Define the Invoice Statuses as public constants
    public const STATUS_PAID    = 'Paid';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_OVERDUE = 'Overdue';
    public const STATUS_DEFAULT = 'Draft'; // 'default' might be better named 'draft' or 'new'


    public function getStatusClass($status): string
    {
        return match ($status) {
            self::STATUS_PAID    => 'status-paid',
            self::STATUS_PENDING => 'status-pending',
            self::STATUS_OVERDUE => 'status-overdue',
            default              => 'status-default',
        };
    }

    public function getStatusFrontClassAttribute()
    {
        return match ($this->status) {
            self::STATUS_PAID    => 'text-green-500 bg-green-500/10',
            self::STATUS_PENDING => 'text-amber-500 bg-amber-500/10',
            self::STATUS_OVERDUE => 'text-red-500 bg-red-500/10',
            default              => 'text-slate-500 bg-slate-500/10',
        };
    }

    /**
     * Simple function to generate mock data for the invoice template.
     */
    public static function getMockInvoiceData()
    {
        return [
            /* color: #0b2f50; */
            /* color: #37053e; */

            'primary_color' => '#37053e',
            'status' => self::STATUS_PENDING, // Options: paid, pending, overdue
            'status_class' => (new self())->getStatusClass(self::STATUS_PENDING),
            'company' => [
                'name' => 'Your Company Name',
                'address_line1' => '123 Business Lane',
                'address_line2' => 'City, State, ZIP',
                'email' => 'info@yourcompany.com',
                'phone' => '(555) 123-567',
            ],
            'client' => [
                'name' => 'Client Company Name',
                'address' => '456 Client Street, ZIP',
                'email' => 'client@email.com',
            ],
            'invoice' => [
                'invoice_num' => 'INV-2023-007',
                'date' => 'October 26, 2023',
                'due_date' => 'November 25, 2023',
                'tax_rate' => 0.08, // 8%
            ],
            'items' => [
                [
                    'description' => 'New Company Formation (Free Zone)',
                    'detail' => 'Complete setup package for a Free Zone LLC, including initial approvals and documentation filing.',
                    'qty' => 1,
                    'unit_price' => 7500.00, // AED
                ],
                [
                    'description' => 'VAT Registration & Compliance',
                    'detail' => 'Full VAT registration with FTA and initial 3 months of compliance and filing support.',
                    'qty' => 1,
                    'unit_price' => 4500.00, // AED
                ],
                [
                    'description' => 'Market Entry Strategy Report',
                    'detail' => 'Detailed analysis of the UAE market for a specific sector, including competitive landscape and licensing requirements.',
                    'qty' => 40, // Hours
                    'unit_price' => 350.00, // AED per hour
                ],
                [
                    'description' => 'Local Sponsor/Agent Arrangement',
                    'detail' => 'Structuring a Mainland company with a local service agent/partner for a 1-year renewable contract.',
                    'qty' => 1,
                    'unit_price' => 12000.00, // AED annual fee
                ],
                [
                    'description' => 'Trademark Registration Assistance',
                    'detail' => 'Search, application, and follow-up for one class of trademark registration in the UAE.',
                    'qty' => 1,
                    'unit_price' => 5500.00, // AED
                ],
            ],
        ];
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
