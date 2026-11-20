<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use SeviaFlow\Core\Configuration\ConfigurationRegistry;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Mcp\McpResource;

final class ConfigurationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\SeviaFlow\Core\SeviaFlowCoreServiceProvider::class, \SeviaFlow\Mcp\McpServiceProvider::class];
    }

    public function test_mcp_registers_both_scopes_and_uses_resource_defaults(): void
    {
        $registry = app(ConfigurationRegistry::class);
        $this->assertContains('mcp', $registry->modules());
        $this->assertContains('mcp', $registry->modules('resource'));
        $resource = new class extends Resource {};
        $this->assertFalse($registry->runtime('mcp', $resource::class)['enabled']);
        $this->assertSame([], $registry->describe('mcp', new User));
    }

    public function test_existing_resource_method_is_consumed_through_registry(): void
    {
        $resource = new class extends Resource {
            public static function mcp(McpResource $mcp): McpResource
            {
                return $mcp->enabled()->operations(['get'])->readable(['name']);
            }
        };
        $registry = app(ConfigurationRegistry::class);
        $values = $registry->runtime('mcp', $resource::class);
        $this->assertSame(['get'], $values['operations']);
        $this->assertSame(['name'], $values['readable']);
        $this->assertTrue(\SeviaFlow\Mcp\ResourceTool::definition($resource::class)->isEnabled);
        Gate::define('seviaflow.resource-settings.view', fn ($user, $class, $module): bool => $class === $resource::class && $module === 'mcp');
        $schema = $registry->describe('mcp', new User, $resource::class);
        $this->assertSame('fields', $schema['readable']['group']);
        $this->assertFalse($schema['enabled']['writable']);
    }
}
