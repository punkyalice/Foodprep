<?php
declare(strict_types=1);

namespace App\Http\Api;

use App\I18n;
use App\RecipeRepository;
use RuntimeException;

final class RecipeController
{
    /**
     * @param array<string> $allowedTypes
     */
    public function __construct(private RecipeRepository $recipes, private array $allowedTypes = ['MEAL','PROTEIN','SAUCE','SIDE','BASE','BREAKFAST','DESSERT','MISC'], private ?I18n $i18n = null)
    {
    }

    public function handle(string $method, string $path): bool
    {
        if ($method === 'GET' && $path === '/api/recipes') {
            $this->list();
            return true;
        }

        if ($method === 'POST' && $path === '/api/recipes') {
            $this->create();
            return true;
        }

        if ($method === 'PATCH' && preg_match('#^/api/recipes/(\d+)$#', $path, $m)) {
            $this->update((int)$m[1]);
            return true;
        }

        return false;
    }

    private function list(): void
    {
        $filters = [
            'search' => trim((string)($_GET['search'] ?? '')),
            'type' => $this->normalizeType($_GET['type'] ?? null),
            'veggie' => $this->normalizeBoolFilter($_GET['veggie'] ?? null),
            'vegan' => $this->normalizeBoolFilter($_GET['vegan'] ?? null),
        ];

        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $sort = ($_GET['sort'] ?? '') === 'updated' ? 'updated' : 'name';

        $result = $this->recipes->search($filters, $limit, $offset, $sort);
        $this->json(['ok' => true, 'data' => $result['data'], 'total' => $result['total']], 200);
    }

    private function create(): void
    {
        $payload = $this->readJson();
        try {
            $id = $this->recipes->create($payload);
            $this->json(['ok' => true, 'id' => $id], 201);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'duplicate_name' ? 409 : 422;
            $this->error($code, $status);
        }
    }

    private function update(int $id): void
    {
        $existing = $this->recipes->findById($id);
        if (!$existing) {
            $this->error('not_found', 404, 'Rezept nicht gefunden');
            return;
        }

        $payload = $this->readJson();
        try {
            $this->recipes->update($id, $payload);
            $this->json(['ok' => true], 200);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'duplicate_name' ? 409 : 422;
            $this->error($code, $status);
        }
    }

    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(string $code, int $status = 400, string $message = ''): void
    {
        $key = 'errors.' . $code;
        $translated = $this->i18n?->t($key) ?? null;
        $msg = $message ?: ($translated ?: $code);
        $this->json(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]], $status);
    }

    private function readJson(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeType(null|string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $upper = strtoupper($value);
        return in_array($upper, $this->allowedTypes, true) ? $upper : null;
    }

    private function normalizeBoolFilter(null|string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return ((string)$value === '1') ? 1 : 0;
    }
}
