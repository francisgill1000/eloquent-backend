<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 400;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-400-normal.woff) format('woff');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 500;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-500-normal.woff) format('woff');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 600;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-600-normal.woff) format('woff');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 700;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-700-normal.woff) format('woff');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 800;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-800-normal.woff) format('woff');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 900;
            src: url(https://cdn.jsdelivr.net/npm/@fontsource/inter@4.5.15/files/inter-latin-900-normal.woff) format('woff');
        }

        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Helvetica', sans-serif;
            color: #0f172a;
            font-size: 10.5pt;
            line-height: 1.5;
            background: #ffffff;
            -webkit-font-smoothing: antialiased;
        }

        /* Top color band */
        .band {
            background: #4b8eff;
            height: 8px;
            width: 100%;
        }

        .container {
            padding: 36px 48px 24px 48px;
            position: relative;
        }

        /* Header */
        .header { width: 100%; margin-bottom: 28px; }
        .header td { vertical-align: top; }
        .brand-name {
            font-size: 22pt;
            font-weight: 900;
            color: #4b8eff;
            letter-spacing: -0.5pt;
            margin-bottom: 4px;
        }
        .brand-meta {
            color: #64748b;
            font-size: 9pt;
            line-height: 1.6;
        }
        .brand-meta strong { color: #334155; font-weight: 600; }

        .invoice-block { text-align: right; }
        .invoice-label {
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5pt;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .invoice-number {
            font-size: 18pt;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.3pt;
        }
        .invoice-date {
            color: #64748b;
            font-size: 9pt;
            margin-top: 6px;
        }
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 1pt;
            margin-top: 8px;
        }
        .badge-issued { background: #dbeafe; color: #1e40af; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        /* Bill-to / Booking info — two column box */
        .info-row { width: 100%; margin: 0 0 28px 0; }
        .info-cell {
            background: #f8fafc;
            border-radius: 10px;
            padding: 16px 18px;
            border: 1px solid #e2e8f0;
        }
        .info-label {
            font-size: 8.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2pt;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .info-name {
            font-size: 13pt;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .info-meta { font-size: 9.5pt; color: #475569; line-height: 1.55; }
        .info-meta .pill {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 8.5pt;
            font-weight: 700;
            color: #475569;
            margin-right: 4px;
        }

        /* Services table */
        .section-header {
            font-size: 9pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.4pt;
            color: #94a3b8;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.items th {
            text-align: left;
            font-size: 8.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1pt;
            color: #64748b;
            background: #f1f5f9;
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.items th.right { text-align: right; }
        table.items td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 11pt;
            color: #0f172a;
            font-weight: 600;
        }
        table.items td.right { text-align: right; font-weight: 700; }
        table.items tr:last-child td { border-bottom: none; }

        /* Totals box (right aligned) */
        .totals-wrap { width: 100%; }
        .totals-wrap td { vertical-align: top; }
        .totals-spacer { width: 60%; }
        .totals-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
        }
        .totals-row {
            width: 100%;
            font-size: 10.5pt;
        }
        .totals-row td {
            padding: 5px 0;
        }
        .totals-row td.label { color: #64748b; }
        .totals-row td.value { text-align: right; font-weight: 700; color: #0f172a; }
        .totals-row tr.grand td {
            font-size: 13pt;
            font-weight: 900;
            color: #0f172a;
            border-top: 2px solid #0f172a;
            padding-top: 10px;
            padding-bottom: 0;
        }
        .totals-row tr.grand td.label { color: #0f172a; }

        /* Footer */
        .thanks {
            margin-top: 36px;
            padding: 18px 24px;
            background: #f1f5f9;
            border-radius: 10px;
            text-align: center;
        }
        .thanks-title {
            font-size: 12pt;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .thanks-sub {
            color: #64748b;
            font-size: 9.5pt;
        }
        .footer {
            margin-top: 14px;
            color: #94a3b8;
            font-size: 8.5pt;
            text-align: center;
            line-height: 1.5;
        }

        /* Stamp overlay */
        .stamp {
            position: absolute;
            right: 60px;
            top: 320px;
            transform: rotate(-18deg);
            padding: 10px 30px;
            border: 5px solid;
            border-radius: 10px;
            font-size: 36pt;
            font-weight: 900;
            letter-spacing: 6pt;
            opacity: 0.18;
        }
        .stamp-paid { color: #166534; border-color: #166534; }
        .stamp-cancelled { color: #991b1b; border-color: #991b1b; }
    </style>
</head>
<body>
    <div class="band"></div>

    <div class="container">
        @if($invoice->status === 'paid')
            <div class="stamp stamp-paid">PAID</div>
        @elseif($invoice->status === 'cancelled')
            <div class="stamp stamp-cancelled">CANCELLED</div>
        @endif

        {{-- Header: brand left, invoice meta right --}}
        <table class="header">
            <tr>
                <td style="width: 60%;">
                    <div class="brand-name">{{ $shop->name }}</div>
                    <div class="brand-meta">
                        @if(!empty($shop->address)){{ $shop->address }}<br>@endif
                        @if(!empty($shop->whatsapp))<strong>WhatsApp:</strong> {{ $shop->whatsapp }}<br>@endif
                        @if(!empty($shop->shop_code))<strong>Shop code:</strong> {{ $shop->shop_code }}@endif
                    </div>
                </td>
                <td style="width: 40%;" class="invoice-block">
                    <div class="invoice-label">Invoice</div>
                    <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                    <div class="invoice-date">
                        Issued {{ \Carbon\Carbon::parse($invoice->issued_at)->format('d M Y') }}
                        @if($invoice->paid_at)
                            <br>Paid {{ \Carbon\Carbon::parse($invoice->paid_at)->format('d M Y') }}
                        @endif
                    </div>
                    <span class="badge badge-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                </td>
            </tr>
        </table>

        {{-- Bill-to + Booking info side by side --}}
        <table class="info-row">
            <tr>
                <td style="width: 50%; padding-right: 8px;">
                    <div class="info-cell">
                        <div class="info-label">Bill to</div>
                        <div class="info-name">{{ $booking->customer_name ?? 'Guest' }}</div>
                        <div class="info-meta">
                            @if(!empty($booking->customer_whatsapp))
                                {{ $booking->customer_whatsapp }}
                            @else
                                Walk-in customer
                            @endif
                        </div>
                    </div>
                </td>
                <td style="width: 50%; padding-left: 8px;">
                    <div class="info-cell">
                        <div class="info-label">Booking</div>
                        <div class="info-name">{{ $booking->booking_reference }}</div>
                        <div class="info-meta">
                            {{ \Carbon\Carbon::parse($booking->date)->format('d M Y') }}
                            @if($booking->getRawOriginal('start_time'))
                                · {{ substr($booking->getRawOriginal('start_time'), 0, 5) }}
                                @if($booking->getRawOriginal('end_time'))
                                    – {{ substr($booking->getRawOriginal('end_time'), 0, 5) }}
                                @endif
                            @endif
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Services --}}
        <div class="section-header">Services</div>
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

        {{-- Totals box, right-aligned --}}
        <table class="totals-wrap">
            <tr>
                <td class="totals-spacer"></td>
                <td>
                    <div class="totals-box">
                        <table class="totals-row">
                            <tr>
                                <td class="label">Subtotal</td>
                                <td class="value">AED {{ number_format((float)$invoice->subtotal, 2) }}</td>
                            </tr>
                            <tr class="grand">
                                <td class="label">Total Due</td>
                                <td class="value">AED {{ number_format((float)$invoice->total, 2) }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Thank you / footer --}}
        <div class="thanks">
            <div class="thanks-title">
                @if($invoice->status === 'paid')
                    Thanks for your payment!
                @elseif($invoice->status === 'cancelled')
                    This invoice has been cancelled.
                @else
                    Thanks for choosing {{ $shop->name }}!
                @endif
            </div>
            <div class="thanks-sub">
                @if($invoice->status === 'issued')
                    Please pay AED {{ number_format((float)$invoice->total, 2) }} at your convenience.
                @else
                    Looking forward to seeing you again.
                @endif
            </div>
        </div>

        <div class="footer">
            Generated by Rezzy · Booking {{ $booking->booking_reference }} · Invoice {{ $invoice->invoice_number }}
        </div>
    </div>
</body>
</html>
