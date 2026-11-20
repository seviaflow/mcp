<?php

declare(strict_types=1);

namespace SeviaFlow\Mcp;

use Illuminate\Support\ServiceProvider;
use SeviaFlow\Core\Configuration\ConfigurationRegistry;
use Laravel\Mcp\Facades\Mcp;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/seviaflow-mcp.php', 'seviaflow-mcp');
    }

    public function boot(ConfigurationRegistry $configuration): void
    {
        McpConfiguration::register($configuration);
        $settings = $configuration->runtime('mcp');
        $this->publishes([__DIR__.'/../config/seviaflow-mcp.php' => config_path('seviaflow-mcp.php')], 'seviaflow-mcp-config');
        if ($settings['enabled']) {
            Mcp::web($settings['path'], ResourceServer::class)
                ->middleware($settings['middleware']);
        }
    }
}
