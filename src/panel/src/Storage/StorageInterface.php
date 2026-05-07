<?php

namespace UniversallyPanel\Panel\Storage;

/**
 * Interface for storage implementations.
 */
interface StorageInterface
{
    /**
     * Get a single value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a single value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Get all values.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array;

    /**
     * Set all values at once.
     *
     * @param array<string, mixed> $values
     */
    public function setAll(array $values): void;

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool;

    /**
     * Delete a key.
     */
    public function delete(string $key): void;
}
