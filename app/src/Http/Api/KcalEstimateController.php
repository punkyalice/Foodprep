<?php
declare(strict_types=1);

namespace App\Http\Api;

use App\Config;
use RuntimeException;

final class KcalEstimateController
{
    private const RATE_LIMIT_SECONDS = 60;

    public function handle(string $method, string $path): bool
    {
        if ($method === 'POST' && $path === '/api/recipes/estimate-kcal') {
            $this->estimate();
            return true;
        }

        return false;
    }

    private function estimate(): void
    {
        $body = $this->readJson();
        $ingredients = trim((string)($body['ingredients_text'] ?? ''));
        if (strlen($ingredients) < 5) {
            $this->json(['ok' => false, 'error' => 'missing_ingredients'], 422);
            return;
        }

        $yield = (int)($body['yield_portions'] ?? 1);
        if ($yield < 1) {
            $yield = 1;
        }

        $apiKey = Config::env('OPENAI_API_KEY');
        if (!$apiKey) {
            $this->json(['ok' => false, 'error' => 'openai_not_configured'], 503);
            return;
        }

        $rateLimit = $this->checkRateLimit();
        if ($rateLimit['limited']) {
            $this->json([
                'ok' => false,
                'error' => 'rate_limited',
                'retry_after_seconds' => $rateLimit['retry_after'],
            ], 429);
            return;
        }

        try {
            $kcal = $this->requestOpenAi($ingredients, $yield, $apiKey);
            $this->json(['ok' => true, 'kcal_per_portion' => $kcal], 200);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            $status = $code === 'openai_not_configured' ? 503 : 502;
            if ($code === 'openai_error') {
                $status = 502;
            } elseif ($code === 'openai_invalid_response') {
                $status = 502;
            } elseif ($code === 'server_error') {
                $status = 500;
            }
            $this->json(['ok' => false, 'error' => $code], $status);
        }
    }

    private function requestOpenAi(string $ingredients, int $yield, string $apiKey): int
    {
        $baseUrl = rtrim(Config::env('OPENAI_BASE_URL', 'https://api.openai.com/v1') ?? 'https://api.openai.com/v1', '/');
        $model = Config::env('OPENAI_MODEL', 'gpt-4.1-mini') ?? 'gpt-4.1-mini';
        $url = $baseUrl . '/chat/completions';

        $systemPrompt = 'Du bist ein Nährwert-Schätzer. Antworte nur mit einer Zahl oder Range im Format 123 oder 123-150. Keine Einheiten, kein Text.';
        $userPrompt = "I give you a list of ingredients for food and the amount of portions this is going to be. I want you to calculate / estimate how many calories one portion is going to have.\nMake an educated, conservative guess if things are vague. For things like oil/butter for frying estimate 2 tablespoons total, if nothing else is given.\nIf yield_portions is missing or zero, assume 1 portion.\nReturn ONLY the calories per portion as an integer number OR a range like 250-320. Nothing else.\n\nYield portions: {$yield}\nIngredients:\n{$ingredients}";

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'max_tokens' => 50,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('server_error');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            curl_close($ch);
            throw new RuntimeException('openai_error');
        }
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('openai_error');
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('openai_error');
        }

        $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('openai_invalid_response');
        }

        $parsed = $this->parseKcal($content);
        if ($parsed === null) {
            throw new RuntimeException('openai_invalid_response');
        }

        return $parsed;
    }

    private function parseKcal(string $content): ?int
    {
        if (preg_match('/^(\d{2,5})$/', $content, $m)) {
            return (int)$m[1];
        }

        if (preg_match('/^(\d{2,5})\s*-\s*(\d{2,5})$/', $content, $m)) {
            $low = (int)$m[1];
            $high = (int)$m[2];
            if ($high < $low) {
                [$low, $high] = [$high, $low];
            }
            return (int)round(($low + $high) / 2);
        }

        return null;
    }

    private function checkRateLimit(): array
    {
        $dir = $this->rateLimitDir();
        if ($dir === '') {
            return ['limited' => false, 'retry_after' => 0];
        }

        $key = sha1(($this->clientIp() ?: 'unknown') . $this->userAgent() . 'kcal_estimate');
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key;

        $now = time();
        $retryAfter = 0;

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return ['limited' => false, 'retry_after' => 0];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return ['limited' => false, 'retry_after' => 0];
            }

            $raw = stream_get_contents($fp);
            $lastCall = $raw !== false ? (int)$raw : 0;
            if ($lastCall > 0 && ($now - $lastCall) < self::RATE_LIMIT_SECONDS) {
                $retryAfter = self::RATE_LIMIT_SECONDS - ($now - $lastCall);
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['limited' => true, 'retry_after' => $retryAfter];
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string)$now);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } catch (RuntimeException $e) {
            fclose($fp);
            return ['limited' => false, 'retry_after' => 0];
        }

        return ['limited' => false, 'retry_after' => 0];
    }

    private function rateLimitDir(): string
    {
        $root = dirname(__DIR__, 3);
        $candidates = [
            $root . '/tmp/ratelimit',
            $root . '/var/ratelimit',
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/kcal_estimate',
        ];

        foreach ($candidates as $dir) {
            if ($this->ensureWritableDir($dir)) {
                return $dir;
            }
        }

        return '';
    }

    private function ensureWritableDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = dirname($dir);
        if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
            return false;
        }

        return (@mkdir($dir, 0777, true) || is_dir($dir)) && is_writable($dir);
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    private function userAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return is_string($ua) ? $ua : '';
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

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
