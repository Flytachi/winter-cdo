<?php

namespace Flytachi\Winter\Cdo;

class CDOBind
{
    private string $name;
    private mixed $value;

    public function __construct(
        string $name,
        mixed $value
    ) {
        $this->name = str_starts_with($name, ':') ? $name : ':' . $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
