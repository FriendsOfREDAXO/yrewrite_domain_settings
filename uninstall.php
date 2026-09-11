<?php

/**
 * Removes what install.php created: the value tables and their YForm
 * registration.
 *
 * Dropping the data is the convention among the addons this one lives next to
 * - yrewrite drops its domains, forwards and aliases, structure drops the
 * articles. Keeping the rows behind would leave tables in the Table Manager
 * that nothing guards any more: the protections against deleting `domain_id`
 * and `clang_id` live in boot.php, which is gone by then.
 *
 * @var rex_addon $this
 */

$base = rex::getTable('yrewrite_domain_settings');

// The tab tables are named at runtime, so they are looked up rather than
// listed. `_` is a LIKE wildcard - escaped, or `rex_yrewrite_domain_settingsX`
// would match as well.
$candidates = rex_sql::factory()->getArray(
    'SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME = :base OR TABLE_NAME LIKE :prefix)',
    [':base' => $base, ':prefix' => str_replace('_', '\_', $base) . '\_%'],
);

$removed = [];

foreach ($candidates as $row) {
    $name = (string) $row['TABLE_NAME'];
    $table = rex_sql_table::get($name);

    // The same test the addon uses to recognise a tab: both structural columns
    // must be there. A project table that merely shares the prefix is none of
    // our business and stays.
    if (!$table->hasColumn('domain_id') || !$table->hasColumn('clang_id')) {
        continue;
    }

    // YForm may already be uninstalled - then there is no registration left to
    // remove, only the table itself.
    if (class_exists(rex_yform_manager_table_api::class)) {
        rex_yform_manager_table_api::removeTable($name);
    }

    $table->drop();
    rex_sql_table::clearInstance($name);

    $removed[] = $name;
}

// Worth a line: the values are gone for good, and an uninstall is easy to
// trigger by accident.
if ([] !== $removed) {
    rex_logger::factory()->info(
        'domain_settings: uninstall dropped {count} table(s): {tables}',
        ['count' => count($removed), 'tables' => implode(', ', $removed)],
    );
}
