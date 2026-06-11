<?php

namespace Tests\Unit;

use App\Support\Wa\Greetings;
use PHPUnit\Framework\TestCase;

class WaGreetingsTest extends TestCase
{
    public function test_detects_bare_greetings(): void
    {
        foreach (['hi', 'Hello', 'HEY', 'good morning', 'salam', 'Asalamualaikum', 'gm', 'h'] as $text) {
            $this->assertTrue(Greetings::isBare($text), "expected '{$text}' to be a bare greeting");
        }
    }

    public function test_collapses_repeated_letters(): void
    {
        $this->assertTrue(Greetings::isBare('hiii'));
        $this->assertTrue(Greetings::isBare('hellooooo'));
        $this->assertTrue(Greetings::isBare('heyyy'));
    }

    public function test_strips_emoji_and_punctuation(): void
    {
        $this->assertTrue(Greetings::isBare('Hi! 😊'));
        $this->assertTrue(Greetings::isBare('hello...'));
    }

    public function test_rejects_real_messages(): void
    {
        foreach (['hi, how much is it?', 'hello I need a booking', 'what is rezzy', '', null] as $text) {
            $this->assertFalse(Greetings::isBare($text), 'expected "' . ($text ?? 'null') . '" NOT to be bare');
        }
    }
}
