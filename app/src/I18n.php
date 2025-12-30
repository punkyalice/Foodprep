<?php
declare(strict_types=1);

namespace App;

final class I18n
{
    private const SUPPORTED = ['de', 'en'];
    /** @var array<string,string> */
    private array $dict = [];

    public function __construct(private string $locale = 'de')
    {
        if (!in_array($this->locale, self::SUPPORTED, true)) {
            $this->locale = 'de';
        }
        $this->dict = $this->loadDictionary($this->locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function withLocale(string $locale): self
    {
        return new self($locale);
    }

    public function t(string $key): string
    {
        return $this->dict[$key] ?? $key;
    }

    /**
     * @return array<string,string>
     */
    private function loadDictionary(string $locale): array
    {
        $path = __DIR__ . '/../resources/i18n/' . $locale . '.php';
        if (!is_file($path)) {
            return [];
        }
        $data = include $path;
        return is_array($data) ? $data : [];
    }
}
