<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Orchestra\Testbench\TestCase;
use SeviaFlow\Core\Resources\Fields\TextField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Mcp\McpResource;
use SeviaFlow\Mcp\ResourceTool;

final class ExampleRecord extends Model
{
    public $timestamps = false;
    protected $table = 'examples';
    protected $guarded = [];
}

final class ExampleResource extends Resource
{
    protected static string $model = ExampleRecord::class;

    public static function mcp(McpResource $mcp): McpResource
    {
        return $mcp->enabled()->operations(['list', 'get', 'create', 'update'])
            ->readable(['name', 'password'])->writable(['name']);
    }

    public static function form(Form $form): Form
    {
        return $form->fields([TextField::make('name')->required(), TextField::make('password')]);
    }

    public static function query(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('tenant_id', 1);
    }
}

final class ExamplePolicy
{
    public function viewAny($user): bool { return true; }
    public function view($user, $record): bool { return $record->name !== 'denied'; }
    public function create($user): bool { return false; }
    public function update($user, $record): bool { return false; }
}

final class ResourceToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\SeviaFlow\Core\SeviaFlowCoreServiceProvider::class, \SeviaFlow\Mcp\McpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('seviaflow.resources', ['examples' => ExampleResource::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['seviaflow-mcp.enabled' => true]);
        $this->app['db']->connection()->getSchemaBuilder()->create('examples', function ($table) {
            $table->id(); $table->string('name'); $table->string('password'); $table->integer('tenant_id');
        });
        ExampleRecord::insert([
            ['id' => 1, 'name' => 'visible', 'password' => 'secret', 'tenant_id' => 1],
            ['id' => 2, 'name' => 'other tenant', 'password' => 'secret', 'tenant_id' => 2],
            ['id' => 3, 'name' => 'denied', 'password' => 'secret', 'tenant_id' => 1],
        ]);
        Auth::setUser(new User(['id' => 1]));
        Gate::policy(ExampleRecord::class, ExamplePolicy::class);
    }

    public function test_discovery_requires_authentication_and_explicit_enablement(): void
    {
        $tool = new ResourceTool('examples', ExampleResource::class, 'list');
        $this->assertTrue($tool->shouldRegister());
        config(['seviaflow-mcp.enabled' => false]);
        $this->assertFalse($tool->shouldRegister());
        config(['seviaflow-mcp.enabled' => true]);
        Auth::forgetGuards();
        $this->assertFalse($tool->shouldRegister());
    }

    public function test_list_preserves_scope_row_policy_and_sensitive_field_filter(): void
    {
        $result = (new ResourceTool('examples', ExampleResource::class, 'list'))->handle(new Request);
        $json = json_encode($result->content()->toArray());
        $this->assertStringContainsString('visible', $json);
        $this->assertStringNotContainsString('other tenant', $json);
        $this->assertStringNotContainsString('denied', $json);
        $this->assertStringNotContainsString('secret', $json);
    }

    public function test_direct_call_cannot_bypass_create_or_record_policy(): void
    {
        $this->assertFalse((new ResourceTool('examples', ExampleResource::class, 'create'))->shouldRegister());
        (new ResourceTool('examples', ExampleResource::class, 'update'))->handle(new Request(['id' => '1', 'revision' => 'bad', 'values' => ['name' => 'changed']]));
        $this->assertSame('visible', ExampleRecord::find(1)->name);
    }

    public function test_unsupported_operations_and_nested_fields_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new McpResource)->operations(['sql']);
    }
}
