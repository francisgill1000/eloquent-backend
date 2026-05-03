<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #1a1a1a; font-size: 11pt; }
        .container { max-width: 720px; margin: 0 auto; padding: 30px; position: relative; }
        .header { border-bottom: 3px solid #4b8eff; padding-bottom: 16px; margin-bottom: 24px; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .shop-name { font-size: 20pt; font-weight: 900; color: #4b8eff; }
        .shop-meta { color: #6b7280; font-size: 9pt; line-height: 1.5; }
        .invoice-title { font-size: 14pt; font-weight: 900; text-align: right; }
        .invoice-meta { text-align: right; font-size: 9pt; color: #6b7280; line-height: 1.6; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: 900; font-size: 9pt; text-transform: uppercase; letter-spacing: 1px; }
        .status-issued { background: #4b8eff20; color: #4b8eff; }
        .status-paid { background: #22c55e20; color: #16a34a; }
        .status-cancelled { background: #ef444420; color: #dc2626; }
        .section-title { font-size: 9pt; text-transform: uppercase; letter-spacing: 1.5px; color: #6b7280; font-weight: 700; margin-bottom: 6px; }
        .customer-block { margin-bottom: 24px; }
        .customer-name { font-size: 13pt; font-weight: 700; }
        .customer-meta { color: #6b7280; font-size: 9pt; margin-top: 2px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.items th { text-align: left; font-size: 9pt; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 8px 0; }
        table.items td { padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 11pt; }
        table.items td.right { text-align: right; }
        .totals { margin-top: 16px; width: 100%; }
        .totals td { padding: 4px 0; font-size: 11pt; }
        .totals td.label { text-align: right; color: #6b7280; padding-right: 16px; width: 75%; }
        .totals td.value { text-align: right; font-weight: 700; }
        .totals tr.total td { font-size: 13pt; font-weight: 900; border-top: 2px solid #1a1a1a; padding-top: 8px; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 8pt; text-align: center; }
        .stamp { position: absolute; right: 60px; top: 220px; transform: rotate(-15deg); padding: 8px 24px; border: 4px solid; border-radius: 8px; font-size: 28pt; font-weight: 900; letter-spacing: 4px; opacity: 0.4; }
        .stamp-paid { color: #16a34a; border-color: #16a34a; }
        .stamp-cancelled { color: #dc2626; border-color: #dc2626; }
    </style>
</head>
<body>
    <div class="container">
        @if($invoice->status === 'paid')
            <div class="stamp stamp-paid">PAID</div>
        @elseif($invoice->status === 'cancelled')
            <div class="stamp stamp-cancelled">CANCELLED</div>
        @endif

        <div class="header">
            <table class="header-table">
                <tr>
                    <td>
                        <div class="shop-name">{{ $shop->name }}</div>
                        <div class="shop-meta">
                            @if(!empty($shop->address)) {{ $shop->address }}<br> @endif
                            @if(!empty($shop->whatsapp)) WhatsApp: {{ $shop->whatsapp }}<br> @endif
                            @if(!empty($shop->shop_code)) Code: {{ $shop->shop_code }} @endif
                        </div>
                    </td>
                    <td>
                        <div class="invoice-title">INVOICE {{ $invoice->invoice_number }}</div>
                        <div class="invoice-meta">
                            Issued: {{ \Carbon\Carbon::parse($invoice->issued_at)->format('d M Y') }}<br>
                            @if($invoice->paid_at)
                                Paid: {{ \Carbon\Carbon::parse($invoice->paid_at)->format('d M Y') }}<br>
                            @endif
                            <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="customer-block">
            <div class="section-title">Bill to</div>
            <div class="customer-name">{{ $booking->customer_name ?? 'Guest' }}</div>
            <div class="customer-meta">
                @if(!empty($booking->customer_whatsapp)) {{ $booking->customer_whatsapp }} · @endif
                Booking {{ $booking->booking_reference }}
                · {{ \Carbon\Carbon::parse($booking->date)->format('d M Y') }}
                {{ $booking->getRawOriginal('start_time') ? '· ' . substr($booking->getRawOriginal('start_time'), 0, 5) : '' }}
            </div>
        </div>

        <div class="section-title">Services</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right">Price</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $services = is_array($booking->services) ? $booking->services : (json_decode($booking->services ?? '[]', true) ?: []);
                @endphp
                @forelse($services as $service)
                    <tr>
                        <td>{{ $service['title'] ?? $service['name'] ?? 'Service' }}</td>
                        <td class="right">AED {{ number_format((float)($service['price'] ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td>Booking total</td>
                        <td class="right">AED {{ number_format((float)$invoice->total, 2) }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">AED {{ number_format((float)$invoice->subtotal, 2) }}</td>
            </tr>
            <tr class="total">
                <td class="label">Total</td>
                <td class="value">AED {{ number_format((float)$invoice->total, 2) }}</td>
            </tr>
        </table>

        <div class="footer">
            Generated by Rezzy · Booking {{ $booking->booking_reference }} · Invoice {{ $invoice->invoice_number }}
        </div>
    </div>
</body>
</html>
