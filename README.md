# SeviaFlow MCP

Expose SeviaFlow resources to MCP clients using Laravel MCP's HTTP transport.
Documentation and project: https://seviaflow.io · https://github.com/seviaflow/mcp

## Installation

Requires PHP 8.2+, Laravel 12.41.1+ or 13, and SeviaFlow Core 0.1 beta.

```sh
composer require seviaflow/mcp
php artisan vendor:publish --tag=seviaflow-mcp-config
```

Until this package is published on Packagist, configure its GitHub VCS repository
in Composer and require `seviaflow/mcp:dev-feat/resource-mcp` for evaluation.

Enable `seviaflow-mcp.enabled` in the published config. The default endpoint is
`/mcp/seviaflow`, protected by `auth:sanctum` and throttling. Install/configure
Sanctum in the host application, or replace the authentication middleware with
your application's authenticated guard. All calls also require an authenticated
user internally. Configure tenant middleware before tool execution. There is no
privileged service identity or anonymous fallback.

## Resource configuration

Add this method directly to an existing registered SeviaFlow Resource. No Core
upgrade or dependency on the REST API adapter is necessary.

```php
use SeviaFlow\Mcp\McpResource;

public static function mcp(McpResource $mcp): McpResource
{
    return $mcp
        ->enabled()
        ->operations(['list', 'get', 'create', 'update'])
        ->readable(['name', 'price'])
        ->writable(['name', 'price']);
}
```

Omit `mcp()`, use `enabled(false)`, or remove an operation to deny exposure.
Empty field lists expose no business attributes. Tool names encode the registered
slug in hexadecimal to avoid collisions. Each resource operation has its own tool.

## Permissions and data

Discovery checks resource permissions. Every call rechecks configuration and
permissions; record reads and updates also check the record policy. All queries
use `ResourceScopedQuery`, including `Resource::query()` and soft-delete rules.
List results filter each row through `canView` and never disclose total counts.
An empty page may still have a next page when rows were filtered by policy.

Read fields must also exist in the show form, be visible for the record's form
state, and not be hidden on the model or classified as sensitive by Core. Only
scalar values are returned. Record ID and revision are transport metadata.
Write fields must be explicitly allowed and persistable in the current form
state. Unknown, readonly, disabled and unsupported fields are rejected. Core
validation, workflow state guards, transactions and lifecycle events apply.
Update requires the `revision` returned by a previous read. Event source is `api`
with `adapter=mcp` metadata for compatibility with the existing Core source enum.

Initial support covers scalar text, textarea, number, email, URL, toggle, date
and datetime fields. Relations, files, arbitrary actions, workflow transitions,
delete/restore, prompts and MCP resource URIs are not exposed in this version.
Do not register this server as a local unauthenticated process.

## Connecting a client

Configure an MCP client supporting authenticated HTTP with the endpoint URL and
a bearer token for the intended application user. OAuth login and consent screens
are host application responsibilities; this package does not provision an OAuth
authorization server. Token abilities should be enforced in host middleware when
using tokens restricted beyond the user's normal permissions.

## Validation

`composer test` runs Testbench tests for default-deny exposure, resource scopes,
row policies, sensitive output and denied writes. CI runs on PHP 8.2–8.4.

## Follow-up tasks

- **Rozszerzyć operacje MCP o relacje i pliki.** Dodać jawne schematy oraz walidację
  powiązań i plików z kontrolą uprawnień do powiązanych rekordów.
- **Dodać akcje i przejścia workflow przez MCP.** Wystawiać tylko wskazane akcje;
  wykorzystać istniejące mechanizmy autoryzacji, transakcji i historii.
- **Dodać profil OAuth dla klientów czatowych.** Przygotować przykład logowania,
  zgody na dostęp i ograniczania zakresów tokenów w aplikacji hosta.

## Core configuration registry

MCP registers application and resource schemas as the `mcp` module in Core's
`ConfigurationRegistry`. Transport settings and resource tools read their effective
configuration through that registry. Existing `mcp(McpResource $mcp)` methods remain
compatible; the resource resolver translates them into the common schema.

Metadata permissions are `seviaflow.settings.view` / `seviaflow.settings.update`
(application) and `seviaflow.resource-settings.view` /
`seviaflow.resource-settings.update` (resource). The host must define those Gates;
metadata and changes deny access by default. These abilities do not replace record
CRUD permissions. Endpoint path and authentication middleware are not editable.

This integration currently requires Core branch `feat/module-configuration` until
a tagged Core release includes the registry. Composer repositories are root-only:
consuming applications must also configure the Core GitHub VCS repository and
explicitly require `seviaflow/core:dev-feat/module-configuration` during evaluation.
Before a release, replace this development dependency with the new Core tag.
