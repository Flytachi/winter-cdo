<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use PDO;
use PDOStatement;

class CDOStatement
{
    private PDOStatement $stmt;
    private array $bindings = [];

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
    {
        $this->bindings[] = [$parameter, $value, $data_type];
        return $this->stmt->bindValue($parameter, $value, $data_type);
    }

    public function bindTypedValue(string $parameter, mixed $value): bool
    {
        return match (gettype($value)) {
            'NULL' => $this->bindValue($parameter, $value, PDO::PARAM_NULL),
            'boolean' => $this->bindValue($parameter, $value, PDO::PARAM_BOOL),
            'integer' => $this->bindValue($parameter, $value, PDO::PARAM_INT),
            'array' => $this->bindValue($parameter, json_encode($value)),
            'object' => $this->bindValue($parameter, $this->valObject($value)),
            default => $this->bindValue($parameter, $value),
        };
    }

    public function valObject(object $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        } elseif ($value instanceof \Stringable) {
            return (string) $value;
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof \BackedEnum) {
            return $value->value;
        } else {
            return serialize($value);
        }
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function updateStm(PDOStatement $stmt): void
    {
        $this->stmt = $stmt;
        foreach ($this->bindings as $binding) {
            $this->stmt->bindValue(...$binding);
        }
    }

    public function getStmt(): PDOStatement
    {
        return $this->stmt;
    }
}
