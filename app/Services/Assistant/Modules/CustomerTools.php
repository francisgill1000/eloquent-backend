<?php
namespace App\Services\Assistant\Modules;

use App\Models\ShopCustomer;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;

/** Owner-assistant customer tools: find / create / update / delete. */
class CustomerTools extends MutatingTool
{
    protected function permissions(): array
    {
        return [
            'find_customer'   => 'customers.view',
            'create_customer' => 'customers.manage',
            'update_customer' => 'customers.manage',
            'delete_customer' => 'customers.manage',
        ];
    }

    public function moduleKey(): ?string
    {
        return 'bookings';
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'find_customer'   => $this->find($call),
            'create_customer' => $this->create($call),
            'update_customer' => $this->update($call),
            'delete_customer' => $this->delete($call),
            default           => ['error' => 'unknown_tool'],
        };
    }

    private function query(ToolCall $call)
    {
        $q = ShopCustomer::where('shop_id', $call->shop->id);
        if ($call->get('whatsapp')) {
            $tail = substr(ShopCustomer::normalize((string) $call->get('whatsapp')), -9);
            return $q->where('whatsapp_normalized', 'LIKE', '%' . $tail);
        }
        $name = trim((string) $call->get('name'));
        return $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%']);
    }

    private function resolve(ToolCall $call): array|ShopCustomer
    {
        if (! $call->get('whatsapp') && trim((string) $call->get('name')) === '') {
            return $this->notFound('customer');
        }
        $matches = $this->query($call)->get();
        if ($matches->count() === 0) return $this->notFound('customer');
        if ($matches->count() > 1) return $this->ambiguous($matches->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'whatsapp' => $c->whatsapp])->all());
        return $matches->first();
    }

    private function find(ToolCall $call): array
    {
        $r = $this->resolve($call);
        if (is_array($r)) {
            return $r; // notFound / ambiguous
        }
        return ['id' => $r->id, 'name' => $r->name, 'whatsapp' => $r->whatsapp];
    }

    private function create(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('whatsapp') ? ['ok' => true] : ['error' => 'not_found', 'what' => 'missing_whatsapp'],
            describe: fn () => ["Add customer \"{$call->get('name')}\" ({$call->get('whatsapp')})", ['customer' => "new: {$call->get('name')}"]],
            write: function () use ($call) {
                $c = ShopCustomer::findOrCreateForShop($call->shop->id, $call->get('whatsapp'), $call->get('name'));
                return ['id' => $c?->id, 'name' => $c?->name];
            },
        );
    }

    private function update(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: function ($c) use ($call) {
                $changes = [];
                if ($call->get('new_name')) $changes['name'] = "{$c->name} → {$call->get('new_name')}";
                if ($call->get('new_whatsapp')) $changes['whatsapp'] = "{$c->whatsapp} → {$call->get('new_whatsapp')}";
                return ["Update customer \"{$c->name}\"", $changes ?: ['customer' => $c->name]];
            },
            write: function ($c) use ($call) {
                if ($call->get('new_name')) $c->name = $call->get('new_name');
                if ($call->get('new_whatsapp')) {
                    $c->whatsapp = $call->get('new_whatsapp');
                    $c->whatsapp_normalized = ShopCustomer::normalize((string) $call->get('new_whatsapp'));
                }
                $c->save();
                return ['id' => $c->id];
            },
        );
    }

    private function delete(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $this->resolve($call),
            describe: fn ($c) => ["Delete customer \"{$c->name}\"", ['customer' => "{$c->name} removed"]],
            write: function ($c) {
                $id = $c->id;
                $c->delete();
                return ['id' => $id];
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'find_customer', 'description' => 'Find a customer by name or WhatsApp number.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'whatsapp' => ['type' => 'string'],
            ]]],
            ['name' => 'create_customer', 'description' => 'Add a customer. Requires whatsapp; name recommended. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'whatsapp' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['whatsapp']]],
            ['name' => 'update_customer', 'description' => 'Update a customer\'s name or WhatsApp. Identify by name or whatsapp; give new_name and/or new_whatsapp. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'Current name to match'],
                'whatsapp' => ['type' => 'string', 'description' => 'Current whatsapp to match'],
                'new_name' => ['type' => 'string'],
                'new_whatsapp' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ]]],
            ['name' => 'delete_customer', 'description' => 'Delete a customer by name or whatsapp. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'whatsapp' => ['type' => 'string'], 'confirmed' => ['type' => 'boolean'],
            ]]],
        ];
    }
}
