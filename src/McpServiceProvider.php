<?php

declare(strict_types=1);

namespace SeviaFlow\Mcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/seviaflow-mcp.php', 'seviaflow-mcp');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/seviaflow-mcp.php' => config_path('seviaflow-mcp.php')], 'seviaflow-mcp-config');
        if (config('seviaflow-mcp.enabled', false)) {
            Mcp::web(config('seviaflow-mcp.path'), ResourceServer::class)
                ->middleware(config('seviaflow-mcp.middleware'));
        }
    }
}
