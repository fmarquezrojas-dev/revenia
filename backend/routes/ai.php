<?php

use App\Mcp\BusinessServer;
use Laravel\Mcp\Facades\Mcp;

if (app()->runningInConsole() && app()->environment('local')) {
    Mcp::local('revenia', BusinessServer::class);
}
