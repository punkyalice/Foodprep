<?php
declare(strict_types=1);

namespace App;

use PDO;
use DateTimeImmutable;

final class MealSetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSets(array $filters, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT id, set_code, name FROM meal_sets WHERE is_active = 1';
        $params = [];

        if (!empty($filters['q'])) {
            $sql .= ' AND name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql .= ' ORDER BY name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $summary = $this->buildSetSummary((int)$row['id'], $filters, $row['name'], $row['set_code']);
            if ($summary === null) {
                continue;
            }
            $result[] = $summary;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSet(int $id, array $filters = []): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, set_code, name FROM meal_sets WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->buildSetSummary((int)$row['id'], $filters, $row['name'], $row['set_code'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSetSummary(int $setId, array $filters, string $name, string $code, bool $includeItems = false): ?array
    {
        $requirements = $this->loadRequirements($setId);
        if (empty($requirements)) {
            return [
                'id' => $setId,
                'set_code' => $code,
                'name' => $name,
                'complete_count' => 0,
                'fifo_ids' => [],
                'is_veggie' => false,
                'is_vegan' => false,
                'is_expiring' => false,
                'items' => $includeItems ? [] : null,
            ];
        }

        $completeCount = PHP_INT_MAX;
        $selectedItems = [];
        $hasExpiring = false;
        $allVeggie = true;
        $allVegan = true;
        $daysThreshold = $this->expiringThreshold();

        foreach ($requirements as $req) {
            $needsVeggie = $req['require_veggie'] || (!empty($filters['veggie']));
            $available = $this->loadAvailableItems($setId, $req['required_type'], $needsVeggie);
            $availCount = count($available);
            $requiredCount = max(1, (int)$req['required_count']);
            $completeCount = min($completeCount, intdiv($availCount, $requiredCount));

            if ($completeCount === 0) {
                break;
            }

            $takeCount = $requiredCount * $completeCount;
            $chosen = array_slice($available, 0, $takeCount);
            $selectedItems = array_merge($selectedItems, $chosen);
        }

        if ($completeCount === PHP_INT_MAX) {
            $completeCount = 0;
        }

        if ($completeCount < 1) {
            return null;
        }

        foreach ($selectedItems as $item) {
            $allVeggie = $allVeggie && (bool)$item['is_veggie'];
            $allVegan = $allVegan && (bool)$item['is_vegan'];
            $isExpiring = $this->isExpiringSoon($item['computed_best_before'], $daysThreshold);
            $hasExpiring = $hasExpiring || $isExpiring;
        }

        if (!empty($filters['expiring']) && !$hasExpiring) {
            return null;
        }

        if (!empty($filters['veggie']) && !$allVeggie) {
            return null;
        }

        $payload = [
            'id' => $setId,
            'set_code' => $code,
            'name' => $name,
            'complete_count' => $completeCount,
            'fifo_ids' => array_values(array_map(fn($i) => $i['id_code'], $selectedItems)),
            'is_veggie' => $allVeggie,
            'is_vegan' => $allVegan,
            'is_expiring' => $hasExpiring,
        ];

        if ($includeItems) {
            $payload['items'] = $selectedItems;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRequirements(int $setId): array
    {
        $stmt = $this->pdo->prepare('SELECT required_type, required_count, require_veggie FROM meal_set_requirements WHERE meal_set_id = :id');
        $stmt->execute(['id' => $setId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadAvailableItems(int $setId, string $type, bool $requireVeggie): array
    {
        $sql = "SELECT ii.id, ii.id_code, ii.name, ii.item_type, ii.is_veggie, ii.is_vegan, ii.frozen_at, ii.best_before_at,
                itd.best_before_days, c.container_code,
                COALESCE(ii.best_before_at, DATE_ADD(ii.frozen_at, INTERVAL itd.best_before_days DAY)) AS computed_best_before
            FROM meal_set_items msi
            JOIN inventory_items ii ON ii.id = msi.inventory_item_id
            JOIN item_type_defaults itd ON itd.item_type = ii.item_type
            LEFT JOIN containers c ON c.id = ii.container_id
            WHERE msi.meal_set_id = :setId AND msi.role_type = :type AND ii.status = 'IN_FREEZER'";

        if ($requireVeggie) {
            $sql .= ' AND ii.is_veggie = 1';
        }

        $sql .= ' ORDER BY ii.frozen_at ASC, ii.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'setId' => $setId,
            'type' => $type,
        ]);

        return $stmt->fetchAll();
    }

    private function expiringThreshold(): DateTimeImmutable
    {
        return new DateTimeImmutable('+7 days');
    }

    private function isExpiringSoon(string $bestBefore, DateTimeImmutable $threshold): bool
    {
        $bb = DateTimeImmutable::createFromFormat('Y-m-d', substr($bestBefore, 0, 10));
        if ($bb === false) {
            return false;
        }

        return $bb <= $threshold;
    }

    /**
     * @return array<int>
     */
    public function chooseFifoItemsForSingleSet(int $setId): array
    {
        $requirements = $this->loadRequirements($setId);
        if (empty($requirements)) {
            return [];
        }

        $selectedIds = [];
        foreach ($requirements as $req) {
            $available = $this->loadAvailableItems($setId, $req['required_type'], (bool)$req['require_veggie']);
            if (count($available) < (int)$req['required_count']) {
                return [];
            }

            $slice = array_slice($available, 0, (int)$req['required_count']);
            foreach ($slice as $item) {
                $selectedIds[] = (int)$item['id'];
            }
        }

        return $selectedIds;
    }
}
