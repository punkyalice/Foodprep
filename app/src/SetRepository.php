<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use DateTimeImmutable;

final class SetRepository
{
    private const BOX_PREFIX = [
        'PROTEIN' => 'P',
        'SIDE' => 'B',
        'SAUCE' => 'S',
        'BASE' => 'Z',
        'BREAKFAST' => 'F',
        'DESSERT' => 'D',
        'MISC' => 'X',
        'MEAL' => 'M',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSets(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT s.id, s.name, s.note, s.updated_at, (SELECT COUNT(*) FROM set_boxes sb WHERE sb.set_id = s.id) AS box_count FROM sets s ORDER BY s.updated_at DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'note' => $row['note'],
                'updated_at' => $row['updated_at'],
                'box_count' => isset($row['box_count']) ? (int)$row['box_count'] : 0,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSet(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('missing_name');
        }

        $components = $this->normalizeComponents($payload['components'] ?? []);
        if (empty($components)) {
            throw new RuntimeException('missing_components');
        }

        $note = $this->optionalText($payload['note'] ?? null);

        $ownTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTx = true;
        }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO sets (name, note) VALUES (:n, :note)');
            $stmt->execute(['n' => $name, 'note' => $note]);
            $setId = (int)$this->pdo->lastInsertId();

            $this->insertComponents($setId, $components);
            if ($ownTx) {
                $this->pdo->commit();
            }

            return $this->getSet($setId) ?? ['id' => $setId];
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
     * @param array<string, mixed> $payload
     */
    public function updateSet(int $id, array $payload): array
    {
        $set = $this->getSet($id);
        if (!$set) {
            throw new RuntimeException('not_found');
        }

        $name = trim((string)($payload['name'] ?? $set['name']));
        if ($name === '') {
            throw new RuntimeException('missing_name');
        }
        $note = $this->optionalText($payload['note'] ?? $set['note']);
        $components = $this->normalizeComponents($payload['components'] ?? $set['components'] ?? []);
        if (empty($components)) {
            throw new RuntimeException('missing_components');
        }

        $ownTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTx = true;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE sets SET name = :n, note = :note, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['n' => $name, 'note' => $note, 'id' => $id]);

            $this->pdo->prepare('DELETE FROM set_components WHERE set_id = :sid')->execute(['sid' => $id]);
            $this->insertComponents($id, $components);

            if ($ownTx) {
                $this->pdo->commit();
            }
            return $this->getSet($id) ?? ['id' => $id];
        } catch (RuntimeException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('update_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSet(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, note, created_at, updated_at FROM sets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $components = $this->loadComponents($id);
        $boxes = $this->loadBoxes($id);

        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'note' => $row['note'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'components' => $components,
            'boxes' => $boxes,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $boxes
     */
    public function addBoxes(int $setId, array $boxes, InventoryRepository $inventoryRepo, ContainerRepository $containerRepo): array
    {
        $set = $this->getSet($setId);
        if (!$set) {
            throw new RuntimeException('not_found');
        }

        $components = $set['components'] ?? [];
        $componentMap = [];
        foreach ($components as $comp) {
            $componentMap[(int)$comp['id']] = $comp;
        }

        if (empty($boxes)) {
            throw new RuntimeException('missing_boxes');
        }

        $normalized = $this->normalizeBoxes($boxes, $componentMap);

        $ownTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTx = true;
        }
        try {
            $realBoxes = array_values(array_filter($normalized, fn(array $box) => $box['is_bag'] === false));
            $containerRepo->lockContainers(array_column($realBoxes, 'container_id'));
            $available = $containerRepo->listFreeContainers();
            $availableIds = [];
            foreach ($available as $row) {
                if (is_numeric($row['id'])) {
                    $availableIds[] = (int)$row['id'];
                }
            }
            foreach ($realBoxes as $box) {
                if (!in_array($box['container_id'], $availableIds, true)) {
                    throw new RuntimeException('container_not_available');
                }
            }

            $created = [];
            foreach ($normalized as $box) {
                $boxCode = $this->generateBoxCode($box['box_type']);
                $kcalTotal = $this->calculateKcal($box['component_ids'], $componentMap, $box['portion_factor']);

                $stmt = $this->pdo->prepare('INSERT INTO set_boxes (set_id, container_id, box_code, box_type, portion_factor, portion_text, kcal_total) VALUES (:sid, :cid, :code, :bt, :pf, :pt, :kc)');
                $stmt->execute([
                    'sid' => $setId,
                    'cid' => $box['container_id'],
                    'code' => $boxCode,
                    'bt' => $box['box_type'],
                    'pf' => $box['portion_factor'],
                    'pt' => $box['portion_text'],
                    'kc' => $kcalTotal,
                ]);
                $boxId = (int)$this->pdo->lastInsertId();

                $rel = $this->pdo->prepare('INSERT INTO set_box_components (set_box_id, set_component_id) VALUES (:bid, :cid)');
                foreach ($box['component_ids'] as $cid) {
                    $rel->execute(['bid' => $boxId, 'cid' => $cid]);
                }

                if ($box['is_bag'] === false) {
                    $containerRepo->setInUse([$box['container_id']], true);
                }

                $inventoryRepo->createItem([
                    'item_type' => $this->prefixForType($box['box_type']),
                    'name' => $set['name'],
                    'frozen_at' => (new DateTimeImmutable())->format('Y-m-d'),
                    'portion_text' => $box['portion_text'],
                    'kcal' => $kcalTotal,
                    'container_id' => $box['container_id'],
                    'storage_type' => $box['storage_type'],
                ]);

                $created[] = [
                    'id' => $boxId,
                    'box_code' => $boxCode,
                    'container_id' => $box['container_id'],
                ];
            }

            if ($ownTx) {
                $this->pdo->commit();
            }
            return $created;
        } catch (RuntimeException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('boxes_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadComponents(int $setId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, component_type, source_type, recipe_id, free_text, amount_text, kcal_total, sort_order FROM set_components WHERE set_id = :id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['id' => $setId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'component_type' => $row['component_type'],
                'source_type' => $row['source_type'],
                'recipe_id' => $row['recipe_id'] ? (int)$row['recipe_id'] : null,
                'free_text' => $row['free_text'],
                'amount_text' => $row['amount_text'],
                'kcal_total' => $row['kcal_total'] !== null ? (int)$row['kcal_total'] : null,
                'sort_order' => (int)$row['sort_order'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadBoxes(int $setId): array
    {
        $stmt = $this->pdo->prepare('SELECT sb.id, sb.box_code, sb.box_type, sb.container_id, sb.portion_factor, sb.portion_text, sb.kcal_total FROM set_boxes sb WHERE sb.set_id = :id ORDER BY sb.id ASC');
        $stmt->execute(['id' => $setId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $components = $this->pdo->prepare('SELECT set_component_id FROM set_box_components WHERE set_box_id = :bid');
            $components->execute(['bid' => $row['id']]);
            $compIds = array_map('intval', array_column($components->fetchAll(), 'set_component_id'));

            $result[] = [
                'id' => (int)$row['id'],
                'box_code' => $row['box_code'],
                'box_type' => $row['box_type'],
                'container_id' => $row['container_id'] !== null ? (int)$row['container_id'] : null,
                'portion_factor' => $row['portion_factor'] !== null ? (float)$row['portion_factor'] : null,
                'portion_text' => $row['portion_text'],
                'kcal_total' => $row['kcal_total'] !== null ? (int)$row['kcal_total'] : null,
                'component_ids' => $compIds,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $components
     */
    private function insertComponents(int $setId, array $components): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO set_components (set_id, component_type, source_type, recipe_id, free_text, amount_text, kcal_total, sort_order) VALUES (:sid, :ct, :st, :rid, :ft, :amt, :kcal, :sort)');
        foreach ($components as $comp) {
            $stmt->execute([
                'sid' => $setId,
                'ct' => $comp['component_type'],
                'st' => $comp['source_type'],
                'rid' => $comp['recipe_id'],
                'ft' => $comp['free_text'],
                'amt' => $comp['amount_text'],
                'kcal' => $comp['kcal_total'],
                'sort' => $comp['sort_order'],
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $input
     * @return array<int, array<string, mixed>>
     */
    private function normalizeComponents(array $input): array
    {
        $result = [];
        $order = 0;
        foreach ($input as $row) {
            $ct = strtoupper(trim((string)($row['component_type'] ?? '')));
            $source = strtoupper(trim((string)($row['source_type'] ?? '')));
            if ($ct === '' || !in_array($source, ['RECIPE', 'FREE'], true)) {
                continue;
            }

            $recipeId = null;
            $freeText = null;
            if ($source === 'RECIPE') {
                $recipeId = $this->optionalInt($row['recipe_id'] ?? null);
                if ($recipeId === null) {
                    continue;
                }
            } else {
                $freeText = $this->optionalText($row['free_text'] ?? null);
                if ($freeText === null) {
                    continue;
                }
            }

            $kcal = $row['kcal_total'] ?? null;
            if ($source === 'FREE' && ($kcal === null || $kcal === '')) {
                continue;
            }

            $result[] = [
                'component_type' => $ct,
                'source_type' => $source,
                'recipe_id' => $recipeId,
                'free_text' => $freeText,
                'amount_text' => $this->optionalText($row['amount_text'] ?? null, 100),
                'kcal_total' => $kcal !== null && $kcal !== '' ? (int)$kcal : null,
                'sort_order' => $order++,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $boxes
     * @param array<int, array<string, mixed>> $componentMap
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBoxes(array $boxes, array $componentMap): array
    {
        $result = [];
        $usedContainers = [];
        foreach ($boxes as $box) {
            $rawContainer = $box['container_id'] ?? null;
            $isBag = $this->isBagContainer($rawContainer);
            $containerId = $isBag ? null : $this->optionalInt($rawContainer);
            $boxType = strtoupper(trim((string)($box['box_type'] ?? '')));
            $portionFactor = $box['portion_factor'] ?? null;
            $portionText = $this->optionalText($box['portion_text'] ?? null, 50);
            $componentIds = $box['component_ids'] ?? [];

            if ((!$isBag && $containerId === null) || $boxType === '' || empty($componentIds)) {
                continue;
            }
            if (!$isBag && isset($usedContainers[$containerId])) {
                throw new RuntimeException('duplicate_container');
            }
            if (!$isBag) {
                $usedContainers[$containerId] = true;
            }

            $componentIds = array_values(array_filter(
                array_unique(array_map('intval', $componentIds)),
                static fn(int $cid): bool => isset($componentMap[$cid])
            ));
            if (empty($componentIds)) {
                throw new RuntimeException('invalid_component');
            }

            if ($portionFactor === '' || $portionFactor === null) {
                $portionFactor = null;
            } else {
                $portionFactor = (float)$portionFactor;
            }

            if ($portionFactor === null && $portionText === null) {
                throw new RuntimeException('portion_missing');
            }

            $result[] = [
                'container_id' => $containerId,
                'box_type' => $boxType,
                'portion_factor' => $portionFactor,
                'portion_text' => $portionText,
                'component_ids' => $componentIds,
                'is_bag' => $isBag,
                'storage_type' => $this->storageTypeForContainer($rawContainer, $isBag),
            ];
        }

        return $result;
    }

    private function generateBoxCode(string $boxType): string
    {
        $prefix = $this->prefixForType($boxType);
        $stmt = $this->pdo->prepare("SELECT sb.box_code FROM set_boxes sb JOIN containers c ON c.id = sb.container_id WHERE sb.box_type = :bt AND c.in_use = 1");
        $stmt->execute(['bt' => $boxType]);
        $codes = array_column($stmt->fetchAll(), 'box_code');

        $containers = $this->pdo->prepare('SELECT container_code FROM containers WHERE in_use = 1 AND container_code LIKE :pfx');
        $containers->execute(['pfx' => $prefix . '%']);
        $codes = array_merge($codes, array_column($containers->fetchAll(), 'container_code'));

        $usedNumbers = [];
        foreach ($codes as $code) {
            if (str_starts_with((string)$code, $prefix)) {
                $num = (int)substr((string)$code, 1);
                if ($num > 0) {
                    $usedNumbers[$num] = true;
                }
            }
        }

        $candidate = 1;
        while (isset($usedNumbers[$candidate])) {
            $candidate++;
        }

        return sprintf('%s%03d', $prefix, $candidate);
    }

    private function prefixForType(string $boxType): string
    {
        $upper = strtoupper($boxType);
        return self::BOX_PREFIX[$upper] ?? 'X';
    }

    private function isBagContainer(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $normalized = strtoupper((string)$value);
        return in_array($normalized, ['FREEZER_BAG', 'VACUUM_BAG', '-1', '-2'], true);
    }

    private function storageTypeForContainer(mixed $value, bool $isBag): string
    {
        if (!$isBag) {
            return 'BOX';
        }

        $normalized = strtoupper((string)$value);
        if (in_array($normalized, ['VACUUM_BAG', '-2'], true)) {
            return 'VACUUM_BAG';
        }

        return 'FREEZER_BAG';
    }

    /**
     * @param array<int> $componentIds
     * @param array<int, array<string, mixed>> $map
     */
    private function calculateKcal(array $componentIds, array $map, ?float $portionFactor): ?int
    {
        $total = 0;
        foreach ($componentIds as $cid) {
            $val = $map[$cid]['kcal_total'] ?? null;
            if ($val === null) {
                return null;
            }
            $total += (int)$val;
        }

        if ($portionFactor !== null) {
            $total = (int)round($total * $portionFactor);
        }

        return $total;
    }

    private function optionalText(null|string $text, int $max = 255): ?string
    {
        if ($text === null) {
            return null;
        }
        $trim = trim($text);
        if ($trim === '') {
            return null;
        }
        return mb_substr($trim, 0, $max);
    }

    private function optionalInt(null|int|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }
}
