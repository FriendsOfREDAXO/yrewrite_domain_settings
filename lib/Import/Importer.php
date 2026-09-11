<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

use Exception;
use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use rex_i18n;
use rex_sql;
use rex_sql_table;
use rex_yform_manager_table;
use rex_yform_manager_table_api;

use function array_key_exists;
use function count;
use function in_array;

/**
 * Carries fields and values from global_settings into one section of this addon.
 *
 * Two steps on purpose: plan() says what would happen and is safe to call at
 * any time, run() writes. The editing page shows the first before offering the
 * second - the import replaces what is in the target section, so nobody should
 * have to guess what they are about to lose.
 *
 * @internal
 */
class Importer
{
    /** Columns that carry the addon's own structure and never come from a field. */
    private const STRUCTURAL_COLUMNS = ['id', 'domain_id', 'clang_id'];

    public function __construct(
        private readonly Mapper $mapper = new Mapper(),
    ) {}

    /**
     * What the import would do, field by field.
     *
     * @return list<MappedField>
     */
    public function plan(): array
    {
        $plan = [];

        foreach (Source::getFields() as $field) {
            $plan[] = $this->mapper->map($field);
        }

        return $plan;
    }

    /**
     * Fields that carry a callback, which this import does not take across.
     *
     * @return list<SourceField>
     */
    public function getCallbackFields(): array
    {
        $fields = [];

        foreach (Source::getFields() as $field) {
            if ('' !== trim($field->callback)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Writes fields and values into the given section.
     *
     * Replaces: every field of the target section is removed first, along with
     * its column, so a field that changes type does not meet a column of the
     * old one. The structural columns stay - they decide which row is which.
     *
     * @param list<int> $clangIds languages to copy, usually every one both sides know
     */
    public function run(string $table, int $domainId, array $clangIds): ImportResult
    {
        $result = new ImportResult();

        if (!array_key_exists($table, Backend::getAllSections())) {
            $result->addError(rex_i18n::msg('domain_settings_import_error_unknown_table'));

            return $result;
        }

        $plan = $this->plan();

        if ([] === $plan) {
            $result->addError(rex_i18n::msg('domain_settings_import_error_nothing_to_do'));

            return $result;
        }

        $this->clearSection($table);

        foreach ($plan as $mapped) {
            if ($mapped->isSkipped()) {
                $result->addSkipped($mapped);

                continue;
            }

            try {
                rex_yform_manager_table_api::setTableField($table, $mapped->definition);
                $result->addField($mapped);
            } catch (Exception $e) {
                $result->addError(rex_i18n::msg(
                    'domain_settings_import_error_field',
                    $mapped->source->getKey(),
                    $e->getMessage(),
                ));
            }
        }

        $managerTable = rex_yform_manager_table::get($table);

        if (null === $managerTable) {
            $result->addError(rex_i18n::msg('domain_settings_import_error_unknown_table'));

            return $result;
        }

        // Builds the columns for the fields just written. Not with $delete_old:
        // that drops every column without a field including domain_id and
        // clang_id - the row keys. clearSection() has already taken the old
        // value columns.
        rex_yform_manager_table_api::generateTableAndFields($managerTable);

        foreach ($clangIds as $clangId) {
            $result->addValues($this->copyValues($table, $domainId, $clangId, $plan));
        }

        Backend::resetCaches();
        DomainSettings::deleteCache();

        return $result;
    }

    /**
     * Empties the target section: field definitions and their columns.
     *
     * Removing a YForm field leaves its column behind, so the column has to go
     * separately - otherwise a name that changes type would hit the old
     * column's type and the value would not fit.
     */
    private function clearSection(string $table): void
    {
        $managerTable = rex_yform_manager_table::get($table);

        if (null === $managerTable) {
            return;
        }

        foreach ($managerTable->getFields() as $field) {
            $name = (string) $field->getName();

            if ('' === $name || in_array($name, self::STRUCTURAL_COLUMNS, true)) {
                continue;
            }

            rex_yform_manager_table_api::removeTablefield($table, $name);
        }

        if ('' === $table) {
            return;
        }

        $definition = rex_sql_table::get($table);

        foreach ($definition->getColumns() as $column) {
            $name = $column->getName();

            if (in_array($name, self::STRUCTURAL_COLUMNS, true)) {
                continue;
            }

            $definition->removeColumn($name);
        }

        $definition->alter();

        rex_yform_manager_table::deleteCache();
    }

    /**
     * Copies one language into the row for this domain, creating it if needed.
     *
     * @param list<MappedField> $plan
     *
     * @return int number of values written
     */
    private function copyValues(string $table, int $domainId, int $clangId, array $plan): int
    {
        $source = Source::getValues($clangId);

        if ([] === $source) {
            return 0;
        }

        $values = [];

        foreach ($plan as $mapped) {
            if (!$mapped->carriesValue()) {
                continue;
            }

            $raw = $source[$mapped->source->name] ?? null;

            if (null === $raw) {
                continue;
            }

            $converted = $this->mapper->convertValue($mapped->source, $raw);

            if (null === $converted) {
                continue;
            }

            $values[$mapped->source->getKey()] = $converted;
        }

        if ([] === $values) {
            return 0;
        }

        // Through getDataset() rather than an INSERT: it creates the row with
        // the right domain_id and clang_id when it does not exist yet, which
        // is the same path the editing page uses.
        $dataset = Backend::getDataset($table, ['domain_id' => $domainId, 'clang_id' => $clangId]);

        $sql = rex_sql::factory();
        $sql->setTable($table);
        $sql->setWhere(['id' => $dataset->getId()]);

        foreach ($values as $name => $value) {
            $sql->setValue($name, $value);
        }

        $sql->update();

        return count($values);
    }
}
