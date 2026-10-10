<?php

use App\Mcp\NuggetServer;
use Laravel\Mcp\Facades\Mcp;

/*
| The MCP server, for an assistant such as Claude. It takes the same personal
| access token as the API (made on the profile page, with the `read`
| ability), and its tools read only that token's user's data.
*/

Mcp::web('/mcp', NuggetServer::class)->middleware(['auth:sanctum', 'ability:read', 'active', 'throttle:api'])->name('mcp');
