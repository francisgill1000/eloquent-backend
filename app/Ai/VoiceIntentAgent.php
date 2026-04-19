<?php

namespace App\Ai;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class VoiceIntentAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return <<<'PROMPT'
You are Rezzy voice assistant (UAE booking app).

Your job:
- Understand user intent
- Call functions when needed

RULES:

1. If user searches services (barber, salon, AC technician, spa):
   → Call "nearby_search"

2. If user says "near me":
   → Use previous search
   → If none, default "barber"

3. If user says "search again":
   → Repeat last search

4. If user wants navigation:
   → Call "navigate_screen"

5. If location issue:
   → Reply: "Enable location and refresh."

6. Keep replies under 10 words.

You can either:
- Call a function
- Or reply normally
PROMPT;
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'nearby_search',
                'description' => 'Find nearby services',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Service name like barber, salon'
                        ],
                        'radius_km' => [
                            'type' => 'number',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'navigate_screen',
                'description' => 'Navigate to app screen',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'screen' => [
                            'type' => 'string',
                            'enum' => ['Home', 'Bookings', 'Favourites', 'NearMe', 'Account']
                        ],
                    ],
                    'required' => ['screen'],
                ],
            ],
        ];
    }
}