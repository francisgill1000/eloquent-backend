<?php
namespace App\Services\Assistant\Modules;

use App\Models\Staff;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;

/** Owner-assistant staff tools: list / create / update / delete. */
class StaffTools extends MutatingTool
{
    protected function permissions(): array
    {
        return [
            'list_staff'   => 'staff.view',
            'create_staff' => 'staff.manage',
            'update_staff' => 'staff.manage',
            'delete_staff' => 'staff.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'list_staff'   => $this->list($call),
            'create_staff' => $this->create($call),
            'update_staff' => $this->update($call),
            'delete_staff' => $this->delete($call),
            default        => ['error' => 'unknown_tool'],
        };
    }

    private function resolve(ToolCall $call): array|Staff
    {
        $name = trim((string) $call->get('name'));
        if ($name === '') {
            return $this->notFound('staff');
        }
        $matches = Staff::where('shop_id', $call->shop->id)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])->get();
        if ($matches->count() === 0) return $this->notFound('staff');
        if ($matches->count() > 1) return $this->ambiguous($matches->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all());
        return $matches->first();
    }

    private function list(ToolCall $call): array
    {
        $rows = Staff::where('shop_id', $call->shop->id)->get(['id', 'name', 'is_active']);
        return ['count' => $rows->count(), 'staff' => $rows->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'active' => (bool) $s->is_active,
        ])->all()];
    }

    private function create(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('name') ? ['ok' => true] : ['error' => 'not_found', 'what' => 'missing_fields'],
            describe: fn () => ["Add staff member \"{$call->get('name')}\"", ['staff' => "new: {$call->get('name')}"]],
            write: function () use ($call) {
                $staff = Staff::create(['shop_id' => $call->shop->id, 'name' => $call->get('name'), 'is_active' => true]);
                return ['id' => $staff->id, 'name' => $staff->name];
            },
        );
    }

    private function update(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: function ($s) use ($call) {
                $changes = [];
                if ($call->get('new_name')) $changes['name'] = "{$s->name} → {$call->get('new_name')}";
                if ($call->get('is_active') !== null) $changes['active'] = $call->get('is_active') ? 'active' : 'inactive';
                return ["Update staff \"{$s->name}\"", $changes ?: ['staff' => $s->name]];
            },
            write: function ($s) use ($call) {
                if ($call->get('new_name')) $s->name = $call->get('new_name');
                if ($call->get('is_active') !== null) $s->is_active = (bool) $call->get('is_active');
                $s->save();
                return ['id' => $s->id];
            },
        );
    }

    private function delete(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: fn ($s) => ["Delete staff member \"{$s->name}\"", ['staff' => "{$s->name} removed"]],
            write: function ($s) {
                $id = $s->id;
                $s->delete();
                return ['id' => $id];
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'list_staff', 'description' => 'List this business\'s staff and whether they are active.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_staff', 'description' => 'Add a staff member by name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'update_staff', 'description' => 'Rename a staff member or set them active/inactive. Identify by name; give new_name and/or is_active. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'Current name'],
                'new_name' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'delete_staff', 'description' => 'Delete a staff member by name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
        ];
    }
}
