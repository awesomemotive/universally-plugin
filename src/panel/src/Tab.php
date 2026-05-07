<?php

namespace UniversallyPanel\Panel;

class Tab
{
    private string $id;
    private string $label;
    private ?string $storage = null;
    /** @var Field[] */
    private array $fields = [];
    private ?Field $currentField = null;

    public function __construct(string $id, string $label)
    {
        $this->id = $id;
        $this->label = $label;
    }

    public function storage(string $storage): self
    {
        $this->storage = $storage;
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

    /**
     * @return Field[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Add a field and return it for chaining.
     */
    public function addField(Field $field): Field
    {
        $this->fields[$field->getId()] = $field;
        $this->currentField = $field;
        return $field;
    }

    /**
     * Get current field being configured.
     */
    public function getCurrentField(): ?Field
    {
        return $this->currentField;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        $data = [
            'label' => $this->label,
        ];

        if ($this->storage !== null) {
            $data['storage'] = $this->storage;
        }

        $data['fields'] = [];
        foreach ($this->fields as $id => $field) {
            $data['fields'][$id] = $field->toArray();
        }

        return $data;
    }
}
