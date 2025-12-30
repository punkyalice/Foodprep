<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class InventoryRepository
{
    private const STORAGE_TYPES = ['BOX', 'FREE', 'FREEZER_BAG', 'VACUUM_BAG'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listItems(string $view, array $filters, int $limit = 20, int $offset = 0): array
    {
        $conditions = ['ii.status = :status'];
        $params = ['status' => 'IN_FREEZER'];

        $mealTypes = ['MEAL', 'M'];
        $ingredientTypes = ['INGREDIENT', 'Z'];

        if ($view === 'meals') {
            $conditions[] = $this->buildInCondition('ii.item_type', $mealTypes, $params, 'meal');
        } elseif ($view === 'ingredient') {
            $conditions[] = $this->buildInCondition('ii.item_type', $ingredientTypes, $params, 'ing');
        } else {
            $conditions[] = $this->buildNotInCondition('ii.item_type', array_merge($mealTypes, $ingredientTypes), $params, 'nt');
        }

        if (!empty($filters['q'])) {
            $conditions[] = 'ii.name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['veggie'])) {
            $conditions[] = 'ii.is_veggie = 1';
        }

        $computedDateExpr = 'COALESCE(ii.best_before_at, DATE_ADD(ii.frozen_at, INTERVAL itd.best_before_days DAY))';
        if (!empty($filters['expiring'])) {
            $conditions[] = $computedDateExpr . ' <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT ii.id, ii.id_code, ii.name, ii.item_type, ii.is_veggie, ii.is_vegan, ii.frozen_at, ii.best_before_at,
                ii.storage_type, itd.best_before_days, c.container_code, {$computedDateExpr} AS computed_best_before
            FROM inventory_items ii
            JOIN item_type_defaults itd ON itd.item_type = ii.item_type
            LEFT JOIN containers c ON c.id = ii.container_id
            WHERE {$where}
            ORDER BY ii.frozen_at ASC, ii.id ASC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array<int, string> $values
     */
    private function buildInCondition(string $column, array $values, array &$params, string $prefix): string
    {
        $placeholders = [];
        foreach ($values as $idx => $value) {
            $key = $prefix . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return $column . ' IN (' . implode(',', $placeholders) . ')';
    }

    /**
     * @param array<int, string> $values
     */
    private function buildNotInCondition(string $column, array $values, array &$params, string $prefix): string
    {
        $placeholders = [];
        foreach ($values as $idx => $value) {
            $key = $prefix . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return $column . ' NOT IN (' . implode(',', $placeholders) . ')';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createItem(array $data): array
    {
        $required = ['item_type', 'name', 'frozen_at'];
        foreach ($required as $key) {
            if (empty($data[$key])) {
                throw new RuntimeException("missing_field: {$key}");
            }
        }

        $itemType = (string)$data['item_type'];
        $storageType = strtoupper((string)($data['storage_type'] ?? 'BOX'));
        if (!in_array($storageType, self::STORAGE_TYPES, true)) {
            throw new RuntimeException('invalid_storage_type');
        }

        $containerId = $this->optionalInt($data['container_id'] ?? null);
        if ($storageType !== 'BOX') {
            $containerId = null;
        }
        $ownTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTx = true;
        }
        try {
            if (empty($data['id_code'])) {
                $data['id_code'] = $this->generateIdCode($itemType);
            }

            $defaults = $this->fetchTypeDefaults($itemType);

            $stmt = $this->pdo->prepare("INSERT INTO inventory_items (
                id_code, item_type, name, recipe_id, is_veggie, is_vegan, portion_text, weight_g, volume_ml, kcal,
                frozen_at, best_before_at, storage_location, prep_notes, thaw_method, reheat_minutes, status, storage_type, container_id
            ) VALUES (
                :id_code, :item_type, :name, :recipe_id, :is_veggie, :is_vegan, :portion_text, :weight_g, :volume_ml, :kcal,
                :frozen_at, :best_before_at, :storage_location, :prep_notes, :thaw_method, :reheat_minutes, 'IN_FREEZER', :storage_type, :container_id
            )");

            $stmt->execute([
                'id_code' => $data['id_code'],
                'item_type' => $itemType,
                'name' => $data['name'],
                'recipe_id' => $data['recipe_id'] ?? null,
                'is_veggie' => empty($data['is_veggie']) ? 0 : 1,
                'is_vegan' => empty($data['is_vegan']) ? 0 : 1,
                'portion_text' => $data['portion_text'] ?? null,
                'weight_g' => $data['weight_g'] ?? null,
                'volume_ml' => $data['volume_ml'] ?? null,
                'kcal' => $data['kcal'] ?? null,
                'frozen_at' => $data['frozen_at'],
                'best_before_at' => $data['best_before_at'] ?? null,
                'storage_location' => $data['storage_location'] ?? null,
                'prep_notes' => $data['prep_notes'] ?? null,
                'thaw_method' => $data['thaw_method'] ?? $defaults['thaw_method'],
                'reheat_minutes' => $data['reheat_minutes'] ?? $defaults['reheat_minutes'],
                'storage_type' => $storageType,
                'container_id' => $containerId,
            ]);

            $itemId = (int)$this->pdo->lastInsertId();

            $evt = $this->pdo->prepare('INSERT INTO inventory_events (inventory_item_id, event_type, to_status) VALUES (:id, "CREATED", "IN_FREEZER")');
            $evt->execute(['id' => $itemId]);

            if ($ownTx) {
                $this->pdo->commit();
            }

            return [
                'id' => $itemId,
                'id_code' => $data['id_code'],
                'name' => $data['name'],
            ];
        } catch (RuntimeException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('create_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTypeDefaults(string $itemType): array
    {
        $stmt = $this->pdo->prepare('SELECT thaw_method, reheat_minutes FROM item_type_defaults WHERE item_type = :t');
        $stmt->execute(['t' => $itemType]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['thaw_method' => 'NONE', 'reheat_minutes' => null];
        }

        return $row;
    }

    /**
     * @return array<int>
     */
    public function takeoutItems(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $ids = array_map('intval', $itemIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $ownTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTx = true;
        }
        try {
            $select = $this->pdo->prepare("SELECT id, status, container_id FROM inventory_items WHERE id IN ({$placeholders}) FOR UPDATE");
            $select->execute($ids);
            $rows = $select->fetchAll();
            if (count($rows) !== count($ids)) {
                throw new RuntimeException('not_found');
            }

            foreach ($rows as $row) {
                if ($row['status'] !== 'IN_FREEZER') {
                    throw new RuntimeException('invalid_status');
                }
            }

            $update = $this->pdo->prepare("UPDATE inventory_items SET status = 'TAKEN_OUT', status_changed_at = NOW() WHERE id IN ({$placeholders})");
            $update->execute($ids);

            $evt = $this->pdo->prepare('INSERT INTO inventory_events (inventory_item_id, event_type, from_status, to_status) VALUES (:id, "STATUS_CHANGED", "IN_FREEZER", "TAKEN_OUT")');
            foreach ($ids as $id) {
                $evt->execute(['id' => $id]);
            }

            $containerIds = array_values(array_unique(array_map(function ($row) {
                return $row['container_id'] !== null ? (int)$row['container_id'] : null;
            }, $rows)));
            $containerIds = array_filter($containerIds, fn($v) => $v !== null);
            if (!empty($containerIds)) {
                $place = implode(',', array_fill(0, count($containerIds), '?'));
                $stmt = $this->pdo->prepare("UPDATE containers SET in_use = 0 WHERE id IN ({$place})");
                $stmt->execute($containerIds);
            }

            if ($ownTx) {
                $this->pdo->commit();
            }
            return $ids;
        } catch (RuntimeException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('takeout_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function optionalInt(null|int|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function generateIdCode(string $itemType): string
    {
        $stmt = $this->pdo->prepare('SELECT next_number FROM id_counters WHERE item_type = :t FOR UPDATE');
        $stmt->execute(['t' => $itemType]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('counter_missing');
        }

        $next = (int)$row['next_number'];
        $update = $this->pdo->prepare('UPDATE id_counters SET next_number = next_number + 1 WHERE item_type = :t');
        $update->execute(['t' => $itemType]);

        return sprintf('%s%03d', $itemType, $next);
    }
}
