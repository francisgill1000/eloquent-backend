@extends('reports._layout', ['title' => 'Staff Performance'])

@section('content')
    <div class="section-title">Per-Staff Summary</div>
    <table class="data">
        <thead>
            <tr>
                <th>Staff</th>
                <th class="right">Bookings</th>
                <th class="right">Done</th>
                <th class="right">Cancelled</th>
                <th class="right">Revenue</th>
                <th class="right">Avg value</th>
                <th class="right">Completion</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['staff'] as $row)
                <tr>
                    <td>{{ $row['staff_name'] }}</td>
                    <td class="right">{{ $row['total_bookings'] }}</td>
                    <td class="right">{{ $row['completed'] }}</td>
                    <td class="right">{{ $row['cancelled'] }}</td>
                    <td class="right">AED {{ number_format($row['revenue'], 2) }}</td>
                    <td class="right">AED {{ number_format($row['avg_booking_value'], 2) }}</td>
                    <td class="right">{{ $row['completion_rate'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 16px;">No staff bookings in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
