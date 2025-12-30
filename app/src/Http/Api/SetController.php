<?php
declare(strict_types=1);

namespace App\Http\Api;

use App\I18n;
use App\SetRepository;
use App\InventoryRepository;
use App\ContainerRepository;
use RuntimeException;

final class SetController
{
    public function __construct(
        private SetRepository $sets,
        private InventoryRepository $inventory,
        private ContainerRepository $containers,
        private ?I18n $i18n = null
    ) {
    }

    public function handle(string $method, string $path): bool
    {
        if ($method === 'GET' && $path === '/api/sets') {
            $this->list();
            return true;
        }

        if ($method === 'POST' && $path === '/api/sets') {
            $this->create();
            return true;
        }

        if ($method === 'GET' && preg_match('#^/api/sets/(\d+)$#', $path, $m)) {
            $this->get((int)$m[1]);
            return true;
        }

        if ($method === 'PATCH' && preg_match('#^/api/sets/(\d+)$#', $path, $m)) {
            $this->update((int)$m[1]);
            return true;
        }

        if ($method === 'POST' && preg_match('#^/api/sets/(\d+)/boxes$#', $path, $m)) {
            $this->addBoxes((int)$m[1]);
            return true;
        }

        return false;
    }

    private function list(): void
    {
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $items = $this->sets->listSets($limit, $offset);
        $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function create(): void
    {
        $payload = $this->readJson();
        try {
            $set = $this->sets->createSet($payload);
            $this->json(['ok' => true, 'data' => $set], 201);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = ($code === 'missing_name' || $code === 'missing_components') ? 422 : 400;
            $this->error($code, $status);
        }
    }

    private function get(int $id): void
    {
        $set = $this->sets->getSet($id);
        if (!$set) {
            $this->error('not_found', 404);
            return;
        }
        $this->json(['ok' => true, 'data' => $set], 200);
    }

    private function update(int $id): void
    {
        $payload = $this->readJson();
        try {
            $set = $this->sets->updateSet($id, $payload);
            $this->json(['ok' => true, 'data' => $set], 200);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'not_found' ? 404 : 422;
            $this->error($code, $status);
        }
    }

    private function addBoxes(int $setId): void
    {
        $payload = $this->readJson();
        if (!is_array($payload)) {
            $payload = [];
        }
        try {
            $created = $this->sets->addBoxes($setId, $payload, $this->inventory, $this->containers);
            $this->json(['ok' => true, 'boxes' => $created], 201);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = in_array($code, ['not_found', 'container_not_available'], true) ? 404 : 422;
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
}
