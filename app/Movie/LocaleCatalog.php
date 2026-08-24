<?php

namespace App\Movie;

/**
 * Locale datasets live as JSON files under resources/locales.
 * Add, remove, or update a language without touching PHP.
 */
final class LocaleCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $locales = [];

    public function __construct(private readonly string $localesDir)
    {
        $this->load();
    }

    /**
     * @return array<string, array{code: string, label: string}>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->locales as $code => $data) {
            $choices[$code] = [
                'code' => $code,
                'label' => (string) ($data['label'] ?? $code),
            ];
        }

        return $choices;
    }

    public function defaultCode(): string
    {
        if (isset($this->locales['en_US'])) {
            return 'en_US';
        }

        $codes = array_keys($this->locales);

        return $codes[0] ?? 'en_US';
    }

    public function has(string $code): bool
    {
        return isset($this->locales[$code]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dataset(string $code): array
    {
        if (!$this->has($code)) {
            throw new \InvalidArgumentException(sprintf('Unknown locale "%s".', $code));
        }

        return $this->locales[$code];
    }

    public function fakerLocale(string $code): string
    {
        $dataset = $this->dataset($code);

        return (string) ($dataset['faker'] ?? $code);
    }

    private function load(): void
    {
        $pattern = rtrim($this->localesDir, '/').'/*.json';
        foreach (glob($pattern) ?: [] as $file) {
            $code = basename($file, '.json');
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                continue;
            }
            $this->locales[$code] = $decoded;
        }

        ksort($this->locales);
    }
}
