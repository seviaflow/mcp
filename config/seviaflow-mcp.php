<?php

return [
    'enabled' => false,
    'path' => '/mcp/seviaflow',
    // Add tenant resolution middleware here, before tools execute.
    'middleware' => ['auth:sanctum', 'throttle:60,1'],
];
