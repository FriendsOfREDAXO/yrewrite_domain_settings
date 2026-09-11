<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

use function count;
use function in_array;

/**
 * One field definition as global_settings stores it.
 *
 * A plain carrier for a row of rex_global_settings_field - the reading and the
 * mapping live elsewhere, so both can be tested without a database.
 *
 * @internal
 */
class SourceField
{
    /** Field types, as global_settings numbers them in rex_global_settings_type. */
    public const TYPE_TEXT = 1;
    public const TYPE_TEXTAREA = 2;
    public const TYPE_SELECT = 3;
    public const TYPE_RADIO = 4;
    public const TYPE_CHECKBOX = 5;
    public const TYPE_MEDIA = 6;
    public const TYPE_MEDIALIST = 7;
    public const TYPE_LINK = 8;
    public const TYPE_LINKLIST = 9;
    public const TYPE_DATE = 10;
    public const TYPE_DATETIME = 11;
    public const TYPE_LEGEND = 12;
    public const TYPE_TIME = 13;
    public const TYPE_TAB = 14;
    public const TYPE_COLORPICKER = 15;
    public const TYPE_RGBACOLORPICKER = 16;

    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly int $typeId,
        public readonly string $notice = '',
        public readonly string $default = '',
        public readonly string $params = '',
        public readonly string $callback = '',
        public readonly int $priority = 0,
        public readonly string $attributes = '',
    ) {}

    /**
     * The name without the `glob_` prefix global_settings requires.
     *
     * Templates already address values by this short form - rex_global_settings
     * strips it before looking a value up - so it is also the name the field
     * carries after the import.
     */
    public function getKey(): string
    {
        return str_starts_with($this->name, 'glob_')
            ? substr($this->name, 5)
            : $this->name;
    }

    /** Whether this type carries a value at all, as opposed to structuring the form. */
    public function hasValue(): bool
    {
        return !in_array($this->typeId, [self::TYPE_LEGEND, self::TYPE_TAB], true);
    }

    /**
     * Whether the option list comes from a query rather than a pipe list.
     *
     * global_settings accepts a SELECT in the same column it otherwise reads
     * options from (handler.php checks for the keyword).
     */
    public function hasSqlOptions(): bool
    {
        return 1 === preg_match('/^\s*SELECT\s/i', $this->params);
    }

    /** Whether this type reads an option list at all. */
    public function hasOptions(): bool
    {
        return in_array($this->typeId, [self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX], true);
    }

    /**
     * A checkbox with a single option and no key is global_settings' boolean.
     *
     * It stores `|true|` when ticked, which becomes a plain YForm checkbox -
     * as opposed to a checkbox with a list of options, which becomes a choice
     * field with several values.
     */
    public function isBoolean(): bool
    {
        if (self::TYPE_CHECKBOX !== $this->typeId) {
            return false;
        }

        if ($this->hasSqlOptions()) {
            return false;
        }

        $options = array_filter(explode('|', $this->params), static fn (string $o) => '' !== trim($o));

        return 1 === count($options) && !str_contains((string) reset($options), ':');
    }
}
