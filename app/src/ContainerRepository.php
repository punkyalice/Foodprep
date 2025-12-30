<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class ContainerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listContainers(string $active = '1'): array
    {
        $conditions = [];
        $params = [];

        if ($active === '1') {
            $conditions[] = 'c.is_active = 1';
        } elseif ($active === '0') {
            $conditions[] = 'c.is_active = 0';
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $sql = "SELECT c.id, c.container_code, c.container_type_id, c.is_active, c.in_use, c.note,
                       ct.shape, ct.volume_ml, ct.material
                FROM containers c
                LEFT JOIN container_types ct ON ct.id = c.container_type_id
                {$where}
                ORDER BY c.container_code ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $code = trim((string)($data['container_code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('invalid_code');
        }

        $typeId = $this->optionalInt($data['container_type_id'] ?? null);
        $note = $this->optionalString($data['note'] ?? null);
        $isActive = $this->normalizeBool($data['is_active'] ?? 1);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO containers (container_code, container_type_id, is_active, note) VALUES (:code, :type, :active, :note)');
            $stmt->execute([
                'code' => $code,
                'type' => $typeId,
                'active' => $isActive,
                'note' => $note,
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('duplicate_container_code', 0, $e);
            }
            throw new RuntimeException('create_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('container_type_id', $data)) {
            $fields[] = 'container_type_id = :type';
            $params['type'] = $this->optionalInt($data['container_type_id']);
        }
        if (array_key_exists('note', $data)) {
            $fields[] = 'note = :note';
            $params['note'] = $this->optionalString($data['note']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :active';
            $params['active'] = $this->normalizeBool($data['is_active']);
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE containers SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFreeContainers(): array
    {
        $sql = "SELECT c.id, c.container_code, c.container_type_id, c.is_active, c.in_use, c.note,
                       ct.shape, ct.volume_ml, ct.material
                FROM containers c
                LEFT JOIN container_types ct ON ct.id = c.container_type_id
                WHERE c.is_active = 1 AND c.in_use = 0
                ORDER BY c.container_code ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $items[] = [
            'id' => 'FREEZER_BAG',
            'container_code' => 'Gefrierbeutel',
            'container_type_id' => null,
            'is_active' => 1,
            'in_use' => 0,
            'note' => 'Einweg',
            'shape' => null,
            'volume_ml' => null,
            'material' => null,
        ];
        $items[] = [
            'id' => 'VACUUM_BAG',
            'container_code' => 'Vakuumierbeutel',
            'container_type_id' => null,
            'is_active' => 1,
            'in_use' => 0,
            'note' => 'Einweg',
            'shape' => null,
            'volume_ml' => null,
            'material' => null,
        ];

        return $items;
    }

    /**
     * @param array<int> $containerIds
     */
    public function lockContainers(array $containerIds): void
    {
        if (empty($containerIds)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $containerIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM containers WHERE id IN ({$placeholders}) FOR UPDATE");
        $stmt->execute($ids);
    }

    /**
     * @param array<int> $containerIds
     */
    public function setInUse(array $containerIds, bool $inUse): void
    {
        if (empty($containerIds)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $containerIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE containers SET in_use = ? WHERE id IN ({$placeholders})");
        $params = array_merge([$inUse ? 1 : 0], $ids);
        $stmt->execute($params);
    }

    private function optionalInt(null|int|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private function optionalString(null|string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeBool(mixed $value): int
    {
        return empty($value) ? 0 : 1;
    }
}
