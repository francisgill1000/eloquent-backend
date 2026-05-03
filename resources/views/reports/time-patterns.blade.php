@extends('reports._layout', ['title' => 'Time Patterns'])

@section('content')
    @php
        // Find max cell value for shading scale
        $maxCell = 0;
        foreach ($data['grid'] as $row) {
            foreach ($row as $v) {
                if ($v > $maxCell) $maxCell = $v;
            }
        }
        $shade = function ($v) use ($maxCell) {
            if ($maxCell === 0 || $v === 0) return '#ffffff';
            $alpha = max(0.08, min(0.85, $v / $maxCell));
            $a = (int) round($alpha * 100);
            return "rgba(75, 142, 255, {$alpha})";
        };
    @endphp

    <div class="section-title">Bookings Heatmap (day × hour)</div>
    <table class="data" style="font-size: 8.5pt;">
        <thead>
            <tr>
                <th></th>
                @for ($h = 0; $h < 24; $h++)
                    <th class="right" style="padding: 4px 3px; min-width: 18px;">{{ $h }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($data['day_labels'] as $i => $label)
                <tr>
                    <td style="font-weight: 700;">{{ $label }}</td>
                    @for ($h = 0; $h < 24; $h++)
                        @php $v = $data['grid'][$i][$h] ?? 0; @endphp
                        <td class="right" style="padding: 4px 3px; background: {{ $shade($v) }};">
                            {{ $v > 0 ? $v : '' }}
                        </td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">By Day of Week</div>
    <table class="data">
        <thead>
            <tr>
                <th>Day</th>
                <th class="right">Bookings</th>
                <th class="right">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['day_labels'] as $i => $label)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="right">{{ $data['by_day'][$i]['count'] }}</td>
                    <td class="right">AED {{ number_format($data['by_day'][$i]['revenue'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">By Hour of Day</div>
    <table class="data">
        <thead>
            <tr>
                <th>Hour</th>
                <th class="right">Bookings</th>
                <th class="right">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @for ($h = 0; $h < 24; $h++)
                @if ($data['by_hour'][$h]['count'] > 0)
                    <tr>
                        <td>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                        <td class="right">{{ $data['by_hour'][$h]['count'] }}</td>
                        <td class="right">AED {{ number_format($data['by_hour'][$h]['revenue'], 2) }}</td>
                    </tr>
                @endif
            @endfor
        </tbody>
    </table>
@endsection
