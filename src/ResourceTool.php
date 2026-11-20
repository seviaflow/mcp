<?php

declare(strict_types=1);

namespace SeviaFlow\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SeviaFlow\Core\Forms\FormFields;
use SeviaFlow\Core\Forms\FormState;
use SeviaFlow\Core\Forms\FormValidationRules;
use SeviaFlow\Core\Operations\ResourceOperationContext;
use SeviaFlow\Core\Operations\ResourceOperationService;
use SeviaFlow\Core\Operations\ResourceRevision;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\ResourceAuthorization;
use SeviaFlow\Core\Resources\ResourceContext;
use SeviaFlow\Core\Resources\ResourceScopedQuery;
use SeviaFlow\Core\Support\SensitiveDataPolicy;
use Throwable;

final class ResourceTool extends Tool
{
    public function __construct(private string $slug, private string $resource, private string $operation) {}

    public static function definition(string $resource): McpResource
    {
        return McpConfiguration::resource($resource);
    }

    public function name(): string
    {
        return 'sf_'.bin2hex($this->slug).'_'.$this->operation;
    }

    public function shouldRegister(): bool
    {
        $definition = self::definition($this->resource);
        $user = Auth::user();
        if (! app(\SeviaFlow\Core\Configuration\ConfigurationRegistry::class)->runtime('mcp')['enabled'] || ! $user instanceof Authenticatable
            || ! $definition->isEnabled || ! in_array($this->operation, $definition->allowedOperations, true)) {
            return false;
        }
        $authorization = ResourceAuthorization::for($this->resource);
        return $this->operation === 'create'
            ? $authorization->canCreate($user)
            : $authorization->canViewAny($user);
    }

    public function toArray(): array
    {
        $properties = [];
        $required = [];
        if (in_array($this->operation, ['get', 'update'], true)) {
            $properties['id'] = ['type' => 'string'];
            $required[] = 'id';
        }
        if ($this->operation === 'list') {
            $properties['page'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000];
        }
        if (in_array($this->operation, ['create', 'update'], true)) {
            $fields = [];
            foreach ($this->resource::form(new Form)->getAllFields() as $field) {
                if (in_array($field->name(), self::definition($this->resource)->writeFields, true)) {
                    $fields[$field->name()] = $field->getSchema();
                }
            }
            $properties['values'] = ['type' => 'object', 'properties' => (object) $fields, 'additionalProperties' => false];
            $required[] = 'values';
        }
        if ($this->operation === 'update') {
            $properties['revision'] = ['type' => 'string'];
            $required[] = 'revision';
        }
        return [
            'name' => $this->name(),
            'description' => $this->operation.' '.$this->slug.'. Only explicitly exposed fields are available.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => in_array($this->operation, ['get', 'list'], true), 'destructiveHint' => $this->operation === 'update'],
        ];
    }

    public function handle(Request $request): Response
    {
        if (! $this->shouldRegister()) {
            return Response::error('Operation unavailable.');
        }
        try {
            return $this->execute($request);
        } catch (Throwable $exception) {
            report($exception);
            return Response::error('Operation failed. Check permissions, values and record revision.');
        }
    }

    private function execute(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 403);
        $class = $this->resource;
        $definition = self::definition($class);
        $authorization = ResourceAuthorization::for($class);
        if ($this->operation === 'list') {
            $request->validate(['page' => 'sometimes|integer|min:1|max:10000']);
            $page = (int) $request->get('page', 1);
            $query = ResourceScopedQuery::make($class);
            $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->skip(($page - 1) * 25)->take(25)->get();
            $data = [];
            foreach ($records as $record) {
                if ($authorization->canView($user, $record)) {
                    $data[] = $this->serialize($record);
                }
            }
            return Response::text(json_encode(['data' => $data, 'page' => $page, 'next_page' => $records->count() === 25 ? $page + 1 : null], JSON_THROW_ON_ERROR));
        }
        $modelClass = $class::model();
        $record = new $modelClass;
        if ($this->operation !== 'create') {
            $request->validate(['id' => 'required|string|max:255']);
            $record = ResourceScopedQuery::find($class, $request->get('id'));
            abort_unless($record && $authorization->canView($user, $record), 404);
        }
        if ($this->operation === 'get') {
            return Response::text(json_encode($this->serialize($record), JSON_THROW_ON_ERROR));
        }
        $creating = $this->operation === 'create';
        abort_unless($creating ? $authorization->canCreate($user) : $authorization->canUpdate($user, $record), 403);
        $request->validate(['values' => 'required|array', 'revision' => $creating ? 'sometimes|string' : 'required|string']);
        $values = $request->get('values');
        abort_if(array_diff(array_keys($values), $definition->writeFields) !== [], 422);
        $context = $creating ? ResourceContext::Create : ResourceContext::Edit;
        $service = app(ResourceOperationService::class);
        $fields = $class::form(new Form)->getAllFields();
        $values = $service->prepareForValidation($fields, $values);
        $state = new FormState(array_merge($record->getAttributes(), $values), $context, $record);
        $persistable = (new FormFields)->persistable($fields, $context, $state);
        $allowed = [];
        foreach ($persistable as $field) {
            if (in_array($field->name(), $definition->writeFields, true)
                && in_array($field->type(), ['text', 'textarea', 'number', 'email', 'url', 'toggle', 'date', 'datetime'], true)) {
                $allowed[] = $field->name();
            }
        }
        abort_if(array_diff(array_keys($values), $allowed) !== [], 422);
        $rules = (new FormValidationRules)->build($fields, $context, $modelClass, 'values', $values, $record, $state);
        Validator::make(['values' => $values], $rules)->validate();
        $saved = $service->execute(
            resourceClass: $class, resourceSlug: $this->slug, model: $record,
            fields: $fields, values: $values, context: $context,
            operationContext: new ResourceOperationContext(
                actor: $user, source: 'api', request: request(), metadata: ['adapter' => 'mcp'],
                expectedRevision: $creating ? null : $request->get('revision'),
            ), state: $state,
        );
        // A newly created record can be outside the caller's readable scope.
        $visible = ResourceScopedQuery::find($class, (string) $saved->getKey());
        return Response::text(json_encode($visible && $authorization->canView($user, $visible)
            ? $this->serialize($visible) : ['saved' => true], JSON_THROW_ON_ERROR));
    }

    private function serialize($record): array
    {
        $data = [];
        $state = new FormState($record->getAttributes(), ResourceContext::Show, $record);
        foreach ($this->resource::show(new Form)->getAllFields() as $field) {
            $name = $field->name();
            if (in_array($name, self::definition($this->resource)->readFields, true)
                && ! SensitiveDataPolicy::isSensitive($name)
                && ! in_array($name, $record->getHidden(), true)
                && $field->isVisibleForState(ResourceContext::Show, $state)) {
                $value = $record->getAttribute($name);
                if ($value === null || is_scalar($value)) {
                    $data[$name] = $value;
                }
            }
        }
        return ['id' => (string) $record->getKey(), 'values' => (object) $data, 'revision' => ResourceRevision::for($record)];
    }
}
