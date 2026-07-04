<?php
namespace App\Services\Assistant\Modules;

use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use Illuminate\Support\Facades\DB;

/** Owner-assistant service (catalog) tools: list / create / update / delete. */
class ServiceTools extends MutatingTool
{
    protected function permissions(): array
    {
        return [
            'list_services'  => 'services.view',
            'create_service' => 'services.manage',
            'update_service' => 'services.manage',
            'delete_service' => 'services.manage',
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'list_services'  => $this->list($call),
            'create_service' => $this->create($call),
            'update_service' => $this->update($call),
            'delete_service' => $this->delete($call),
            default          => ['error' => 'unknown_tool'],
        };
    }

    /** Resolve one catalog row by id or fuzzy title within the shop. */
    private function resolve(ToolCall $call): array|object
    {
        $q = DB::table('catalogs')->where('shop_id', $call->shop->id);
        if ($call->get('catalog_id')) {
            $row = (clone $q)->where('id', (int) $call->get('catalog_id'))->first();
            return $row ?: $this->notFound('service');
        }
        $title = trim((string) $call->get('service_title', $call->get('title')));
        if ($title === '') {
            return $this->notFound('service');
        }
        $matches = (clone $q)->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($title) . '%'])->get();
        if ($matches->count() === 0) {
            return $this->notFound('service');
        }
        if ($matches->count() > 1) {
            return $this->ambiguous($matches->map(fn ($r) => ['id' => $r->id, 'title' => $r->title])->all());
        }
        return $matches->first();
    }

    private function categoryId(ToolCall $call): ?int
    {
        $name = trim((string) $call->get('category'));
        if ($name === '') {
            return null;
        }
        $cat = DB::table('parent_categories')->where('shop_id', $call->shop->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        return $cat?->id;
    }

    private function list(ToolCall $call): array
    {
        $rows = DB::table('catalogs')->where('shop_id', $call->shop->id)->get(['id', 'title', 'price']);
        return ['count' => $rows->count(), 'services' => $rows->map(fn ($r) => [
            'id' => $r->id, 'title' => $r->title, 'price' => (float) $r->price,
        ])->all()];
    }

    private function create(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('title') && $call->get('price') !== null
                ? ['ok' => true]
                : ['error' => 'not_found', 'what' => 'missing_fields'],
            describe: fn () => ["Add service \"{$call->get('title')}\" at {$call->get('price')} dirhams", ['service' => "new: {$call->get('title')}"]],
            write: function () use ($call) {
                $id = DB::table('catalogs')->insertGetId([
                    'shop_id' => $call->shop->id,
                    'title' => $call->get('title'),
                    'description' => $call->get('description', ''),
                    'price' => $call->get('price'),
                    'parent_category_id' => $this->categoryId($call),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return ['id' => $id, 'title' => $call->get('title')];
            },
        );
    }

    private function update(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: function ($r) use ($call) {
                $changes = [];
                if ($call->get('price') !== null) $changes['price'] = "{$r->price} → {$call->get('price')}";
                if ($call->get('title')) $changes['title'] = "{$r->title} → {$call->get('title')}";
                return ["Update service \"{$r->title}\"", $changes ?: ['service' => $r->title]];
            },
            write: function ($r) use ($call) {
                $patch = ['updated_at' => now()];
                if ($call->get('price') !== null) $patch['price'] = $call->get('price');
                if ($call->get('title')) $patch['title'] = $call->get('title');
                if ($call->get('description') !== null) $patch['description'] = $call->get('description');
                if ($call->get('category')) $patch['parent_category_id'] = $this->categoryId($call);
                DB::table('catalogs')->where('id', $r->id)->update($patch);
                return ['id' => $r->id];
            },
        );
    }

    private function delete(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: fn ($r) => ["Delete service \"{$r->title}\"", ['service' => "{$r->title} removed"]],
            write: function ($r) {
                DB::table('catalogs')->where('id', $r->id)->delete();
                return ['id' => $r->id];
            },
        );
    }

    public function toolDefs(): array
    {
        $ident = [
            'catalog_id' => ['type' => 'integer', 'description' => 'Service id (preferred if known)'],
            'service_title' => ['type' => 'string', 'description' => 'Service name to match'],
        ];
        return [
            ['name' => 'list_services', 'description' => 'List this business\'s services with prices.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_service', 'description' => 'Add a service. Requires title and price; optional description and category name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'price' => ['type' => 'number'],
                'description' => ['type' => 'string'],
                'category' => ['type' => 'string', 'description' => 'Category name'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['title', 'price']]],
            ['name' => 'update_service', 'description' => 'Change a service (price, title, description, category). Identify by catalog_id or service_title. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ident, [
                'title' => ['type' => 'string', 'description' => 'New title'],
                'price' => ['type' => 'number'],
                'description' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ])]],
            ['name' => 'delete_service', 'description' => 'Delete a service by catalog_id or service_title. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => array_merge($ident, ['confirmed' => ['type' => 'boolean']])]],
        ];
    }
}
