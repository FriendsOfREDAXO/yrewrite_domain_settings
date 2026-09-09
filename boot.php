<?php

/**
 * yrewrite_domain_settings addon.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\RoutePackage\Values;

// Registered in the GENERAL group, which is what the language key
// perm_general_yrewrite_domain_settings[] expects - rex_perm looks the label
// up as 'perm_' . group . '_' . perm (core/lib/login/perm.php).
$addon = $this;

rex_perm::register('yrewrite_domain_settings[]');
rex_complex_perm::register('yrewrite_domains', rex_yrewrite_domains_perm::class);

// Values can be changed through this addon's page or directly in the YForm
// table manager, so the cache is dropped from YForm's own events rather than
// from the editing page.
rex_extension::register(
    ['YFORM_DATA_ADDED', 'YFORM_DATA_UPDATED', 'YFORM_DATA_DELETED'],
    static function (rex_extension_point $ep): void {
        $changed = $ep->getParam('table');
        if (!$changed instanceof rex_yform_manager_table) {
            return;
        }

        // Cheap prefix test first: this fires for every YForm table in the
        // installation, and only tables named like ours can ever be sections.
        if (!str_starts_with($changed->getTableName(), rex::getTable(DomainSettings::ADDON))) {
            return;
        }

        if (array_key_exists($changed->getTableName(), Backend::getAllSections())) {
            DomainSettings::deleteCache();
        }
    },
);

rex_extension::register('CLANG_ADDED', static function (): void {
    DomainSettings::deleteCache();
});

rex_extension::register('CACHE_DELETED', static function (): void {
    DomainSettings::deleteCache();
    Backend::resetCaches();

    // Keep the IDE helper in step with the fields. There is no extension point
    // for schema changes in YForm, but clearing the cache is what one does
    // after changing fields anyway - so this is the closest thing to
    // automatic. Only while developing: on a production server nobody reads
    // the file, and CACHE_DELETED also fires on setup, backup import and core
    // update. `console domain-settings:ide-helper` writes it on demand.
    if (rex::isDebugMode()) {
        Backend::writeIdeHelper();
    }
});

// A deleted language leaves rows behind that nothing can reach any more.
rex_extension::register('CLANG_DELETED', static function (rex_extension_point $ep): void {
    // Not rex_type::int(): that one throws, and this point fires *after* the
    // language is gone - an exception here would leave behind exactly the rows
    // this handler exists to remove.
    $clangIdParam = $ep->getParam('id');

    if (!is_scalar($clangIdParam)) {
        return;
    }

    $clangId = (int) $clangIdParam;

    $sql = rex_sql::factory();

    foreach (array_keys(Backend::getAllSections()) as $table) {
        $sql->setQuery(
            'DELETE FROM ' . $sql->escapeIdentifier($table) . ' WHERE clang_id = :clang',
            ['clang' => $clangId],
        );
    }

    DomainSettings::deleteCache();
});

// Keep the media pool from silently dropping a logo that is still in use.
rex_extension::register('MEDIA_IS_IN_USE', static function (rex_extension_point $ep) {
    $warnings = (array) $ep->getSubject();
    $filenameParam = $ep->getParam('filename');
    $filename = is_scalar($filenameParam) ? (string) $filenameParam : '';

    if (DomainSettings::isMediaInUse($filename)) {
        $warnings[] = rex_i18n::msg('domain_settings_media_in_use');
    }

    return $warnings;
});

// Collapsible field groups on the editing page. Loaded for the whole backend
// like YForm and yrewrite_metainfo do it; the CSS is scoped to .domain-settings-form,
// so no other form is affected. The version is appended so a changed asset is
// not served from the browser cache after an update.
if (rex::isBackend() && rex::getUser()) {
    $version = '?v=' . $this->getVersion();
    rex_view::addCssFile($this->getAssetsUrl('domain_settings.css') . $version);
    rex_view::addJsFile($this->getAssetsUrl('domain_settings.js') . $version);
}

// Sections become real backend tabs rather than a second row of tabs inside
// the page. They are added in front of the statically defined subpages so the
// content sits left and settings/help stay on the right.
if (rex::isBackend()) {
    rex_extension::register('PAGES_PREPARED', static function () use ($addon): void {
        $user = rex::getUser();
        if (!$user instanceof rex_user) {
            return;
        }

        $page = rex_be_controller::getPageObject('yrewrite_domain_settings');
        if (null === $page) {
            return;
        }

        $activeSlug = (string) rex_be_controller::getCurrentPagePart(2);
        $subPath = $addon->getPath('pages/values.php');

        $sectionPages = [];
        foreach (Backend::getSections() as $table => $label) {
            $slug = Backend::sectionSlug($table);
            $sectionPages[$slug] = (new rex_be_page($slug, $label))
                ->setSubPath($subPath)
                ->setIsActive($slug === $activeSlug);
        }

        $page->setSubpages($sectionPages + $page->getSubpages());
    });
}

// Expose the values over the api addon when it is installed. Guarded rather
// than declared as a dependency: the addon works without it, and because this
// runs on every request the routes appear as soon as api is installed - no
// reinstall of this addon needed.
if (rex_addon::get('api')->isAvailable()) {
    RouteCollection::registerRoutePackage(
        new Values(),
    );
}
