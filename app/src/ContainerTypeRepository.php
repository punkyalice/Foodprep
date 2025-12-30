<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class ContainerTypeRepository
{
    private const SHAPES = ['RECT', 'ROUND', 'OVAL'];
    private const MATERIALS = ['PLASTIC', 'GLASS'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTypes(): array
    {
        $stmt = $this->pdo->query('SELECT id, shape, volume_ml, height_mm, width_mm, length_mm, material, note FROM container_types ORDER BY volume_ml ASC, id ASC');
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $shape = strtoupper(trim((string)($data['shape'] ?? '')));
        $volume = (int)($data['volume_ml'] ?? 0);
        $height = $this->optionalPositiveInt($data['height_mm'] ?? null);
        $width = $this->optionalPositiveInt($data['width_mm'] ?? null);
        $length = $this->optionalPositiveInt($data['length_mm'] ?? null);
        $material = $this->normalizeMaterial($data['material'] ?? null);
        $note = $this->optionalString($data['note'] ?? null);

        if (!in_array($shape, self::SHAPES, true)) {
            throw new RuntimeException('invalid_shape');
        }
        if ($volume <= 0) {
            throw new RuntimeException('invalid_volume');
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO container_types (shape, volume_ml, height_mm, width_mm, length_mm, material, note) VALUES (:shape, :volume, :height, :width, :length, :material, :note)');
            $stmt->execute([
                'shape' => $shape,
                'volume' => $volume,
                'height' => $height,
                'width' => $width,
                'length' => $length,
                'material' => $material,
                'note' => $note,
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('duplicate_container_type', 0, $e);
            }
            throw new RuntimeException('create_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function optionalPositiveInt(null|int|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $intVal = (int)$value;
        if ($intVal <= 0) {
            throw new RuntimeException('invalid_dimension');
        }
        return $intVal;
    }

    private function optionalString(null|string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeMaterial(null|string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $material = strtoupper(trim($value));
        if (!in_array($material, self::MATERIALS, true)) {
            throw new RuntimeException('invalid_material');
        }
        return $material;
    }
}
