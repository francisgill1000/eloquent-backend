<?php

namespace Tests\Feature;

use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopUserPasswordCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_on_assignment(): void
    {
        $user = ShopUser::factory()->create(['password' => 'plaintext123']);

        $this->assertTrue(Hash::check('plaintext123', $user->password));
        $this->assertNotSame('plaintext123', $user->password);
    }

    public function test_password_is_hidden_from_json(): void
    {
        $user = ShopUser::factory()->create(['password' => 'plaintext123']);

        $this->assertArrayNotHasKey('password', $user->toArray());
    }
}
