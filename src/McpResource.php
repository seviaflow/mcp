<?php

declare(strict_types=1);

namespace SeviaFlow\Mcp;

use InvalidArgumentException;

final class McpResource
{
    public bool $isEnabled = false;
    public array $allowedOperations = [];
    public array $readFields = [];
    public array $writeFields = [];

    public function enabled(bool $enabled = true): self
    {
        $this->isEnabled = $enabled;
        return $this;
    }

    public function operations(array $operations): self
    {
        if (array_diff($operations, ['list', 'get', 'create', 'update']) !== []) {
            throw new InvalidArgumentException('Unsupported MCP operation.');
        }
        $this->allowedOperations = array_values(array_unique($operations));
        return $this;
    }

    public function readable(array $fields): self
    {
        $this->readFields = $this->names($fields);
        return $this;
    }

    public function writable(array $fields): self
    {
        $this->writeFields = $this->names($fields);
        return $this;
    }

    private function names(array $fields): array
    {
        foreach ($fields as $field) {
            if (! is_string($field) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field)) {
                throw new InvalidArgumentException('MCP fields must be direct attribute names.');
            }
        }
        return array_values(array_unique($fields));
    }
}
