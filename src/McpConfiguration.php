<?php

declare(strict_types=1);

namespace SeviaFlow\Mcp;

use SeviaFlow\Core\Configuration\ConfigurationRegistry;
use SeviaFlow\Core\Configuration\SettingDefinition;
use SeviaFlow\Core\Configuration\SettingType;

final class McpConfiguration
{
    public static function register(ConfigurationRegistry $registry): void
    {
        $registry->registerApplication('mcp', [
            new SettingDefinition('enabled', SettingType::Boolean, false, group: 'server', readAbility: 'seviaflow.settings.view', writeAbility: 'seviaflow.settings.update', configKey: 'seviaflow-mcp.enabled'),
            new SettingDefinition('path', SettingType::String, '/mcp/seviaflow', group: 'server', rules: ['regex:#^/[^\\s]*$#'], readAbility: 'seviaflow.settings.view', configKey: 'seviaflow-mcp.path'),
            new SettingDefinition('middleware', SettingType::List, ['auth:sanctum', 'throttle:60,1'], group: 'security', readAbility: 'seviaflow.settings.view', configKey: 'seviaflow-mcp.middleware', itemRules: ['string', 'min:1']),
        ]);
        $registry->registerResource('mcp', [
            new SettingDefinition('enabled', SettingType::Boolean, false, group: 'access', readAbility: 'seviaflow.resource-settings.view', writeAbility: 'seviaflow.resource-settings.update'),
            new SettingDefinition('operations', SettingType::List, [], itemRules: ['string', 'in:list,get,create,update'], group: 'access', readAbility: 'seviaflow.resource-settings.view', writeAbility: 'seviaflow.resource-settings.update'),
            new SettingDefinition('readable', SettingType::List, [], itemRules: ['string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D'], group: 'fields', readAbility: 'seviaflow.resource-settings.view', writeAbility: 'seviaflow.resource-settings.update'),
            new SettingDefinition('writable', SettingType::List, [], itemRules: ['string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D'], group: 'fields', readAbility: 'seviaflow.resource-settings.view', writeAbility: 'seviaflow.resource-settings.update'),
        ], static function (string $resource): array {
            $definition = method_exists($resource, 'mcp') ? $resource::mcp(new McpResource) : new McpResource;

            return [
                'enabled' => $definition->isEnabled,
                'operations' => $definition->allowedOperations,
                'readable' => $definition->readFields,
                'writable' => $definition->writeFields,
            ];
        });
    }

    public static function resource(string $resource): McpResource
    {
        $values = app(ConfigurationRegistry::class)->runtime('mcp', $resource);

        return (new McpResource)->enabled($values['enabled'])->operations($values['operations'])
            ->readable($values['readable'])->writable($values['writable']);
    }
}
