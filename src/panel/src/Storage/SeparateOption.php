<?php

namespace UniversallyPanel\Panel\Storage;

/**
 * Storage implementation using separate WordPress options per field.
 */
final class SeparateOption implements StorageInterface
{
    private string $prefix;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    private function makeKey(string $key): string
    {
        return $this->prefix . '_' . $key;
    }

    public function get(string $key, $default = null)
    {
        $optionKey = $this->makeKey($key);

        if (!array_key_exists($key, $this->cache)) {
            $value = get_option($optionKey);
            $this->cache[$key] = $value !== false ? $value : $default;
        }

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        update_option($this->makeKey($key), $value, false);
        $this->cache[$key] = $value;
    }

    public function getAll(): array
    {
        return $this->cache;
    }

    public function setAll(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function has(string $key): bool
    {
        return get_option($this->makeKey($key)) !== false;
    }

    public function delete(string $key): void
    {
        delete_option($this->makeKey($key));
        unset($this->cache[$key]);
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
