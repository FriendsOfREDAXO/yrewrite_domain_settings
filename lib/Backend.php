<?php

namespace FriendsOfRedaxo\DomainSettings;

use rex;
use rex_addon;
use rex_clang;
use rex_file;
use rex_i18n;
use rex_logger;
use rex_path;
use rex_sql;
use rex_sql_column;
use rex_sql_index;
use rex_sql_table;
use rex_string;
use rex_url;
use rex_view;
use rex_yform;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yform_manager_table_api;
use rex_yform_manager_table_perm_edit;
use rex_yrewrite;
use rex_yrewrite_domain;
use RuntimeException;

use function array_key_exists;
use function count;
use function in_array;
use function strlen;

use const ARRAY_FILTER_USE_KEY;

/**
 * Helpers for the editing page.
 *
 * Kept apart from DomainSettings so the read path stays free of backend concerns and
 * never loads any of this in the frontend.
 */
final class Backend
{
    /**
     * Page keys a section may not use.
     *
     * `main` belongs to the base table, `settings` and `help` to the statically
     * defined subpages. A section taking one of them would collide: the page
     * list is merged with the section pages winning, so a section called
     * "Settings" would push out the very page it could be deleted from, and a
     * duplicate `main` would make one section unreachable and point its API
     * scope at the other one's table.
     */
    private const RESERVED_SLUGS = ['main', 'settings', 'help'];
    /**
     * Section list for this request.
     *
     * Built once per request: the list is needed on every backend request to
     * build the navigation, and in several extension points on top of that.
     *
     * @var array<string, string>|null
     */
    private static ?array $sections = null;

    /**
     * Domain list for this request. Built from yrewrite's domains, which do
     * not change within a request, but is asked for repeatedly - once per row
     * of the role form among others.
     *
     * @var array<int, string>|null
     */
    private static ?array $domains = null;

    /**
     * Language ids per domain, from yrewrite. Asked for on every get() that
     * falls back, so it is worth not walking yrewrite's list each time.
     *
     * @var array<int, list<int>>
     */
    private static array $domainClangIds = [];

    /**
     * Every domain known to the system, as id => label.
     *
     * Without yrewrite there is exactly one entry: domain 0, the whole site.
     * yrewrite's implicit `default` domain has no id and therefore also maps
     * to 0, so both cases line up.
     *
     * Never returns an empty array - the REST routes rely on that when they
     * resolve a missing domain_id to the first entry.
     *
     * @return array<int, string>
     */
    public static function getAllDomains(): array
    {
        if (null !== self::$domains) {
            return self::$domains;
        }

        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return self::$domains = [0 => rex_i18n::msg('domain_settings_domain_default')];
        }

        $yrewriteDomains = self::getYrewriteDomains();

        $domains = [];
        foreach ($yrewriteDomains as $domain) {
            $id = (int) $domain->getId();
            if (0 === $id) {
                continue;
            }
            $domains[$id] = $domain->getName();
        }

        // Only offer yrewrite's implicit "default" domain while there is no
        // real one. Once domains are configured, every article belongs to one
        // of them, so values maintained on domain 0 would never show up in the
        // frontend - an easy trap to fall into.
        if ([] === $domains) {
            return self::$domains = [0 => rex_i18n::msg('domain_settings_domain_default')];
        }

        ksort($domains);

