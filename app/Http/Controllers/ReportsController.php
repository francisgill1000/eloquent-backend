<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(protected ReportsAggregator $aggregator)
    {
    }

    public function revenue(Request $request)
    {
        [$shopId, $from, $to] = $this->validated($request);
        return response()->json($this->aggregator->revenueSummary($shopId, $from, $to));
    }

    public function staff(Request $request)
    {
        [$shopId, $from, $to] = $this->validated($request);
        return response()->json($this->aggregator->staffSummary($shopId, $from, $to));
    }

    public function services(Request $request)
    {
        [$shopId, $from, $to] = $this->validated($request);
        return response()->json($this->aggregator->servicesSummary($shopId, $from, $to));
    }

    public function timePatterns(Request $request)
    {
        [$shopId, $from, $to] = $this->validated($request);
        return response()->json($this->aggregator->timePatternsSummary($shopId, $from, $to));
    }

    public function export(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'from'    => 'required|date',
            'to'      => 'required|date',
            'type'    => 'required|in:revenue,staff,services,time-patterns',
            'format'  => 'required|in:pdf,csv',
        ]);

        [$shopId, $from, $to] = $this->validated($request);
        $shop = Shop::find($shopId);
        $type = $request->type;
        $format = $request->format;

        $data = match ($type) {
            'revenue'        => $this->aggregator->revenueSummary($shopId, $from, $to),
            'staff'          => $this->aggregator->staffSummary($shopId, $from, $to),
            'services'       => $this->aggregator->servicesSummary($shopId, $from, $to),
            'time-patterns'  => $this->aggregator->timePatternsSummary($shopId, $from, $to),
        };

        $filename = sprintf('%s-%s-to-%s', $type, $from->toDateString(), $to->toDateString());

        if ($format === 'pdf') {
            $view = match ($type) {
                'revenue'       => 'reports.revenue',
                'staff'         => 'reports.staff',
                'services'      => 'reports.services',
                'time-patterns' => 'reports.time-patterns',
            };
            $pdf = Pdf::loadView($view, [
                'data' => $data,
                'shop' => $shop,
                'from' => $from,
                'to'   => $to,
            ]);
            $pdf->setOption('isRemoteEnabled', true);
            return $pdf->stream("{$filename}.pdf");
        }

        // CSV
        return $this->csvResponse($type, $data, "{$filename}.csv");
    }

    protected function validated(Request $request): array
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'from'    => 'required|date',
            'to'      => 'required|date',
        ]);

        $shopId = (int) $request->shop_id;
        $from = Carbon::parse($request->from)->startOfDay();
        $to   = Carbon::parse($request->to)->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        return [$shopId, $from, $to];
    }

    protected function csvResponse(string $type, array $data, string $filename): StreamedResponse
    {
        $callback = function () use ($type, $data) {
            $out = fopen('php://output', 'w');

            switch ($type) {
                case 'revenue':
                    fputcsv($out, ['KPI', 'Value']);
                    fputcsv($out, ['Range from', $data['range']['from']]);
                    fputcsv($out, ['Range to',   $data['range']['to']]);
                    fputcsv($out, ['Total bookings',     $data['kpis']['total_bookings']]);
                    fputcsv($out, ['Completed',          $data['kpis']['completed']]);
                    fputcsv($out, ['Cancelled',          $data['kpis']['cancelled']]);
                    fputcsv($out, ['Gross revenue (AED)', $data['kpis']['gross_revenue']]);
                    fputcsv($out, ['Avg booking value',  $data['kpis']['avg_booking_value']]);
                    fputcsv($out, ['Invoices paid (AED)', $data['invoices']['paid_total']]);
                    fputcsv($out, ['Invoices issued (AED)', $data['invoices']['issued_total']]);
                    fputcsv($out, []);
                    fputcsv($out, ['Daily trend']);
                    fputcsv($out, ['Date', 'Bookings', 'Revenue (AED)']);
                    foreach ($data['daily_trend'] as $row) {
                        fputcsv($out, [$row['date'], $row['bookings'], $row['revenue']]);
                    }
                    fputcsv($out, []);
                    fputcsv($out, ['Top services']);
                    fputcsv($out, ['Service', 'Count', 'Revenue (AED)', 'Avg price']);
                    foreach ($data['top_services'] as $row) {
                        fputcsv($out, [$row['title'], $row['count'], $row['revenue'], $row['avg_price']]);
                    }
                    break;

                case 'staff':
                    fputcsv($out, ['Staff', 'Total bookings', 'Completed', 'Cancelled', 'Revenue (AED)', 'Avg booking value', 'Completion %', 'Cancellation %']);
                    foreach ($data['staff'] as $row) {
                        fputcsv($out, [
                            $row['staff_name'],
                            $row['total_bookings'],
                            $row['completed'],
                            $row['cancelled'],
                            $row['revenue'],
                            $row['avg_booking_value'],
                            $row['completion_rate'],
                            $row['cancellation_rate'],
                        ]);
                    }
                    break;

                case 'services':
                    fputcsv($out, ['Service', 'Count', 'Revenue (AED)', 'Avg price']);
                    foreach ($data['services'] as $row) {
                        fputcsv($out, [$row['title'], $row['count'], $row['revenue'], $row['avg_price']]);
                    }
                    break;

                case 'time-patterns':
                    fputcsv($out, ['Day \\ Hour', ...range(0, 23)]);
                    foreach ($data['day_labels'] as $i => $label) {
                        fputcsv($out, [$label, ...$data['grid'][$i]]);
                    }
                    fputcsv($out, []);
                    fputcsv($out, ['Day', 'Bookings', 'Revenue']);
                    foreach ($data['day_labels'] as $i => $label) {
                        fputcsv($out, [$label, $data['by_day'][$i]['count'], $data['by_day'][$i]['revenue']]);
                    }
                    break;
            }

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
