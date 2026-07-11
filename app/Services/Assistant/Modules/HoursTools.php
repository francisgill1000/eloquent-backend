<?php
namespace App\Services\Assistant\Modules;

use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Support\Facades\DB;

/**
 * Owner-assistant working-hours tools: list / set one weekday's open+close /
 * close a weekday. A "closed" day has no shop_working_hours row (the app's
 * getWorkingHourOrFail treats a missing row as closed).
 */
class HoursTools extends MutatingTool
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected function permissions(): array
    {
        return [
            'list_hours' => 'working_hours.view',
            'set_hours'  => 'working_hours.manage',
            'close_day'  => 'working_hours.manage',
        ];
    }

    public function moduleKey(): ?string
    {
        return 'bookings';
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'list_hours' => $this->list($call),
            'set_hours'  => $this->setHours($call),
            'close_day'  => $this->closeDay($call),
            default      => ['error' => 'unknown_tool'],
        };
    }

    private function dayName(int $d): string
    {
        return self::DAYS[$d] ?? "day {$d}";
    }

    private function list(ToolCall $call): array
    {
        $rows = DB::table('shop_working_hours')->where('shop_id', $call->shop->id)->get();
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(int) $r->day_of_week] = substr((string) $r->start_time, 0, 5) . '-' . substr((string) $r->end_time, 0, 5);
        }
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $out[] = ['day' => $this->dayName($d), 'hours' => $byDay[$d] ?? 'closed'];
        }
        return ['hours' => $out];
    }

    private function setHours(ToolCall $call): array
    {
        $day = (int) $call->get('day_of_week');
        return $this->gate(
            $call,
            resolve: fn () => ($day >= 0 && $day <= 6 && $call->get('start_time') && $call->get('end_time'))
                ? ['day' => $day]
                : ['error' => 'not_found', 'what' => 'missing_fields'],
            describe: fn () => ["Set {$this->dayName($day)} to {$call->get('start_time')}–{$call->get('end_time')}", ['hours' => "{$this->dayName($day)}: {$call->get('start_time')}-{$call->get('end_time')}"]],
            write: function () use ($call, $day) {
                $payload = [
                    'start_time' => $call->get('start_time') . ':00',
                    'end_time' => $call->get('end_time') . ':00',
                    'updated_at' => now(),
                ];
                $base = DB::table('shop_working_hours')->where('shop_id', $call->shop->id)->where('day_of_week', $day);
                if ($base->exists()) {
                    (clone $base)->update($payload);
                } else {
                    DB::table('shop_working_hours')->insert(array_merge($payload, [
                        'shop_id' => $call->shop->id, 'day_of_week' => $day, 'slot_duration' => 30, 'created_at' => now(),
                    ]));
                }
                return ['day' => $day];
            },
        );
    }

    private function closeDay(ToolCall $call): array
    {
        $day = (int) $call->get('day_of_week');
        return $this->gate(
            $call,
            resolve: fn () => ($day >= 0 && $day <= 6) ? ['day' => $day] : ['error' => 'not_found', 'what' => 'invalid_day'],
            describe: fn () => ["Close {$this->dayName($day)} (mark it not open)", ['hours' => "{$this->dayName($day)}: closed"]],
            write: function () use ($call, $day) {
                DB::table('shop_working_hours')->where('shop_id', $call->shop->id)->where('day_of_week', $day)->delete();
                return ['day' => $day];
            },
        );
    }

    public function toolDefs(): array
    {
        $day = ['day_of_week' => ['type' => 'integer', 'description' => '0=Sunday .. 6=Saturday']];
        return [
            ['name' => 'list_hours', 'description' => 'List opening hours for each weekday.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'set_hours', 'description' => 'Set one weekday\'s open and close times. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($day, [
                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h'],
                'end_time' => ['type' => 'string', 'description' => 'HH:MM 24h'],
                'confirmed' => ['type' => 'boolean'],
            ]), 'required' => ['day_of_week', 'start_time', 'end_time']]],
            ['name' => 'close_day', 'description' => 'Mark one weekday as closed (removes its opening hours). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($day, ['confirmed' => ['type' => 'boolean']]), 'required' => ['day_of_week']]],
        ];
    }
}
