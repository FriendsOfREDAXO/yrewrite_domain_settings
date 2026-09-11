<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

use function count;

/**
 * What an import run did, for the message shown afterwards.
 *
 * @internal
 */
class ImportResult
{
    /** @var list<MappedField> */
    private array $fields = [];

    /** @var list<MappedField> */
    private array $skipped = [];

    /** @var list<string> */
    private array $errors = [];

    private int $values = 0;

    public function addField(MappedField $field): void
    {
        $this->fields[] = $field;
    }

    public function addSkipped(MappedField $field): void
    {
        $this->skipped[] = $field;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addValues(int $count): void
    {
        $this->values += $count;
    }

    public function countFields(): int
    {
        return count($this->fields);
    }

    public function countSkipped(): int
    {
        return count($this->skipped);
    }

    public function countValues(): int
    {
        return $this->values;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /**
     * Fields that were written but need looking at - an SQL option list, a
     * colour picker without a counterpart.
     *
     * @return list<MappedField>
     */
    public function getFieldsNeedingAttention(): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            if ($field->needsAttention()) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
