@extends('reports._layout', ['title' => 'Service Popularity'])

@section('content')
    <div class="section-title">All Services</div>
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
            @forelse($data['services'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">AED {{ number_format($row['revenue'], 2) }}</td>
                    <td class="right">AED {{ number_format($row['avg_price'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 16px;">No services in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
