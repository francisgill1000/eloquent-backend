@extends('reports._layout', ['title' => 'Revenue & Financial'])

@section('content')
    <table class="kpi-row">
        <tr>
            <td style="width: 25%; padding-right: 6px;">
                <div class="kpi">
                    <div class="kpi-label">Gross Revenue</div>
                    <div class="kpi-value">AED {{ number_format($data['kpis']['gross_revenue'], 2) }}</div>
                    <div class="kpi-sub">Excl. cancelled</div>
                </div>
            </td>
            <td style="width: 25%; padding: 0 6px;">
                <div class="kpi">
                    <div class="kpi-label">Bookings</div>
                    <div class="kpi-value">{{ $data['kpis']['total_bookings'] }}</div>
                    <div class="kpi-sub">{{ $data['kpis']['completed'] }} done · {{ $data['kpis']['cancelled'] }} cancelled</div>
                </div>
            </td>
            <td style="width: 25%; padding: 0 6px;">
                <div class="kpi">
                    <div class="kpi-label">Avg booking value</div>
                    <div class="kpi-value">AED {{ number_format($data['kpis']['avg_booking_value'], 2) }}</div>
                </div>
            </td>
            <td style="width: 25%; padding-left: 6px;">
                <div class="kpi">
                    <div class="kpi-label">Paid invoices</div>
                    <div class="kpi-value">AED {{ number_format($data['invoices']['paid_total'], 2) }}</div>
                    <div class="kpi-sub">{{ $data['invoices']['paid_count'] }} paid · {{ $data['invoices']['issued_count'] }} unpaid (AED {{ number_format($data['invoices']['issued_total'], 2) }})</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section-title">Daily Trend</div>
    <table class="data">
        <thead>
            <tr>
                <th>Date</th>
                <th class="right">Bookings</th>
                <th class="right">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['daily_trend'] as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row['date'])->format('D, d M') }}</td>
                    <td class="right">{{ $row['bookings'] }}</td>
                    <td class="right">AED {{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align: center; color: #94a3b8; padding: 16px;">No bookings in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Top Services by Revenue</div>
    <table class="data">
        <thead>
            <tr>
                <th>Service</th>
                <th class="right">Count</th>
                <th class="right">Revenue</th>
                <th class="right">Avg price</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['top_services'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">AED {{ number_format($row['revenue'], 2) }}</td>
                    <td class="right">AED {{ number_format($row['avg_price'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No service data.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
