<?php
namespace Tests\Unit;

use App\Support\Phone\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /**
     * The BK00037 regression: a customer gave a valid 10-digit 05 number five
     * times and the LLM kept mis-counting it as 9 digits. Validation is code's
     * job now — this exact string must be accepted.
     */
    public function test_accepts_a_plain_valid_uae_mobile(): void
    {
        $r = PhoneNormalizer::uaeMobile('0529284464');
        $this->assertSame('0529284464', $r);
    }

    public function test_strips_spaces_and_dashes(): void
    {
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('052-928 4464'));
    }

    public function test_expands_spoken_double(): void
    {
        // "…two eight double four six four" spoken → the 44 must survive.
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('05292 8 double four 64'));
    }

    public function test_expands_spoken_triple(): void
    {
        $this->assertSame('0555512345', PhoneNormalizer::uaeMobile('05 triple five 12345'));
    }

    public function test_expands_spoken_digit_words_and_oh(): void
    {
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('oh five two nine two eight four four six four'));
    }

    public function test_folds_plus_971_country_code(): void
    {
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('+971529284464'));
    }

    public function test_folds_00971_country_code(): void
    {
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('00971529284464'));
    }

    public function test_folds_bare_971_country_code(): void
    {
        $this->assertSame('0529284464', PhoneNormalizer::uaeMobile('971529284464'));
    }

    public function test_rejects_nine_digit_number(): void
    {
        $this->assertNull(PhoneNormalizer::uaeMobile('052928446'));
    }

    public function test_rejects_landline_not_starting_05(): void
    {
        $this->assertNull(PhoneNormalizer::uaeMobile('042928446'));
    }

    public function test_rejects_garbage(): void
    {
        $this->assertNull(PhoneNormalizer::uaeMobile('pagal'));
    }
}
