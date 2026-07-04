<?php
namespace App\Services\Assistant\Modules;

use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;

/** Owner-assistant business-profile tools: read + update safe shop fields. */
class ProfileTools extends MutatingTool
{
    /** Fields the assistant is allowed to change (never status/verification/category). */
    private const EDITABLE = ['name', 'location'];

    protected function permissions(): array
    {
        return [
            'get_business_profile'    => 'settings.manage',
            'update_business_profile' => 'settings.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'get_business_profile'    => $this->get($call),
            'update_business_profile' => $this->update($call),
            default                   => ['error' => 'unknown_tool'],
        };
    }

    private function get(ToolCall $call): array
    {
        $shop = $call->shop;
        return ['name' => $shop->name, 'location' => $shop->location];
    }

    private function update(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: function () use ($call) {
                $patch = [];
                foreach (self::EDITABLE as $f) {
                    if ($call->get($f) !== null && $call->get($f) !== '') {
                        $patch[$f] = $call->get($f);
                    }
                }
                return $patch ? ['patch' => $patch] : ['error' => 'not_found', 'what' => 'nothing_to_change'];
            },
            describe: function ($t) use ($call) {
                $changes = [];
                foreach ($t['patch'] as $f => $v) {
                    $changes[$f] = "{$call->shop->{$f}} → {$v}";
                }
                return ['Update business profile', $changes];
            },
            write: function ($t) use ($call) {
                $call->shop->fill($t['patch']);
                $call->shop->save();
                return array_keys($t['patch']);
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'get_business_profile', 'description' => 'Read the business name and location.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'update_business_profile', 'description' => 'Update the business name and/or location. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'location' => ['type' => 'string', 'description' => 'Address / area'],
                'confirmed' => ['type' => 'boolean'],
            ]]],
        ];
    }
}
