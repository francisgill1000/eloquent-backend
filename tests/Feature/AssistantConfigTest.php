<?php
namespace Tests\Feature;

use Tests\TestCase;

class AssistantConfigTest extends TestCase
{
    public function test_mutations_enabled_defaults_true(): void
    {
        $this->assertTrue(config('assistant.mutations_enabled'));
    }
}
