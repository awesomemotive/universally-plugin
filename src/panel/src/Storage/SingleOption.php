<?php

namespace UniversallyPanel\Panel\Storage;

/**
 * Storage implementation using a single WordPress option.
 */
final class SingleOption implements StorageInterface
{
    private string $optionName;
    private ?array $cache = null;

    public function __construct(string $optionName)
    {
        $this->optionName = $optionName;
    }

    public function get(string $key, $default = null)
    {
        $all = $this->getAll();
        return $all[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $all = $this->getAll();
        $all[$key] = $value;
        $this->setAll($all);
    }

    public function getAll(): array
    {
        if ($this->cache === null) {
            $value = get_option($this->optionName, []);
            $this->cache = is_array($value) ? $value : [];
        }
        return $this->cache;
    }

    public function setAll(array $values): void
    {
        update_option($this->optionName, $values, false);
        $this->cache = $values;
    }

    public function has(string $key): bool
    {
        $all = $this->getAll();
        return array_key_exists($key, $all);
    }

    public function delete(string $key): void
    {
        $all = $this->getAll();
        unset($all[$key]);
        $this->setAll($all);
    }

    /**
     * Clear the internal cache.
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }
}
