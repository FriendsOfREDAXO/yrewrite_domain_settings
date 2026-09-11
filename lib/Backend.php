<?php

namespace FriendsOfRedaxo\DomainSettings;

use rex;
use rex_addon;
use rex_be_controller;
use rex_clang;
use rex_config;
use rex_file;
use rex_fragment;
use rex_i18n;
use rex_logger;
use rex_path;
use rex_request;
use rex_select;
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
use function rex_escape;
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
    private const RESERVED_SLUGS = ['main', 'data', 'settings', 'migration', 'help'];
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
     * Section tables already reported as colliding, so the warning is written
     * once per process rather than once per backend request.
     *
     * @var array<string, true>
     */
    private static array $loggedSlugCollisions = [];

    /**
     * The domain being edited in this request.
     *
     * Resolved once: the navigation, the switcher and the form below it all
     * ask for it, and they have to agree - otherwise the tabs would point at a
     * different domain than the form writes to.
     */
    private static ?int $activeDomainId = null;

    /**
     * The language being edited, per domain.
     *
     * Keyed by domain because yrewrite decides per domain which languages it
     * serves: the same session value can be valid on one and not on the next.
     *
     * @var array<int, int>
     */
    private static array $activeClangIds = [];

    /** Config key holding the section-to-domain assignment. */
    private const CONFIG_SECTION_DOMAINS = 'section_domains';

    /** Session keys carrying the editing context from one page to the next. */
    private const SESSION_DOMAIN = 'domain_settings_domain_id';
    private const SESSION_CLANG = 'domain_settings_clang_id';

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

            // A table named exactly like the base plus an underscore passes
            // the prefix test and leaves nothing behind as a key.
            if ('' === $slug) {
                continue;
            }

            if ($name !== $base && isset($slugs[$slug])) {
                // Once per process, not once per request: this runs on every
                // backend page through PAGES_PREPARED, and a permanent
                // collision would otherwise grow the log by a line per view.
                if (!isset(self::$loggedSlugCollisions[$name])) {
                    self::$loggedSlugCollisions[$name] = true;
                    rex_logger::factory()->warning(
                        'domain_settings: section {table} is ignored, another section already uses the key {slug}',
                        ['table' => $name, 'slug' => $slug],
                    );
                }

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
    public static function getSections(?int $domainId = null): array
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
            static function (string $table) use ($perm, $base, $domainId) {
                // A section keyed like one of the static subpages would push
                // that page out of the navigation - `settings` takes the very
                // page it could be deleted from with it. Filtered here rather
                // than in getAllSections(), so such a table stays readable,
                // listable and above all deletable.
                if ($table !== $base && in_array(self::sectionSlug($table), self::RESERVED_SLUGS, true)) {
                    return false;
                }

                if (!$perm->hasPerm($table)) {
                    return false;
                }

                // The domain assignment narrows what is offered, it never
                // widens it: the permission above has the last word either way.
                return null === $domainId || self::isSectionVisibleForDomain($table, $domainId);
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

        // Drop the domain assignment with it, otherwise a section created
        // under the same name later would inherit a limit nobody set.
        $assignment = self::getSectionDomains();
        if (array_key_exists($table, $assignment)) {
            unset($assignment[$table]);
            rex_config::set(DomainSettings::ADDON, self::CONFIG_SECTION_DOMAINS, $assignment);
        }

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
     * Sections that are not offered on both domains are skipped - the return
     * value counts what was actually copied, so the caller can say so.
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

        // Both sides of the copy, not just the editable set: a section that
        // is not assigned to the target domain would be written there and
        // never delivered, and one not assigned to the source has nothing to
        // give in the first place.
        $sections = array_intersect_key(
            self::getSections($fromDomainId),
            self::getSections($toDomainId),
        );

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
     * Field names that more than one tab uses on the same domain.
     *
     * The key namespace is shared, so the same name in two tabs resolves to
     * whichever table is read first. Only a real clash counts: tabs assigned
     * to different domains never answer for the same domain, so they may well
     * carry the same name.
     *
     * Used to be reported from the value cache while it was built; without
     * that cache the check belongs where tabs are managed and to the self
     * test, not into every request.
     *
     * @return list<string>
     */
    public static function getDuplicateFieldNames(): array
    {
        $seen = [];
        $duplicates = [];

        foreach (array_keys(self::getAllSections()) as $table) {
            foreach (self::getFieldNames($table) as $name) {
                if (isset($seen[$name])
                    && $seen[$name] !== $table
                    && self::sectionsShareDomain($seen[$name], $table)
                ) {
                    $duplicates[$name] = true;
                }

                $seen[$name] = $table;
            }
        }

        $names = array_keys($duplicates);
        sort($names);

        return $names;
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

            $columns = $table->getColumns();

            foreach ($table->getValueFields() as $field) {
                // Asked of the table, not of a list of type names: layout
                // elements hold no value, and neither does a 1-n relation -
                // that one lives in the other table and never becomes a column
                // here. Completing either would promise a value that get()
                // can never answer with.
                if (!array_key_exists($field->getName(), $columns)) {
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
        string $extraButtons = '',
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

        // Rendered by YForm rather than appended afterwards, so it sits in the
        // same row as the save button instead of below the form. Every html
        // field needs a name of its own.
        if ('' !== $extraButtons) {
            $yform->setValueField('html', [
                'name' => 'domain_settings_extra_buttons',
                'html' => '<span class="domain-settings-form-actions">' . $extraButtons . '</span>',
            ]);
        }

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

    /**
     * The domain currently being edited.
     *
     * Request wins over session, session over the first domain the user may
     * edit. Keeping it in the session is what makes the domain a context
     * rather than a form field: it survives leaving the page and coming back,
     * which a query parameter on its own does not.
     *
     * Returns -1 when the user may edit no domain at all. Not 0 - that is a
     * real domain id (yrewrite's implicit default), so falling back to it
     * would hand out write access instead of denying it.
     */
    public static function getActiveDomainId(): int
    {
        if (null !== self::$activeDomainId) {
            return self::$activeDomainId;
        }

        $domains = self::getDomains();

        if ([] === $domains) {
            return self::$activeDomainId = -1;
        }

        $requested = self::requested('domain_id');
        if (isset($domains[$requested])) {
            self::remember(self::SESSION_DOMAIN, $requested);

            return self::$activeDomainId = $requested;
        }

        $remembered = self::recall(self::SESSION_DOMAIN);
        if (isset($domains[$remembered])) {
            return self::$activeDomainId = $remembered;
        }

        return self::$activeDomainId = (int) array_key_first($domains);
    }

    /**
     * The language currently being edited on the given domain.
     *
     * Resolved like the domain, but against the languages that domain serves -
     * a language remembered from another domain simply does not apply here and
     * gives way to the fallback language.
     *
     * Returns -1 when the user may edit no language of this domain.
     */
    public static function getActiveClangId(int $domainId): int
    {
        if (isset(self::$activeClangIds[$domainId])) {
            return self::$activeClangIds[$domainId];
        }

        $clangIds = array_map(
            static fn (rex_clang $clang) => $clang->getId(),
            self::getEditableClangs($domainId),
        );

        if ([] === $clangIds) {
            return self::$activeClangIds[$domainId] = -1;
        }

        $requested = self::requested('clang_id');
        if (in_array($requested, $clangIds, true)) {
            self::remember(self::SESSION_CLANG, $requested);

            return self::$activeClangIds[$domainId] = $requested;
        }

        $remembered = self::recall(self::SESSION_CLANG);
        if (in_array($remembered, $clangIds, true)) {
            return self::$activeClangIds[$domainId] = $remembered;
        }

        $fallback = DomainSettings::getFallbackClangId($domainId);

        return self::$activeClangIds[$domainId] = in_array($fallback, $clangIds, true)
            ? $fallback
            : $clangIds[0];
    }

    /**
     * Reads an int from the query string, falling back to the posted form.
     *
     * Deliberately not rex_request::request(): that reads $_REQUEST, which
     * also contains the cookies when request_order is empty in the php.ini. A
     * cookie named domain_id would then quietly override the editing context
     * on every single request.
     */
    private static function requested(string $key): int
    {
        $value = rex_request::get($key, 'int', -1);

        return -1 === $value ? rex_request::post($key, 'int', -1) : $value;
    }

    /**
     * Reads the editing context back from the session, if there is one.
     *
     * rex_request::session() throws without an active session, and the console
     * commands reach the same helpers - see remember().
     */
    private static function recall(string $key): int
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return -1;
        }

        return (int) rex_request::session($key, 'int', -1);
    }

    /**
     * Writes the editing context to the session, if there is one.
     *
     * rex_request::setSession() throws without an active session. That never
     * happens in the backend, but the console commands touch the same helpers
     * and should not blow up over a stored preference.
     */
    private static function remember(string $key, int $value): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return;
        }

        rex_request::setSession($key, $value);
    }

    /**
     * The domain switcher at the top of the editing page.
     *
     * Deliberately not a panel: this is the context everything below it is
     * edited in, not a setting of its own. With a single domain there is
     * nothing to switch, so it renders nothing at all.
     */
    public static function renderDomainSwitch(): string
    {
        $domains = self::getDomains();

        if (count($domains) < 2) {
            return '';
        }

        $select = new rex_select();
        $select->setId('domain-settings-domain');
        $select->setName('domain_id');
        // selectpicker: the backend's own dressed-up select, the same one the
        // domain assignment uses in the settings. Falls back to a plain select
        // without JavaScript.
        $select->setAttribute('class', 'form-control selectpicker');
        $select->setAttribute('data-width', '100%');

        if (count($domains) >= 10) {
            $select->setAttribute('data-live-search', 'true');
        }

        // No inline onchange: form.submit() fires no submit event and would
        // leave the page before the unsaved-changes dialog gets a say. The
        // script below takes it from here; without JavaScript the noscript
        // button carries the switch.
        $select->setAttribute('data-domain-settings-switch', '1');
        $select->setSelected(self::getActiveDomainId());
        $select->addArrayOptions($domains);

        // A GET form on the current page: switching the domain is a navigation
        // step, so it belongs in the URL and in the browser history.
        return '<form action="' . rex_url::currentBackendPage() . '" method="get">'
            . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
            // Stay in the same section across the switch where the new domain
            // has it; the page falls back to its first section where it does not.
            . '<input type="hidden" name="section" value="'
            . rex_escape(rex_request::get('section', 'string', '')) . '">'
            . '<div class="form-group">'
            . '<label for="domain-settings-domain">' . rex_i18n::msg('domain_settings_domain') . '</label> '
            . $select->get()
            . '</div>'
            . '<noscript><button class="btn btn-default" type="submit">'
            . rex_i18n::msg('domain_settings_domain_switch') . '</button></noscript>'
            . '</form>';
    }

    /**
     * The language switch, built exactly like the one on the structure page.
     *
     * rex_view::clangSwitchAsButtons() cannot be called directly for two
     * reasons: it builds its links with the parameter `clang` while this addon
     * reads `clang_id`, and it offers every language the user may edit rather
     * than the ones this domain serves. So the items are assembled here and
     * handed to the core's own fragments - same markup, same classes, same
     * switch to a dropdown once there are too many for a button row.
     *
     * @param array<string, string> $params carried along in every link
     */
    public static function renderClangButtons(int $domainId, array $params = []): string
    {
        $clangs = self::getEditableClangs($domainId);

        if (count($clangs) < 2) {
            return '';
        }

        // The same threshold the core uses: four languages no longer fit a
        // button row.
        if (count($clangs) >= 4) {
            return self::renderClangDropdown($clangs, $domainId, $params);
        }

        $activeId = self::getActiveClangId($domainId);
        $fallbackId = DomainSettings::getFallbackClangId($domainId);

        $buttons = [];
        foreach ($clangs as $clang) {
            $id = $clang->getId();
            $name = rex_i18n::translate($clang->getName());

            // Icon inside the label, exactly as the core does it - the space
            // after the tag is part of it.
            $icon = $clang->isOnline()
                ? '<i class="rex-icon rex-icon-online"></i> '
                : '<i class="rex-icon rex-icon-offline"></i> ';

            $attributes = [
                'class' => ['btn-clang'],
                'title' => $id === $fallbackId
                    ? $name . ' (' . rex_i18n::msg('domain_settings_fallback') . ')'
                    : $name,
                // The unsaved-changes dialog hooks onto this.
                'data-clang-id' => $id,
            ];

            if ($id === $activeId) {
                $attributes['class'][] = 'active';
            }

            $buttons[] = [
                'label' => $icon . $name,
                'url' => rex_url::currentBackendPage(['clang_id' => $id] + $params),
                'attributes' => $attributes,
            ];
        }

        $fragment = new rex_fragment();
        $fragment->setVar('buttons', $buttons, false);

        return '<div class="rex-nav-btn rex-nav-language">'
            . '<div class="btn-toolbar">' . $fragment->parse('core/buttons/button_group.php') . '</div>'
            . '</div>';
    }

    /**
     * The language switch as a dropdown, for when there are too many buttons.
     *
     * @param list<rex_clang>       $clangs
     * @param array<string, string> $params
     */
    private static function renderClangDropdown(array $clangs, int $domainId, array $params): string
    {
        $activeId = self::getActiveClangId($domainId);

        $buttonLabel = '';
        $items = [];

        foreach ($clangs as $clang) {
            $id = $clang->getId();
            $name = rex_i18n::translate($clang->getName());

            $item = [
                'title' => $name,
                'href' => rex_url::currentBackendPage(['clang_id' => $id] + $params),
                'attributes' => 'data-clang-id="' . $id . '"',
            ];

            if ($id === $activeId) {
                $item['active'] = true;
                $buttonLabel = $name;
            }

            $items[] = $item;
        }

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'rex-language');
        $fragment->setVar('button_prefix', rex_i18n::msg('language'));
        $fragment->setVar('button_label', $buttonLabel);
        $fragment->setVar('header', rex_i18n::msg('clang_select'));
        $fragment->setVar('items', $items, false);

        if (rex::getUser()?->isAdmin() ?? false) {
            $fragment->setVar('footer', '<a href="' . rex_url::backendPage('system/lang') . '">'
                . '<i class="fa fa-flag"></i> ' . rex_i18n::msg('languages_edit') . '</a>', false);
        }

        return $fragment->parse('core/dropdowns/dropdown.php');
    }

    /**
     * The context strip above the sections: which domain, which language.
     *
     * Both belong together - they say what the page below is about - and both
     * disappear on their own when there is nothing to choose, so the strip is
     * only rendered when at least one of them has something to show.
     *
     * @param array<string, string> $params carried along in the language links
     */
    public static function renderContextBar(int $domainId, array $params = []): string
    {
        $domainSwitch = self::renderDomainSwitch();
        $clangButtons = self::renderClangButtons($domainId, $params);

        if ('' === $domainSwitch && '' === $clangButtons) {
            return '';
        }

        // Source order follows reading order; the flex layout pushes the
        // languages to the right edge.
        return '<div class="domain-settings-context">'
            . $domainSwitch
            . ('' === $clangButtons ? '' : '<div class="domain-settings-languages">' . $clangButtons . '</div>')
            . '</div>';
    }

    /**
     * Section to domain assignment, as table name => list of domain ids.
     *
     * A section without an entry shows up on every domain, which is both the
     * sensible reading of "nothing picked" and what keeps installations that
     * never touch the setting working exactly as before.
     *
     * @return array<string, list<int>>
     */
    public static function getSectionDomains(): array
    {
        $config = rex_config::get(DomainSettings::ADDON, self::CONFIG_SECTION_DOMAINS, []);

        if (!is_array($config)) {
            return [];
        }

        // Rebuilt rather than passed through: what comes back from the config
        // is whatever was written there once, and every caller relies on the
        // shape.
        $assignment = [];

        foreach ($config as $table => $ids) {
            if (!is_string($table) || !is_array($ids)) {
                continue;
            }

            // Written out rather than cast: what comes back from the config
            // is mixed, and anything that is not an id is better dropped than
            // turned into 0 - which is a real domain.
            $domainIds = [];

            foreach ($ids as $id) {
                if (is_int($id)) {
                    $domainIds[] = $id;
                } elseif (is_string($id) && ctype_digit($id)) {
                    $domainIds[] = (int) $id;
                }
            }

            $assignment[$table] = $domainIds;
        }

        return $assignment;
    }

    /**
     * Domains one section is limited to. An empty list means every domain.
     *
     * @return list<int>
     */
    public static function getSectionDomainIds(string $table): array
    {
        return self::getSectionDomains()[$table] ?? [];
    }

    /**
     * Limits a section to the given domains. An empty list lifts the limit.
     *
     * Reaches into the frontend: values of a domain the section is no longer
     * assigned to stop being delivered. The rows stay, so assigning the domain
     * again brings them back unchanged.
     *
     * @param array<int|string> $domainIds
     */
    public static function setSectionDomains(string $table, array $domainIds): void
    {
        // The table name arrives from a request, so it has to be one of ours
        // before it becomes a config key.
        if (!array_key_exists($table, self::getAllSections())) {
            return;
        }

        $known = self::getDomains();

        $ids = [];
        foreach ($domainIds as $id) {
            $id = (int) $id;
            if (array_key_exists($id, $known)) {
                $ids[$id] = $id;
            }
        }

        // Picking every domain is stored as picking none. Otherwise a domain
        // added later would silently hide every section that was "assigned to
        // all of them" at the time.
        if (count($ids) === count($known)) {
            $ids = [];
        }

        $config = self::getSectionDomains();

        if ([] === $ids) {
            unset($config[$table]);
        } else {
            $config[$table] = array_values($ids);
        }

        rex_config::set(DomainSettings::ADDON, self::CONFIG_SECTION_DOMAINS, $config);

        // The assignment decides what goes into the value cache, so the cache
        // is wrong the moment it changes. Nothing else drops it here: no
        // dataset was saved, so YFORM_DATA_UPDATED does not fire.
        self::resetCaches();
        DomainSettings::deleteCache();
    }

    /**
     * Whether two sections are ever offered on the same domain.
     *
     * The key namespace is shared, but only within a domain: two sections
     * assigned to different domains may well carry the same field name,
     * because only one of them ever answers for a given domain.
     */
    public static function sectionsShareDomain(string $a, string $b): bool
    {
        $idsA = self::getSectionDomainIds($a);
        $idsB = self::getSectionDomainIds($b);

        // An unassigned section is offered everywhere, so it overlaps with
        // anything.
        if ([] === $idsA || [] === $idsB) {
            return true;
        }

        return [] !== array_intersect($idsA, $idsB);
    }

    /** Whether a section is meant to be edited on the given domain. */
    public static function isSectionVisibleForDomain(string $table, int $domainId): bool
    {
        $ids = self::getSectionDomainIds($table);

        return [] === $ids || in_array($domainId, $ids, true);
    }

    /**
     * Whether a section holds any value for the given domain.
     *
     * Used before taking a domain away from a section: the rows stay behind
     * and keep answering getValue() in the frontend, so the editor should hear
     * about it rather than find out months later.
     */
    public static function sectionHasValues(string $table, int $domainId): bool
    {
        if (!array_key_exists($table, self::getAllSections())) {
            return false;
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $sql->escapeIdentifier($table) . ' WHERE domain_id = :domain',
            ['domain' => $domainId],
        );

        foreach ($rows as $row) {
            unset($row['id'], $row['domain_id'], $row['clang_id']);

            foreach ($row as $value) {
                if (null !== $value && '' !== $value) {
                    return true;
                }
            }
        }

        return false;
    }
}
