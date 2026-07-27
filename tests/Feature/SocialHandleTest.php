<?php

namespace Tests\Feature;

use App\Support\SocialHandle;
use Tests\TestCase;

class SocialHandleTest extends TestCase
{
    public function test_bare_and_at_prefixed_handles_normalize_to_a_url(): void
    {
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', 'acmegym'));
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', '@acmegym'));
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', '  @acmegym  '));
    }

    public function test_handles_containing_dots_are_not_mistaken_for_domains(): void
    {
        // Instagram handles may contain dots — "acme.gym" is a handle, not a host.
        $this->assertSame('https://instagram.com/acme.gym', SocialHandle::normalize('instagram', 'acme.gym'));
    }

    public function test_urls_in_any_form_normalize_to_the_same_canonical_url(): void
    {
        foreach ([
            'instagram.com/acmegym',
            'www.instagram.com/acmegym',
            'https://instagram.com/acmegym',
            'https://www.instagram.com/acmegym/',
            'https://www.instagram.com/acmegym/?hl=en',
        ] as $input) {
            $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', $input), $input);
        }
    }

    public function test_tiktok_handles_carry_the_at_sign(): void
    {
        $this->assertSame('https://tiktok.com/@acmegym', SocialHandle::normalize('tiktok', 'acmegym'));
        $this->assertSame('https://tiktok.com/@acmegym', SocialHandle::normalize('tiktok', 'https://www.tiktok.com/@acmegym'));
    }

    public function test_facebook_and_linkedin_preserve_their_full_path(): void
    {
        // /company/x and /in/x are different things on LinkedIn; FB pages can be
        // /pages/Name/123. Collapsing to a first segment would break both.
        $this->assertSame(
            'https://linkedin.com/company/acme-gym',
            SocialHandle::normalize('linkedin', 'https://www.linkedin.com/company/acme-gym/')
        );
        $this->assertSame(
            'https://linkedin.com/in/jane-doe',
            SocialHandle::normalize('linkedin', 'https://linkedin.com/in/jane-doe')
        );
        $this->assertSame(
            'https://facebook.com/pages/Acme-Gym/12345',
            SocialHandle::normalize('facebook', 'https://www.facebook.com/pages/Acme-Gym/12345')
        );
        $this->assertSame(
            'https://facebook.com/profile.php?id=12345',
            SocialHandle::normalize('facebook', 'https://www.facebook.com/profile.php?id=12345')
        );
    }

    public function test_a_bare_linkedin_handle_becomes_a_company_url(): void
    {
        $this->assertSame('https://linkedin.com/company/acme-gym', SocialHandle::normalize('linkedin', 'acme-gym'));
    }

    public function test_cross_platform_input_is_rejected(): void
    {
        $this->assertNull(SocialHandle::normalize('linkedin', 'https://instagram.com/acmegym'));
        $this->assertNull(SocialHandle::normalize('instagram', 'https://tiktok.com/@acmegym'));
    }

    public function test_unrelated_urls_and_empty_input_are_rejected(): void
    {
        $this->assertNull(SocialHandle::normalize('instagram', 'https://acmegym.ae/about'));
        $this->assertNull(SocialHandle::normalize('instagram', ''));
        $this->assertNull(SocialHandle::normalize('instagram', '   '));
        $this->assertNull(SocialHandle::normalize('instagram', 'https://instagram.com/'));
        $this->assertNull(SocialHandle::normalize('nonsense', 'acmegym'));
    }

    public function test_email_is_validated_and_lowercased(): void
    {
        $this->assertSame('owner@acmegym.ae', SocialHandle::normalize('email', 'Owner@AcmeGym.ae'));
        $this->assertNull(SocialHandle::normalize('email', 'not-an-email'));
        $this->assertNull(SocialHandle::normalize('email', 'owner@'));
    }

    public function test_detect_platform_identifies_known_hosts_only(): void
    {
        $this->assertSame('facebook', SocialHandle::detectPlatform('https://www.facebook.com/acmegym'));
        $this->assertSame('instagram', SocialHandle::detectPlatform('instagram.com/acmegym'));
        $this->assertNull(SocialHandle::detectPlatform('https://acmegym.ae'));
        $this->assertNull(SocialHandle::detectPlatform('acmegym'));
    }
}
