<?php
function handle_web_route(array $segments, string $method): void
{
    jsonResponse(false, 'Invalid API path', null, 404);
}
