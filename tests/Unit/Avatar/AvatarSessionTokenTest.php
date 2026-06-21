<?php

namespace Tests\Unit\Avatar;

use App\Services\Avatar\AvatarSessionToken;
use Tests\TestCase;

class AvatarSessionTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.liveavatar.session_secret' => 'unit-secret']);
    }

    public function test_issue_then_verify_roundtrips(): void
    {
        $token = AvatarSessionToken::issue(42, 'dev-abc');
        $this->assertSame(['shop_id' => 42, 'device_id' => 'dev-abc'], AvatarSessionToken::verify($token));
    }

    public function test_tampered_token_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AvatarSessionToken::verify(AvatarSessionToken::issue(1, 'd') . 'x');
    }

    public function test_extract_from_text_finds_marker(): void
    {
        $token = AvatarSessionToken::issue(7, 'dev-9');
        $sys = "You are helpful.\n" . sprintf(AvatarSessionToken::MARKER, $token);
        $this->assertSame($token, AvatarSessionToken::extractFromText($sys));
        $this->assertNull(AvatarSessionToken::extractFromText('no marker here'));
    }
}