        return self::$domains = $domains;
    }

    /**
     * yrewrite's domain objects.
     *
     * yrewrite fills its domain list during boot in a web request. In a
     * console command it stays empty until init() runs, which would make a
     * configured instance look like it had no domains at all. Checking for the
     * empty list beats sniffing the context: rex::isBackend() is true on the
     * CLI as well, so it cannot tell the two apart.
     *
     * @return array<string, rex_yrewrite_domain>
     */
    private static function getYrewriteDomains(): array
    {
        $domains = rex_yrewrite::getDomains();

        if ([] === $domains) {
            rex_yrewrite::init();
            $domains = rex_yrewrite::getDomains();
        }

        return $domains;
    }

    /**
     * The languages a domain actually serves, as configured in yrewrite.
     *
     * A domain that only runs German and English must not offer a third
     * language for editing - values maintained there would never be delivered.
     * An empty list means "no restriction": that is domain 0, an instance
     * without yrewrite, or a domain that has no language selection of its own.
     *
     * @return list<int>
     */
    public static function getDomainClangIds(int $domainId): array
    {
        if (isset(self::$domainClangIds[$domainId])) {
            return self::$domainClangIds[$domainId];
        }

        if (0 === $domainId || !rex_addon::get('yrewrite')->isAvailable()) {
            return self::$domainClangIds[$domainId] = [];
        }

        // Warms yrewrite's list on the CLI, see getYrewriteDomains().
        self::getYrewriteDomains();
        $domain = rex_yrewrite::getDomainById($domainId);

        if (null === $domain) {
            return self::$domainClangIds[$domainId] = [];
        }

        $clangs = array_map('intval', $domain->getClangs());

        return self::$domainClangIds[$domainId] = array_values(array_filter($clangs, rex_clang::exists(...)));
    }

    /** The start language yrewrite has configured for a domain, if any. */
    public static function getDomainStartClangId(int $domainId): ?int
    {
        self::getYrewriteDomains();
        $domain = rex_yrewrite::getDomainById($domainId);

        if (null === $domain) {
            return null;
        }

        $startClang = (int) $domain->getStartClang();

        return rex_clang::exists($startClang) ? $startClang : null;
    }

    /**
     * Domains the current user may edit.
     *
     * @return array<int, string>
     */
    public static function getDomains(): array
    {
        $user = rex::getUser();

        // No user, no permissions. Returning everything would make this filter
        // a no-op the moment the method is reached from a context without a
        // login - the console, the API, a frontend call.
        if (null === $user) {
            return [];
        }

        $domains = self::getAllDomains();
        $perm = $user->getComplexPerm('yrewrite_domains');

        if (!$perm instanceof DomainPerm) {
            return [];
        }

        return array_filter(
            $domains,
            static fn (int $id) => $perm->hasPerm($id),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * All section tables, as table name => label.
     *
     * A section is an ordinary YForm table named like the main one plus a
     * suffix. Recognising them by prefix keeps the addon free of a second
     * registry, but the prefix alone is not enough: a project table that
     * happens to start the same way must not be picked up, so the structural
     * columns are checked as well.
     *
     * @return array<string, string>
     */
    public static function getAllSections(): array
    {
        if (null !== self::$sections) {
            return self::$sections;
        }

        $base = rex::getTable(DomainSettings::ADDON);
        $sections = [];
        // The base table claims `main` before anything else can.
        $slugs = ['main' => true];

        foreach (rex_yform_manager_table::getAll() as $table) {
            $name = $table->getTableName();

            if ($name !== $base && !str_starts_with($name, $base . '_')) {
                continue;
            }

            // Through YForm's own cache rather than SHOW COLUMNS: the column
            // list sits in the same cache file the table came from, and this
            // runs on every backend request via PAGES_PREPARED. It also keeps
            // a stale YForm registration from throwing here - though a table
            // that is gone still fails later, when its values are read.
            $columns = $table->getColumns();
            if (!isset($columns['domain_id'], $columns['clang_id'])) {
                continue;
            }

            // Two sections with the same key would be genuinely ambiguous:
            // one tab would show the other's values, and their API scopes
            // would overwrite each other. The base table wins, everything
            // else is first come, first served - and said out loud, because
            // the section simply would not appear otherwise.
            $slug = self::sectionSlug($name);

            if ($name !== $base && isset($slugs[$slug])) {
                rex_logger::factory()->warning(
                    'domain_settings: section {table} is ignored, another section already uses the key {slug}',
                    ['table' => $name, 'slug' => $slug],
                );

                continue;
            }

            $slugs[$slug] = true;

            $sections[$name] = $table->getNameLocalized();
        }

        return self::$sections = $sections;
    }

    /**
     * Drops everything this class keeps for the duration of a request.
     *
     * Needed after creating, renaming or deleting a section, and after
     * anything that changes yrewrite's domains - all three caches are built
     * once per request, see the properties.
     */
    public static function resetCaches(): void
    {
        self::$sections = null;
        self::$domains = null;
        self::$domainClangIds = [];
    }

    /**
     * Sections the current user may edit.
     *
     * Uses YForm's own table permission, so every section shows up in the role
     * form by itself and the authorisation is YForm's, not ours.
     *
     * Empty without a logged-in user - on the console, where copyValues()
     * therefore finds nothing to do. A command that needs to work on sections
     * has to go through getAllSections().
     *
     * @return array<string, string>
     */
    public static function getSections(): array
    {
        $user = rex::getUser();

        if (null === $user) {
            return [];
        }

        $sections = self::getAllSections();
        $perm = $user->getComplexPerm('yform_manager_table_edit');

        if (!$perm instanceof rex_yform_manager_table_perm_edit) {
            return [];
        }

        $base = rex::getTable(DomainSettings::ADDON);

        return array_filter(
            $sections,
            static function (string $table) use ($perm, $base) {
                // A section keyed like one of the static subpages would push
                // that page out of the navigation - `settings` takes the very
                // page it could be deleted from with it. Filtered here rather
                // than in getAllSections(), so such a table stays readable,
                // listable and above all deletable.
                if ($table !== $base && in_array(self::sectionSlug($table), self::RESERVED_SLUGS, true)) {
                    return false;
                }

                return $perm->hasPerm($table);
            },
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * URL-safe key for a section table, used as its backend page key.
     *
     * The main table has no suffix to strip, so it gets a fixed key.
     */
    public static function sectionSlug(string $table): string
    {
        $base = rex::getTable(DomainSettings::ADDON);

        return $table === $base ? 'main' : substr($table, strlen($base) + 1);
    }

    /** Resolves a page key back to its section table, or null if unknown. */
    public static function sectionBySlug(string $slug): ?string
    {
        foreach (array_keys(self::getAllSections()) as $table) {
            if (self::sectionSlug($table) === $slug) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Creates a new section table and registers it with YForm.
     *
     * Returns the table name, or null when the label yields no usable suffix,
     * hits a reserved key, or the table already exists.
     */
    public static function createSection(string $label): ?string
    {
        $label = trim($label);
        // normalize() trims the replacement character itself.
        $slug = rex_string::normalize($label, '_');

        if ('' === $label || '' === $slug || in_array($slug, self::RESERVED_SLUGS, true)) {
            return null;
        }

        $table = rex::getTable(DomainSettings::ADDON) . '_' . $slug;

        if (null !== rex_yform_manager_table::get($table)) {
            return null;
        }

        rex_sql_table::get($table)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('domain_id', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new rex_sql_column('clang_id', 'int(10) unsigned', false, '0'))
            ->ensureIndex(new rex_sql_index('domain_clang', ['domain_id', 'clang_id'], rex_sql_index::UNIQUE))
            ->ensure();

        rex_yform_manager_table_api::setTable([
            'table_name' => $table,
            'name' => $label,
            'description' => rex_i18n::msg('domain_settings_table_description'),
            'hidden' => 1,
            'schema_overwrite' => 0,
            'export' => 0,
            'import' => 0,
            'search' => 0,
            'mass_deletion' => 0,
            'mass_edit' => 0,
            'history' => 0,
        ]);
        // setTable() clears YForm's cache itself; resetCaches() is ours.
        self::resetCaches();

        return $table;
    }

    /**
     * Whether a section may be deleted.
     *
     * The main table stays: the addon installs it, the editing page falls back
     * to it, and dropping it would leave the addon without any storage at all.
     */
    public static function isSectionDeletable(string $table): bool
    {
        return $table !== rex::getTable(DomainSettings::ADDON)
            && array_key_exists($table, self::getAllSections());
    }

    /**
     * Deletes a section: field definitions, YForm registration and the table.
     *
     * The name is checked against the known sections first, and that check is
     * the whole safety net - the table name arrives from a request, and
     * dropping whatever it names would happily take rex_article with it.
     *
     * Permissions already granted on the table stay behind in the roles as
     * dead entries. Harmless (nothing resolves them any more) and the same
     * thing happens when a table is deleted in the YForm table manager.
     */
    public static function deleteSection(string $table): bool
    {
        if ('' === $table || !self::isSectionDeletable($table)) {
            return false;
        }

        // removeTable() clears YForm's cache itself.
        rex_yform_manager_table_api::removeTable($table);

        // Through rex_sql_table rather than a raw DROP: it also resets the
        // instance pool, so a later ensure() on the same name would create the
        // table instead of trying to alter one it still believes exists.
        rex_sql_table::get($table)->drop();

        self::resetCaches();
        DomainSettings::deleteCache();

        return true;
    }

    /**
     * Copies all values of one domain/language pair onto another.
     *
     * Overwrites the target: this is meant for setting up a new domain from an
     * existing one, where anything already there is a leftover. Only sections
     * the user may edit are touched, and source and target are validated by
     * the caller against what the user may see.
     *
     * @param string|null $onlyTable limit to one section, null for all of them
     *
     * @return int number of sections copied
     */
    public static function copyValues(
        int $fromDomainId,
        int $fromClangId,
        int $toDomainId,
        int $toClangId,
        ?string $onlyTable = null,
    ): int {
        if ($fromDomainId === $toDomainId && $fromClangId === $toClangId) {
            return 0;
        }

        $sections = self::getSections();
        if (null !== $onlyTable) {
            $sections = array_intersect_key($sections, [$onlyTable => true]);
        }

        $copied = 0;

        foreach (array_keys($sections) as $table) {
            $source = rex_yform_manager_dataset::query($table)
                ->where('domain_id', $fromDomainId)
                ->where('clang_id', $fromClangId)
                ->findOne();

            if (null === $source) {
                continue;
            }

            $values = $source->getData();
            unset($values['id'], $values['domain_id'], $values['clang_id']);

            if ([] === $values) {
                continue;
            }

            // getDataset() creates the target row when it does not exist yet,
            // so there is always something to write to.
            $target = self::getDataset($table, ['domain_id' => $toDomainId, 'clang_id' => $toClangId]);

            foreach ($values as $column => $value) {
                $target->setValue($column, $value);
            }

            if ($target->save()) {
                ++$copied;
            }
        }

        // No deleteCache() here: saving a dataset fires YFORM_DATA_UPDATED,
        // which the boot hook already listens to.
        return $copied;
    }

    /**
     * All field names, without layout elements.
     *
     * @param string|null $onlyTable limit to one section, null for all of them
     *
     * @return list<string>
     */
    public static function getFieldNames(?string $onlyTable = null): array
    {
        $names = [];
        $tables = null === $onlyTable ? array_keys(self::getAllSections()) : [$onlyTable];

        foreach ($tables as $tableName) {
            $table = rex_yform_manager_table::get($tableName);
            if (null === $table) {
                continue;
            }

            foreach ($table->getValueFields() as $field) {
                // Layout elements hold no value, so completing them would be
                // misleading.
                if (in_array($field->getTypeName(), ['fieldset', 'html'], true)) {
                    continue;
                }
                $names[$field->getName()] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Writes the .phpstorm.meta.php listing every field name.
     *
     * Kept here rather than in the command so the cache hooks can refresh it
     * too - an IDE helper that goes stale is worse than none.
     *
     * @return int number of names written, or -1 when the file could not be written
     */
    public static function writeIdeHelper(): int
    {
        $keys = self::getFieldNames();

        if ([] === $keys) {
            return 0;
        }

        $list = implode(', ', array_map(static fn (string $key) => "'" . $key . "'", $keys));

        $meta = "<?php\n\nnamespace PHPSTORM_META;\n\n"
            . "// Generated by the yrewrite_domain_settings addon - do not edit by hand.\n"
            . "// Refreshed on cache:clear, or via \"console domain-settings:ide-helper\".\n\n"
            . "registerArgumentsSet('domain_settings_keys', " . $list . ");\n"
            . "expectedArguments(\\FriendsOfRedaxo\\DomainSettings\\DomainSettings::get(), 0, argumentsSet('domain_settings_keys'));\n";

        return rex_file::put(rex_path::addon(DomainSettings::ADDON, '.phpstorm.meta.php'), $meta)
            ? count($keys)
            : -1;
    }

    /**
     * Renames a section.
     *
     * Only the label changes; the table keeps its name. That is deliberate:
     * YForm's table permission stores table names in the roles, so renaming
     * the table would silently drop every assignment - and the page key, which
     * is derived from the table name, would change too.
     */
    public static function renameSection(string $table, string $label): bool
    {
        $label = trim($label);

        if ('' === $label || !array_key_exists($table, self::getAllSections())) {
            return false;
        }

        rex_yform_manager_table_api::setTable([
            'table_name' => $table,
            'name' => $label,
        ]);
        self::resetCaches();

        return true;
    }

    /**
     * Languages the current user may edit, optionally limited to one domain.
     *
     * Two filters apply. The core's `clang` complex permission - the same one
     * the language selection in the user profile writes to, so there is
     * nothing to configure twice. And, when a domain is given, the languages
     * that domain serves in yrewrite: with two languages on one domain and
     * three on another, only the ones actually delivered should be offered.
     *
     * @return list<rex_clang>
     */
    public static function getEditableClangs(?int $domainId = null): array
    {
        $user = rex::getUser();

        if (null === $user) {
            return [];
        }

        // getComplexPerm() resolves 'clang' to rex_clang_perm, which the core
        // registers itself - no null check needed, unlike the two above where
        // the class comes from this addon and from YForm.
        $perm = $user->getComplexPerm('clang');
        $available = null === $domainId ? [] : self::getDomainClangIds($domainId);
        $clangs = [];

        foreach (rex_clang::getAll() as $clang) {
            if ([] !== $available && !in_array($clang->getId(), $available, true)) {
                continue;
            }

            if ($perm->hasPerm($clang->getId())) {
                $clangs[] = $clang;
            }
        }

        return $clangs;
    }

    /**
     * Ids of the languages the current user may edit on a domain.
     *
     * The id list is what callers actually compare against; having it here
     * keeps the same array_map from being written out in three places.
     *
     * @return list<int>
     */
    public static function getEditableClangIds(?int $domainId = null): array
    {
        return array_map(
            static fn (rex_clang $clang) => $clang->getId(),
            self::getEditableClangs($domainId),
        );
    }

    /**
     * Returns the dataset for the given key, creating the row if needed.
     *
     * The row has to exist before the form runs: domain_id and clang_id are
     * plain columns rather than YForm fields, so YForm's db action would not
     * write them and a freshly inserted row would land on domain 0.
     *
     * @param array<string, int> $keys
     */
    public static function getDataset(string $table, array $keys): rex_yform_manager_dataset
    {
        $query = rex_yform_manager_dataset::query($table);
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }

        $dataset = $query->findOne();

        if (null !== $dataset) {
            return $dataset;
        }

        $insert = rex_sql::factory();
        $insert->setTable($table);
        foreach ($keys as $column => $value) {
            $insert->setValue($column, $value);
        }
        $insert->insert();
        $id = (int) $insert->getLastId();

        DomainSettings::deleteCache();

        // The new row is not necessarily empty, so the cache has to go. YForm
        // gives columns a real database default through the `preDefault` hook
        // (yform/lib/manager/table/api.php), and two shipped value types use
        // it: `text` passes the default configured in the table manager
        // through, and `checkbox` always returns '0' or '1'. Verified against
        // a generated table: a fresh row comes out as flag='0', txt='vorgabe'
        // - and '0' is deliberately not empty here, that is how an unchecked
        // checkbox is stored.
        //
        // This costs one rebuild per combination of section, domain and
        // language, once, when it is first opened - not per page view: every
        // later visit finds the row above and returns before reaching this.
        $dataset = rex_yform_manager_dataset::get($id, $table);

        if (null === $dataset) {
            throw new RuntimeException('domain_settings: could not load dataset ' . $id . ' from ' . $table);
        }

        return $dataset;
    }

    /**
     * Renders one editing form.
     *
     * Each form gets its own form_name: YForm namespaces its fields as
     * FORM[<form_name>] (see rex_yform::getFieldName()), so two forms can sit
     * on the same page without their values colliding. The CSRF token is
     * derived from that name as well, so both forms are protected separately -
     * YForm adds the field itself in executeFields().
     *
     * @param array<string, int|string> $queryParams
     */
    public static function renderForm(
        rex_yform_manager_dataset $dataset,
        string $formName,
        array $queryParams,
        bool &$saved = false,
    ): string {
        $yform = $dataset->getForm();
        $yform->setObjectparams('form_name', $formName);
        // Anchor for the collapsible-groups asset, see assets/domain_settings.js.
        $yform->setObjectparams('form_class', 'rex-yform domain-settings-form');
        $yform->setObjectparams('getdata', true);
        $yform->setObjectparams('form_showformafterupdate', 1);

        // Into the action URL, not into hidden fields: YForm posts to plain
        // "index.php", so without these the request carries no page, domain or
        // language at all and the page would fall back to its defaults - which
        // meant a save in one language overwrote the fallback language.
        $yform->setObjectparams('form_action_query_params', $queryParams);

        $yform->setValueField('submit', [
            'name' => 'submit',
            'labels' => rex_i18n::msg('domain_settings_save'),
            'no_db' => true,
            'css_classes' => 'btn-save',
        ]);

        $form = $dataset->executeForm($yform);

        // YForm reports a completed save through this objparam - the same
        // signal its own manager uses to decide whether to show a message.
        $saved = (bool) $yform->getObjectparams('actions_executed');
        $message = $saved
            ? rex_view::success(rex_i18n::msg('domain_settings_saved'))
            : '';

        return $message . $form;
    }

    /**
     * Labels of fields that this language currently takes from the fallback.
     *
     * Only fields that are empty here but filled there - so the hint names
     * exactly what an editor would otherwise read as missing content.
     *
     * @return list<string>
     */
    public static function getInheritedKeys(string $table, int $domainId, int $clangId): array
    {
        $fallbackClangId = DomainSettings::getFallbackClangId($domainId);
        if ($fallbackClangId === $clangId) {
            return [];
        }

        // Do not describe a language the user has no permission for, not even
        // by naming which of its fields are filled.
        if (!in_array($fallbackClangId, self::getEditableClangIds($domainId), true)) {
            return [];
        }

        $managerTable = rex_yform_manager_table::get($table);
        if (null === $managerTable) {
            return [];
        }

        // Both languages in one query - the builder turns the array into IN().
        $byClang = DomainSettings::rowsByClang($table, $domainId, [$clangId, $fallbackClangId]);

        $current = $byClang[$clangId] ?? [];
        $fallback = $byClang[$fallbackClangId] ?? [];

        $labels = [];
        foreach ($managerTable->getValueFields() as $field) {
            $name = (string) $field->getName();
            $isEmptyHere = !isset($current[$name]) || '' === $current[$name];
            $isFilledThere = isset($fallback[$name]) && '' !== $fallback[$name];

            if ($isEmptyHere && $isFilledThere) {
                $labels[] = (string) $field->getLabel() ?: $name;
            }
        }

        return $labels;
    }

    /**
     * Whether the admin has defined any content fields for a table yet.
     *
     * A table that only carries its structural columns would render as an
     * empty form with a save button, which just looks broken.
     */
    public static function hasFields(string $table): bool
    {
        $managerTable = rex_yform_manager_table::get($table);

        return null !== $managerTable && [] !== $managerTable->getValueFields();
    }

    /**
     * Link to the field definitions of a table in the YForm table manager.
     *
     * HTML-escaped, because both callers put it straight into markup. If it
     * ever has to go into a Location header, pass false as the third argument
     * of rex_url::backendPage() at that call site instead.
     */
    public static function getFieldsUrl(string $table): string
    {
        return rex_url::backendPage('yform/manager/table_field', [
            'table_name' => $table,
        ]);
    }
}
