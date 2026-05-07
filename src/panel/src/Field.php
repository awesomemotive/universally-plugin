<?php

namespace UniversallyPanel\Panel;

class Field
{
    private string $id;
    private string $type;
    private string $label;
    private ?string $description = null;
    private ?string $placeholder = null;
    private $default = null;
    private ?string $storage = null;
    private $conditions = null;
    private array $options = [];
    private array $extra = [];

    public function __construct(string $id, string $type, string $label)
    {
        $this->id = $id;
        $this->type = $type;
        $this->label = $label;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function default($value): self
    {
        $this->default = $value;
        return $this;
    }

    public function storage(string $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    /**
     * Set visibility condition(s).
     *
     * @param string|array $conditions Single condition string, array for OR, nested array for AND
     */
    public function conditions($conditions): self
    {
        $this->conditions = $conditions;
        return $this;
    }

    /**
     * Set options for select fields.
     *
     * @param array<string, string> $options value => label pairs
     */
    public function options(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Set extra attributes.
     */
    public function extra(string $key, $value): self
    {
        $this->extra[$key] = $value;
        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStorage(): ?string
    {
        return $this->storage;
    }

    public function getDefault()
    {
        return $this->default;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'label' => $this->label,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->placeholder !== null) {
            $data['placeholder'] = $this->placeholder;
        }
        if ($this->default !== null) {
            $data['default'] = $this->default;
        }
        if ($this->storage !== null) {
            $data['storage'] = $this->storage;
        }
        if ($this->conditions !== null) {
            $data['conditions'] = $this->conditions;
        }
        if (!empty($this->options)) {
            $data['options'] = $this->options;
        }
        if (!empty($this->extra)) {
            $data = array_merge($data, $this->extra);
        }

        return $data;
    }
}
