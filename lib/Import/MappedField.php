<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

/**
 * What one global_settings field becomes on this side.
 *
 * Carries the YForm field definition and, just as important, what the editor
 * has to know before the import runs: whether anything is left to do by hand.
 *
 * @internal
 */
class MappedField
{
    /** Carried over without loss. */
    public const STATUS_AUTOMATIC = 'automatic';

    /** Imported, but something needs looking at afterwards. */
    public const STATUS_MANUAL = 'manual';

    /** No counterpart - the field is skipped. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, scalar> $definition field definition for rex_yform_manager_table_api::setTableField()
     * @param list<string> $notes what the editor has to know, already translated
     */
    public function __construct(
        public readonly SourceField $source,
        public readonly string $status,
        public readonly string $targetType,
        public readonly array $definition = [],
        public readonly array $notes = [],
    ) {}

    public function isSkipped(): bool
    {
        return self::STATUS_SKIPPED === $this->status;
    }

    public function needsAttention(): bool
    {
        return self::STATUS_MANUAL === $this->status;
    }

    /** Whether this field carries a value that has to be copied. */
    public function carriesValue(): bool
    {
        return !$this->isSkipped() && $this->source->hasValue();
    }
}
