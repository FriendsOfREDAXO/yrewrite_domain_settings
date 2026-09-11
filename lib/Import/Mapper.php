<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

use rex_i18n;

/**
 * Translates global_settings field definitions and values into YForm's shape.
 *
 * Pure translation, no database: what comes out of here is a definition array
 * for rex_yform_manager_table_api::setTableField() and a converted value. That
 * keeps the part with all the edge cases testable on its own.
 *
 * @internal
 */
class Mapper
{
    /**
     * Both sides separate multiple values, but differently: global_settings
     * wraps them in pipes, YForm uses commas. `|+|` is the older separator and
     * still turns up in installations that were never re-saved.
     */
    private const LEGACY_SEPARATOR = '|+|';

    public function map(SourceField $field): MappedField
    {
        return match ($field->typeId) {
            SourceField::TYPE_TEXT => $this->simple($field, 'text'),
            SourceField::TYPE_TEXTAREA => $this->simple($field, 'textarea'),
            SourceField::TYPE_SELECT, SourceField::TYPE_RADIO, SourceField::TYPE_CHECKBOX => $this->choice($field),
            SourceField::TYPE_MEDIA => $this->media($field, false),
            SourceField::TYPE_MEDIALIST => $this->media($field, true),
            SourceField::TYPE_LINK => $this->link($field, false),
            SourceField::TYPE_LINKLIST => $this->link($field, true),
            SourceField::TYPE_DATE => $this->simple($field, 'date'),
            SourceField::TYPE_DATETIME => $this->simple($field, 'datetime'),
            SourceField::TYPE_TIME => $this->simple($field, 'time'),
            SourceField::TYPE_LEGEND => $this->fieldset($field),
            SourceField::TYPE_TAB => $this->skip($field, rex_i18n::msg('domain_settings_import_note_tab')),
            SourceField::TYPE_COLORPICKER, SourceField::TYPE_RGBACOLORPICKER => $this->colour($field),
            default => $this->skip($field, rex_i18n::msg('domain_settings_import_note_unknown_type', $field->typeId)),
        };
    }

    /**
     * Converts one stored value into what the target expects.
     *
     * Returns null where the value has no meaning on this side - an unset date
     * rather than one in 1970.
     */
    public function convertValue(SourceField $field, string $value): ?string
    {
        if ('' === $value) {
            return '';
        }

        return match ($field->typeId) {
            SourceField::TYPE_CHECKBOX => $field->isBoolean()
                ? ($this->isTicked($value) ? '1' : '0')
                : $this->pipesToCommas($value),
            SourceField::TYPE_SELECT, SourceField::TYPE_RADIO => $this->pipesToCommas($value),
            SourceField::TYPE_DATE => $this->fromTimestamp($value, 'Y-m-d'),
            SourceField::TYPE_DATETIME => $this->fromTimestamp($value, 'Y-m-d H:i:s'),
            SourceField::TYPE_TIME => $this->fromTimestamp($value, 'H:i:s'),
            default => $value,
        };
    }

    /**
     * @param array<string, scalar> $extra
     *
     * @return array<string, scalar>
     */
    private function definition(SourceField $field, string $type, array $extra = []): array
    {
        return [
            'type_id' => 'value',
            'type_name' => $type,
            'name' => $field->getKey(),
            'label' => '' !== $field->title ? $field->title : $field->getKey(),
            'notice' => $field->notice,
            'prio' => $field->priority,
        ] + $extra;
    }

    private function simple(SourceField $field, string $type): MappedField
    {
        $extra = [];
        $notes = [];

        // A default only survives where the target stores the same thing a
        // date field would read a timestamp it cannot parse.
        if ('' !== $field->default && in_array($type, ['text', 'textarea'], true)) {
            $extra['default'] = $field->default;
        }

        return new MappedField($field, MappedField::STATUS_AUTOMATIC, $type, $this->definition($field, $type, $extra), $notes);
    }

    private function media(SourceField $field, bool $multiple): MappedField
    {
        $extra = $multiple ? ['multiple' => 1] : [];

        return new MappedField(
            $field,
            MappedField::STATUS_AUTOMATIC,
            'be_media',
            $this->definition($field, 'be_media', $extra),
        );
    }

    private function link(SourceField $field, bool $multiple): MappedField
    {
        $extra = $multiple ? ['multiple' => 1] : [];

        return new MappedField(
            $field,
            MappedField::STATUS_AUTOMATIC,
            'be_link',
            $this->definition($field, 'be_link', $extra),
        );
    }

