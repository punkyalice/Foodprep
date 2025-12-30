<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class RecipeRepository
{
    private const TYPES = ['MEAL','PROTEIN','SAUCE','SIDE','BASE','BREAKFAST','DESSERT','MISC'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit = 50, int $offset = 0, string $sort = 'name'): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(name LIKE :search OR ingredients_text LIKE :search OR tags_text LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if ($filters['type'] !== null) {
            $conditions[] = 'recipe_type = :type';
            $params['type'] = $filters['type'];
        }

        if ($filters['veggie'] !== null) {
            $conditions[] = 'is_veggie = :veggie';
            $params['veggie'] = $filters['veggie'];
        }

        if ($filters['vegan'] !== null) {
            $conditions[] = 'is_vegan = :vegan';
            $params['vegan'] = $filters['vegan'];
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $orderBy = $sort === 'updated' ? 'updated_at DESC, id DESC' : 'name ASC';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM recipes {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT id, name, recipe_type, ingredients_text, prep_text, reheat_text, yield_portions, kcal_per_portion, is_veggie, is_vegan, tags_text, description, instructions, default_best_before_days, created_at, updated_at
                FROM recipes {$where}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $name = $this->requireName($data['name'] ?? '');
        $payload = $this->normalizePayload($data);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO recipes (name, recipe_type, ingredients_text, prep_text, reheat_text, yield_portions, kcal_per_portion, is_veggie, is_vegan, tags_text, description, instructions, default_best_before_days) VALUES (:name, :recipe_type, :ingredients_text, :prep_text, :reheat_text, :yield_portions, :kcal_per_portion, :is_veggie, :is_vegan, :tags_text, :description, :instructions, :default_best_before_days)');
            $stmt->execute([
                'name' => $name,
                'recipe_type' => $payload['recipe_type'],
                'ingredients_text' => $payload['ingredients_text'],
                'prep_text' => $payload['prep_text'],
                'reheat_text' => $payload['reheat_text'],
                'yield_portions' => $payload['yield_portions'],
                'kcal_per_portion' => $payload['kcal_per_portion'],
                'is_veggie' => $payload['is_veggie'],
                'is_vegan' => $payload['is_vegan'],
                'tags_text' => $payload['tags_text'],
                'description' => $payload['description'],
                'instructions' => $payload['instructions'],
                'default_best_before_days' => $payload['default_best_before_days'],
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('duplicate_name', 0, $e);
            }
            throw new RuntimeException('create_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if (empty($data)) {
            return;
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->requireName($data['name']);
        }

        $fields = [];
        $params = ['id' => $id];

        $payload = $this->normalizePayload($data, true);
        foreach ($payload as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params['name'] = $data['name'];
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE recipes SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('duplicate_name', 0, $e);
            }
            throw new RuntimeException('update_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    private function requireName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new RuntimeException('name_required');
        }
        return $trimmed;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, bool $partial = false): array
    {
        $payload = [];

        if (!$partial || array_key_exists('recipe_type', $data)) {
            $payload['recipe_type'] = $this->normalizeType($data['recipe_type'] ?? 'MEAL');
        }

        $textFields = [
            'ingredients_text', 'prep_text', 'reheat_text', 'tags_text', 'description', 'instructions'
        ];
        foreach ($textFields as $field) {
            if ($partial && !array_key_exists($field, $data)) continue;
            $payload[$field] = $this->optionalText($data[$field] ?? null);
        }

        $intFields = ['yield_portions', 'kcal_per_portion', 'default_best_before_days'];
        foreach ($intFields as $field) {
            if ($partial && !array_key_exists($field, $data)) continue;
            $payload[$field] = $this->optionalInt($data[$field] ?? null);
        }

        if (!$partial || array_key_exists('is_veggie', $data)) {
            $payload['is_veggie'] = $this->normalizeBool($data['is_veggie'] ?? 0);
        }

        if (!$partial || array_key_exists('is_vegan', $data)) {
            $payload['is_vegan'] = $this->normalizeBool($data['is_vegan'] ?? 0);
        }

        return $payload;
    }

    private function normalizeType(mixed $value): string
    {
        $type = strtoupper((string)$value);
        if (!in_array($type, self::TYPES, true)) {
            return 'MEAL';
        }
        return $type;
    }

    private function optionalText(null|string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalInt(null|int|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private function normalizeBool(mixed $value): int
    {
        return empty($value) ? 0 : 1;
    }
}
