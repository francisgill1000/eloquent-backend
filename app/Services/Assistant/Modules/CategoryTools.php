<?php
namespace App\Services\Assistant\Modules;

use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Support\Facades\DB;

/** Owner-assistant service-category tools: list / create / rename / delete. */
class CategoryTools extends MutatingTool
{
    protected function permissions(): array
    {
        return [
            'list_categories'  => 'services.view',
            'create_category'  => 'services.manage',
            'rename_category'  => 'services.manage',
            'delete_category'  => 'services.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'list_categories' => $this->list($call),
            'create_category' => $this->create($call),
            'rename_category' => $this->rename($call),
            'delete_category' => $this->delete($call),
            default           => ['error' => 'unknown_tool'],
        };
    }

    private function resolve(ToolCall $call): array|object
    {
        $name = trim((string) $call->get('name'));
        if ($name === '') {
            return $this->notFound('category');
        }
        $matches = DB::table('parent_categories')->where('shop_id', $call->shop->id)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])->get();
        if ($matches->count() === 0) return $this->notFound('category');
        if ($matches->count() > 1) return $this->ambiguous($matches->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all());
        return $matches->first();
    }

    private function list(ToolCall $call): array
    {
        $rows = DB::table('parent_categories')->where('shop_id', $call->shop->id)->get(['id', 'name']);
        return ['count' => $rows->count(), 'categories' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all()];
    }

    private function create(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('name') ? ['ok' => true] : ['error' => 'not_found', 'what' => 'missing_fields'],
            describe: fn () => ["Add category \"{$call->get('name')}\"", ['category' => "new: {$call->get('name')}"]],
            write: function () use ($call) {
                $id = DB::table('parent_categories')->insertGetId([
                    'shop_id' => $call->shop->id, 'name' => $call->get('name'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return ['id' => $id, 'name' => $call->get('name')];
            },
        );
    }

    private function rename(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: fn ($r) => ["Rename category to \"{$call->get('new_name')}\"", ['name' => "{$r->name} → {$call->get('new_name')}"]],
            write: function ($r) use ($call) {
                DB::table('parent_categories')->where('id', $r->id)->update(['name' => $call->get('new_name'), 'updated_at' => now()]);
                return ['id' => $r->id];
            },
        );
    }

    private function delete(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: fn ($r) => ["Delete category \"{$r->name}\"", ['category' => "{$r->name} removed"]],
            write: function ($r) {
                DB::table('parent_categories')->where('id', $r->id)->delete();
                return ['id' => $r->id];
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'list_categories', 'description' => 'List this business\'s service categories.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_category', 'description' => 'Add a service category by name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
            ['name' => 'rename_category', 'description' => 'Rename a category. Identify by current name, give new_name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'Current name'],
                'new_name' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'new_name']]],
            ['name' => 'delete_category', 'description' => 'Delete a category by name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
        ];
    }
}