    private function fieldset(SourceField $field): MappedField
    {
        return new MappedField(
            $field,
            MappedField::STATUS_AUTOMATIC,
            'fieldset',
            [
                'type_id' => 'value',
                'type_name' => 'fieldset',
                'name' => $field->getKey(),
                'label' => '' !== $field->title ? $field->title : $field->getKey(),
                'prio' => $field->priority,
            ],
        );
    }

    private function colour(SourceField $field): MappedField
    {
        return new MappedField(
            $field,
            MappedField::STATUS_MANUAL,
            'text',
            $this->definition($field, 'text', '' !== $field->default ? ['default' => $field->default] : []),
            [rex_i18n::msg('domain_settings_import_note_colorpicker')],
        );
    }

    /**
     * select, radio and checkbox all become YForm's choice - except the single
     * unkeyed checkbox, which is global_settings' way of storing a boolean.
     */
    private function choice(SourceField $field): MappedField
    {
        if ($field->isBoolean()) {
            return new MappedField(
                $field,
                MappedField::STATUS_AUTOMATIC,
                'checkbox',
                $this->definition($field, 'checkbox'),
            );
        }

        $expanded = in_array($field->typeId, [SourceField::TYPE_RADIO, SourceField::TYPE_CHECKBOX], true);
        $multiple = SourceField::TYPE_CHECKBOX === $field->typeId || $this->isMultipleSelect($field);

        // A query goes across unchanged, and the editor is told why: YForm
        // reads named columns (value/label), global_settings the first two by
        // position. Rewriting that reliably would mean parsing SQL.
        if ($field->hasSqlOptions()) {
            return new MappedField(
                $field,
                MappedField::STATUS_MANUAL,
                'choice',
                $this->definition($field, 'choice', [
                    'choices' => $field->params,
                    'expanded' => $expanded ? 1 : 0,
                    'multiple' => $multiple ? 1 : 0,
                ]),
                [rex_i18n::msg('domain_settings_import_note_sql_options')],
            );
        }

        return new MappedField(
            $field,
            MappedField::STATUS_AUTOMATIC,
            'choice',
            $this->definition($field, 'choice', [
                'choices' => $this->choicesToJson($field->params),
                'expanded' => $expanded ? 1 : 0,
                'multiple' => $multiple ? 1 : 0,
            ]),
        );
    }

    private function skip(SourceField $field, string $note): MappedField
    {
        return new MappedField($field, MappedField::STATUS_SKIPPED, '', [], [$note]);
    }

    /**
     * Option list into the JSON that YForm's choice expects.
     *
     * Watch the direction: global_settings writes `value:Label`, YForm reads
     * `{"Label": "value"}`. Turning that around would keep every option
     * looking right in the backend while no stored value matches any more.
     */
    private function choicesToJson(string $params): string
    {
        $choices = [];

        foreach (explode('|', $params) as $option) {
            $option = trim($option);

            if ('' === $option) {
                continue;
            }

            if (str_contains($option, ':')) {
                [$value, $label] = explode(':', $option, 2);
                $choices[trim($label)] = trim($value);

                continue;
            }

            // Without a colon the option is its own label and its own value.
            $choices[$option] = $option;
        }

        return json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** global_settings marks a multi-select through the HTML attributes of the field. */
    private function isMultipleSelect(SourceField $field): bool
    {
        return SourceField::TYPE_SELECT === $field->typeId
            && str_contains(strtolower($field->attributes), 'multiple');
    }

    private function pipesToCommas(string $value): string
    {
        if (str_contains($value, self::LEGACY_SEPARATOR)) {
            $parts = explode(self::LEGACY_SEPARATOR, $value);
        } else {
            $parts = explode('|', $value);
        }

        $parts = array_filter(array_map('trim', $parts), static fn (string $p) => '' !== $p);

        return implode(',', $parts);
    }

    /** A ticked boolean checkbox is stored as `|true|`. */
    private function isTicked(string $value): bool
    {
        return in_array(trim($value, '|'), ['true', '1'], true);
    }

    /**
     * Dates are unix timestamps over there. Zero means "not set" rather than
     * 1970 - global_settings shows an empty field for it.
     */
    private function fromTimestamp(string $value, string $format): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        if (0 === $timestamp) {
            return null;
        }

        return date($format, $timestamp);
    }
}
