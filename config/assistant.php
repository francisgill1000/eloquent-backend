<?php
/*
 | Master switch for the owner assistant's data-changing tools. When false,
 | the AssistantToolRegistry omits every MutatingTool module from defs() and
 | routing, so the assistant instantly reverts to read-only — no redeploy.
 */
return [
    'mutations_enabled' => (bool) env('ASSISTANT_MUTATIONS_ENABLED', true),
];
