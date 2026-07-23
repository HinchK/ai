<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ToolResult implements Arrayable, JsonSerializable
{
    public bool $successful;

    public ?string $error;

    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public mixed $result,
        public ?string $resultId = null,
        public bool $denied = false,
        ?bool $successful = null,
        ?string $error = null,
    ) {
        $this->successful = $successful ?? ! $denied;
        $this->error = $error ?? (! $this->successful && is_string($result) ? $result : null);
    }

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            arguments: $data['arguments'],
            result: $data['result'],
            resultId: $data['result_id'] ?? null,
            denied: $data['denied'] ?? false,
            successful: $data['successful'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    /**
     * Get the instance as an array, omitting default status values.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_id' => $this->resultId,
            ...($this->denied ? ['denied' => true] : []),
            ...(! $this->successful ? ['successful' => false] : []),
            ...($this->error !== null ? ['error' => $this->error] : []),
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
