<?php

/**
 * Brings the storage table to the 2.4.0 layout: one row per domain *and*
 * language instead of one row per domain.
 *
 * Included from both install.php and update.php, so it has to be idempotent -
 * and it has to stay free of this addon's own classes: during an update the
 * installer runs update.php out of the freshly downloaded directory while the
 * old files are still the ones registered with the autoloader
 * (install/lib/package/package_update.php), so nothing under lib/ can be
 * relied on here.
 *
 * Existing rows keep their id. Project code out there joins other tables on
 * it - renumbering would quietly break those references.
 */

$table = rex::getTable('yrewrite_domain_settings');
$sql = rex_sql::factory();

$tableExisted = [] !== $sql->getArray('SHOW TABLES LIKE ' . $sql->escape($table));
$columns = $tableExisted ? array_column(rex_sql::showColumns($table), 'name') : [];
$hadClangColumn = in_array('clang_id', $columns, true);

// A column named clang_id that the addon did not create means someone else's
// data is in there. Guessing would be worse than stopping.
if ($hadClangColumn) {
    $foreignField = $sql->getArray(
        'SELECT id FROM ' . rex::getTable('yform_field') . ' WHERE table_name = :table AND name = :name',
        ['table' => $table, 'name' => 'clang_id'],
    );

    if ([] !== $foreignField) {
        throw new rex_functional_exception(
            'Die Tabelle ' . $table . ' hat bereits ein YForm-Feld "clang_id". '
            . 'Das Update braucht diesen Spaltennamen für die Sprachzuordnung. '
            . 'Bitte das Feld vorher umbenennen und das Update erneut starten.',
        );
    }
}

// The domain is chosen by the addon's own page from here on, not inside the
// form, so its field definition goes. The column stays: YForm never drops a
// column when a field is removed. This also takes the `unique` validator on
// domain_id with it - it matches by name - which is what has to happen,
// because it would reject the second language of a domain.
if (null !== rex_yform_manager_table::get($table)) {
    rex_yform_manager_table_api::removeTablefield($table, 'domain_id');
}

$definition = rex_sql_table::get($table)
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('domain_id', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(10) unsigned', false, '0'));

$definition->ensure();

// Rows written before the update belong to the start language: that is the
// language whose values they have always been served as.
if ($tableExisted && !$hadClangColumn) {
    $sql->setQuery(
        'UPDATE ' . $table . ' SET clang_id = :clang WHERE clang_id = 0',
        ['clang' => rex_clang::getStartId()],
    );
}

// Check before the index, not after: a failing ALTER would leave the update
// half applied, and the admin is the one who has to decide which row wins.
$duplicates = $sql->getArray(
    'SELECT domain_id, clang_id, COUNT(*) AS amount FROM ' . $table
    . ' GROUP BY domain_id, clang_id HAVING amount > 1',
);

if ([] !== $duplicates) {
    $pairs = [];
    foreach ($duplicates as $row) {
        $pairs[] = 'Domain ' . $row['domain_id'] . '/Sprache ' . $row['clang_id'] . ' (' . $row['amount'] . 'x)';
    }

    throw new rex_functional_exception(
        'In ' . $table . ' gibt es mehrere Datensätze für dieselbe Domain: '
        . implode(', ', $pairs) . '. Ab 2.4.0 ist je Domain und Sprache genau ein '
        . 'Datensatz vorgesehen. Bitte die überzähligen Datensätze entfernen und '
        . 'das Update erneut starten.',
    );
}

rex_sql_table::get($table)
    ->ensureIndex(new rex_sql_index('domain_clang', ['domain_id', 'clang_id'], rex_sql_index::UNIQUE))
    ->ensure();

// Registered with the YForm table manager so fields can be added there.
// Hidden from the table list: values are edited on this addon's own page,
// which knows about domains, languages and the fallback - the raw data view
// does not and would show one row per language without any context.
$registration = [
    'table_name' => $table,
    'hidden' => 1,
    'schema_overwrite' => 0,
    'export' => 0,
    'import' => 0,
    'search' => 0,
    'mass_deletion' => 0,
    'mass_edit' => 0,
    'history' => 0,
];

// The name has to be passed every time, even when it is not meant to change:
// setTable() falls back to the table name whenever the key is absent, in the
// update branch as well (yform/lib/manager/table/api.php). Leaving it out once
// turned "Domaineinstellungen" into "rex_yrewrite_domain_settings".
$existing = rex_yform_manager_table::get($table);
$currentName = null === $existing ? '' : (string) $existing->getName();

if ('' !== $currentName && $currentName !== $table) {
    // Whatever this installation calls its table, it keeps calling it that.
    $registration['name'] = $currentName;
} else {
    // First registration. The language files of the *new* version are only
    // loaded when this runs from install.php; during an update the old ones
    // are still active, hence the literal fallback.
    $registration['name'] = rex_i18n::hasMsg('domain_settings_table')
        ? rex_i18n::msg('domain_settings_table')
        : 'Domaineinstellungen';
    $registration['description'] = rex_i18n::hasMsg('domain_settings_table_description')
        ? rex_i18n::msg('domain_settings_table_description')
        : '';
}

// setTable() clears YForm's table cache itself.
rex_yform_manager_table_api::setTable($registration);

// The value cache still holds the pre-update shape.
rex_file::delete(rex_path::addonCache('yrewrite_domain_settings', 'values.json'));
