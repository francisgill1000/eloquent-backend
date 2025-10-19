<!DOCTYPE html>
<html>

<head>
    <title>Invoice Template</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        /* CRITICAL: Set the page margins to 0 for Dompdf */
        @page {
            margin: 0;
        }

        /* Define global styles for Dompdf */
        body {
            font-family: 'Helvetica', sans-serif;
            margin: 0;
            padding: 0;
            font-size: 10pt;
        }

        /* NEW: Top Gradient Bar Style */
        .top-gradient-bar {
            height: 20px;
            width: 100%;
            margin: 0;
            padding: 0;
        }

        .invoice-box {
            /* Set max-width to 100% and removed explicit width to improve Dompdf compatibility,
               ensuring padding stays inside the page bounds (fixing overflow). */
            padding: 20px;
            max-width: 100%;
            box-sizing: border-box;
            /* Include padding in width calculation */
            line-height: 1.6;
            color: #555;
            background: #ffffff;
        }

        /* --- Layout Styles using Tables (Crucial for Dompdf) --- */
        .header-table {
            width: 100%;
            margin: 0 auto;
            margin-bottom: 25px;
        }

        .title-text {
            font-size: 24pt;
            font-weight: 300;
            text-transform: uppercase;
            color: #333;
            line-height: 1.1;
        }

        .template-text {
            display: block;
            font-size: 10pt;
            color: #888;
            margin-top: -5px;
        }

        .invoice-details-block {
            /* Optional: Add some padding/margin if needed, but keep margins tight */
            padding: 5px;
            display: inline-block;
            /* Helps contain floating elements if you had them */
        }

        .invoice-title {
            /* Styles for "INVOICE #" text */
            font-size: 14px;
            font-weight: bold;
            color: #777;
            /* Light gray for subtle title */
            margin-bottom: 2px;
            /* Small space between title and number */
        }

        .invoice-number {
            /* Styles for the actual number */
            font-size: 16px;
            font-weight: bold;
            color: #000;
        }

        /* Important fix: Override default margins that h1/h5 would apply */
        h1,
        h5,
        .invoice-title,
        .invoice-number {
            margin: 0 !important;
            padding: 0 !important;
        }

        .date-col {
            padding: 0 0 5px 10px;
            text-align: right;
            vertical-align: top;
        }

        .date-col strong {
            display: block;
            color: #444;
            margin-bottom: 3px;
        }

        .address-table {
            width: 100%;
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            /* Padding handled by cell style below */
        }

        .address-table td {
            padding-bottom: 15px;
            /* Adds space under the address block */
        }

        .address-col {
            width: 50%;
            vertical-align: top;
            padding: 0 10px;
            /* border: 1px solid #ddd; */


        }

        .address-col strong {
            display: block;
            font-size: 11pt;
            margin-bottom: 5px;
        }

        .info-block-table {
            width: 100%;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .info-block-table td {
            padding: 10px 15px;
            line-height: 1.2;
            width: 33.33%;
            vertical-align: top;
            border-right: 1px solid #ddd;
        }

        .info-block-table td:last-child {
            border-right: none;
        }

        .info-item span {
            display: block;
            font-size: 8pt;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .info-item .value {
            font-size: 10pt;
            color: #333;
            font-weight: bold;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            margin-bottom: 40px;
        }

        .items-table th,
        .items-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .items-table thead th {
            color: #ffffff;
            font-weight: normal;
            font-size: 10pt;
            text-transform: uppercase;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .footer-table {
            width: 100%;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ccc;
        }

        .terms {
            width: 60%;
            vertical-align: top;
            font-size: 10pt;
        }

        .terms strong {
            display: block;
            margin-bottom: 5px;
            font-size: 11pt;
        }

        .text-right {
            text-align: right;
        }

        .status-paid {
            color: #4CAF50 !important;
            /* Green */
        }

        .status-pending {
            color: #FF9800 !important;
            /* Orange */
        }

        .status-overdue {
            color: #F44336 !important;
            /* Red */
        }

        .status-default {
            color: #37053e !important;
        }
    </style>
</head>

<body>
    <div class="top-gradient-bar" style="background: {{ $primary_color }}"></div>

    <div class="invoice-box">

        <table class="header-table" cellpadding="0" cellspacing="0">
            <tr>
                <td>
                    <div style="width: 150px;padding-left:5px;">
                        <img src="{{ public_path('images/logo.jpg') }}" style="width: 100%;">
                    </div>
                </td>
                <td class="date-col" style="padding-right: 20px;">

                    <div class="invoice-details-block">
                        {{-- Use simple elements and a class for easy styling --}}
                        <div class="invoice-title">INVOICE #</div>
                        <div class="invoice-number">{{ $invoice['invoice_num'] }}</div>
                    </div>

                </td>
            </tr>
        </table>

        <!-- Billing Addresses Section -->
        <table class="address-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="address-col">
                    <strong style="{{ $primary_color }}">FROM:</strong>
                    {{ $company['name'] }}<br>
                    {{ $company['address_line1'] }}<br>
                    {{ $company['address_line2'] }}<br>
                    {{ $company['email'] }}<br>
                    {{ $company['phone'] }}
                </td>
                <td class="address-col">
                    <strong>BILL TO:</strong>
                    {{ $client['name'] }}<br>
                    {{ $client['email'] }}<br>
                    {{ $client['phone'] }}
                </td>
            </tr>
        </table>

        <!-- Invoice Details Block (Mid-section) -->
        <table class="info-block-table" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width: 33.33%; text-align:left;">
                    <div class="info-item">
                        <span>DATE:</span>
                        <span class="value">{{ $invoice['date'] }}</span>
                    </div>
                </td>
                <td style="width: 33.33%; text-align:left;">
                    <div class="info-item">
                        <span>DUE DATE:</span>
                        <span class="value">{{ $invoice['due_date'] }}</span>
                    </div>
                </td>
                <td style="width: 33.33%; text-align:left;">
                    <div class="info-item">
                        <span>STATUS:</span>
                        <span class="value {{ $status_class }}">{{ $status }}</span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table" cellpadding="0" cellspacing="0">
            <thead style="background: {{ $primary_color }};">
                <tr>
                    <th style="width: 50%;">DESCRIPTION</th>
                    <th style="width: 10%;">QTY</th>
                    <th style="width: 20%;" class="text-right">UNIT PRICE</th>
                    <th style="width: 20%;" class="text-right">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td>
                            <strong>{{ $item['description'] }}</strong><br>
                            <span style="font-size: 9pt; color: #777;">{{ $item['detail'] }}</span>
                        </td>
                        <td>{{ $item['qty'] }}</td>
                        <td class="text-right">AED {{ $item['unit_price'] }}</td>
                        <td class="text-right">AED {{ $item['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals and Footer -->
        <table class="footer-table" cellpadding="0" cellspacing="0">
            <tr>
                <!-- Terms & Conditions -->
                <td class="terms">
                    <strong>TERMS & CONDITIONS:</strong>
                    <p>Thank you for your business! Payment must be clear before due date.</p>
                </td>

                <!-- Grand Total Summary -->
                <td style="width: 40%; text-align: right;">
                    <table style="width: 100%; border-collapse: collapse;" cellpadding="0" cellspacing="0">

                        <tr>
                            <td class="text-right"
                                style="width: 60%; padding-top: 15px; padding-bottom: 5px; font-size: 10pt; color: #555;">
                                SUBTOTAL</td>
                            <td class="text-right"
                                style="width: 40%; padding-top: 15px; padding-bottom: 5px; font-size: 10pt; color: #555;">
                                AED {{ $invoice['subtotal'] }}</td>
                        </tr>
                        <tr>
                            <td class="text-right"
                                style="padding-top: 5px; padding-bottom: 5px; font-size: 10pt; color: #555;">TAX</td>
                            <td class="text-right"
                                style="padding-top: 5px; padding-bottom: 5px; font-size: 10pt; color: #555;">
                                AED {{ $invoice['tax'] }}</td>
                        </tr>
                        <tr>
                            <td class="text-right"
                                style="width: 60%; padding-top: 15px; padding-bottom: 5px; font-size: 10pt; color: #555;">
                                DISCOUNT</td>
                            <td class="text-right"
                                style="width: 40%; padding-top: 15px; padding-bottom: 5px; font-size: 10pt; color: #555;">
                                AED {{ $invoice['discount'] }}</td>
                        </tr>
                        <tr>
                            <td class="text-right"
                                style="padding-top: 10px; padding-bottom: 20px; font-size: 14px; color: {{ $primary_color }}; font-weight: bold;">
                                TOTAL</td>
                            <td class="text-right"
                                style="padding-top: 10px; padding-bottom: 20px; font-size: 14px; color: {{ $primary_color }}; font-weight: bold;">
                                AED {{ $invoice['total'] }}</td>
                        </tr>
                    </table> <!-- CLOSING TAG ADDED -->
                </td> <!-- CLOSING TAG ADDED -->
            </tr> <!-- CLOSING TAG ADDED -->
        </table> <!-- CLOSING TAG ADDED -->

    </div> <!-- CLOSING TAG ADDED -->

</body> <!-- CLOSING TAG ADDED -->

</html> <!-- CLOSING TAG ADDED -->
