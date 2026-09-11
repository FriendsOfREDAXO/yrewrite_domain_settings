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
rex_perm::register('yrewrite_domain_settings[]');
rex_complex_perm::register('yrewrite_domains', rex_yrewrite_domains_perm::class);

// Values can be changed through this addon's page or directly in the YForm
// table manager, so what this request has already read is dropped from
// YForm's own events rather than from the editing page. Nothing is written to
// disk any more - this only keeps a save and a read in the same request from
// disagreeing.
rex_extension::register(
    ['YFORM_DATA_ADDED', 'YFORM_DATA_UPDATED', 'YFORM_DATA_DELETED'],
    static function (rex_extension_point $ep): void {
        $changed = $ep->getParam('table');
        if (!$changed instanceof rex_yform_manager_table) {
            return;
        }

        // A prefix test is enough now: this fires for every YForm table in the
        // installation, only tables named like ours can ever be tabs, and all
        // that follows is emptying an array. Looking the table up in
        // getAllSections() would cost more than it saves.
        if (!str_starts_with($changed->getTableName(), rex::getTable(DomainSettings::ADDON))) {
            return;
        }

        DomainSettings::deleteCache();
    },
);

rex_extension::register('CLANG_ADDED', static function (): void {
    DomainSettings::deleteCache();
});

rex_extension::register('CACHE_DELETED', static function (): void {
    // Nothing of ours survives a request any more, so this is about the
    // section list Backend holds - and about the IDE helper below.
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

// Old tab URLs keep working. Registered rather than caught: the controller
// sends an unknown page to the start page before any of our code runs.
if (rex::isBackend()) {
    rex_extension::register('PAGES_PREPARED', static function (): void {
        $page = rex_be_controller::getPageObject('yrewrite_domain_settings');

        if (null === $page) {
            return;
        }

        $subpages = $page->getSubpages();
        $redirects = [];
        $subPath = rex_addon::get('yrewrite_domain_settings')->getPath('pages/redirect.php');

        foreach (Backend::getSections() as $table => $label) {
            $slug = Backend::sectionSlug($table);

            // Never shadow one of the real pages - a tab called "data" would
            // otherwise take the editing page with it.
            if (isset($subpages[$slug])) {
                continue;
            }

            $redirects[$slug] = (new rex_be_page($slug, $label))
                ->setSubPath($subPath)
                ->setHidden(true);
        }

        if ([] !== $redirects) {
            $page->setSubpages($subpages + $redirects);
        }
    });
}

// Two things on YForm's field page: a way back into this addon, and the notice
// that the guard below turned a request away.
//
// The way back, because "edit fields" leads out of this addon and YForm has no
// idea where the visitor came from. Through getSections(), so it only appears
// for someone who may edit that tab.
if (rex::isBackend()) {
    rex_extension::register('PAGE_TITLE_SHOWN', static function (rex_extension_point $ep) {
        if ('yform/manager/table_field' !== rex_be_controller::getCurrentPage()) {
            return null;
        }

        $table = rex_request('table_name', 'string', '');
        $subject = $ep->getSubject();
        $out = is_string($subject) ? $subject : '';

        // Said out loud rather than swallowed: a button that silently does
        // nothing reads as a broken backend, not as a protected table.
        if (1 === rex_request('domain_settings_protected', 'int', 0)
            && array_key_exists($table, Backend::getAllSections())
        ) {
            $out .= rex_view::warning(rex_i18n::msg('domain_settings_structure_protected'));
        }

        if (!array_key_exists($table, Backend::getSections())) {
            return '' === $out ? null : $out;
        }

        return $out . '<p><a class="btn btn-default" href="'
            . rex_url::backendPage('yrewrite_domain_settings/data', ['section' => Backend::sectionSlug($table)])
            . '"><i class="rex-icon fa-arrow-left"></i> '
            . rex_i18n::msg('domain_settings_back_to_values')
            . '</a></p>';
    });
}

// "Update table with field deletion" drops every column that has no field, id
// aside (yform, lib/manager/table/api.php, generateTableAndFields() with
// $delete_old). On these tables that is domain_id and clang_id - the columns
// that decide which row is read and written - so one click would take every
// stored value with it.
//
// Stopped before YForm sees the request: getFieldPage() carries the action out
// while building the page (lib/manager/manager.php), behind nothing but a CSRF
// token, and that token is on every other link of the same page. Hiding the
// button is therefore housekeeping, not protection - by the time an
// OUTPUT_FILTER runs, the columns are gone. PAGE_CHECKED is the last point
// that still runs before the page is included (core/backend.php).
if (rex::isBackend()) {
    rex_extension::register('PAGE_CHECKED', static function (rex_extension_point $ep): void {
        if ('yform/manager/table_field' !== $ep->getSubject()
            || 'updatetablewithdelete' !== rex_request('func', 'string', '')
        ) {
            return;
        }

        $table = rex_request('table_name', 'string', '');

        if (!array_key_exists($table, Backend::getAllSections())) {
            return;
        }

        // Back to the same table without the func, so a reload cannot repeat
        // it. The page needs nothing else (yform, pages/manager.table_field.php).
        rex_response::sendRedirect(rex_url::backendPage('yform/manager/table_field', [
            'table_name' => $table,
            'domain_settings_protected' => 1,
        ]));
    });
}

// The same two columns from the other side: YForm offers to turn every column
// without a field into one. A form supplying domain_id or clang_id would let a
// crafted post write into another domain, so the offer is taken off the page
// on our tables. Cosmetic by nature - the guard above is what protects the
// data - but it keeps the trap out of sight.
if (rex::isBackend()) {
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep) {
        if ('yform/manager/table_field' !== rex_be_controller::getCurrentPage()) {
            return null;
        }

        $table = rex_request('table_name', 'string', '');

        if (!array_key_exists($table, Backend::getAllSections())) {
            return null;
        }

        // Someone is editing the fields of one of our tabs, so this is the
        // moment the field list can have changed. YForm fires no extension
        // point for that, and CACHE_DELETED only comes around when someone
        // clears the cache by hand - which is one step too many right after
        // adding a field. Only while developing, like the CACHE_DELETED
        // handler above: nobody reads the file on a production server.
        if (rex::isDebugMode()) {
            Backend::writeIdeHelper();
        }

        $subject = $ep->getSubject();

        if (!is_string($subject)) {
            return null;
        }

        $before = $subject;

        // Keyed to the links the box is made of rather than to its heading:
        // that heading is a hardcoded German sentence in YForm
        // (lib/manager/manager.php), so matching it would survive a
        // translation but not a rewording. func=choosenadd carries
        // type_real_field only here - the "add field" icon of the field list
        // uses the same func without it.
        $subject = preg_replace(
            '#<section[^>]*class="[^"]*rex-page-section[^"]*"[^>]*>'
            . '(?:(?!</section>).)*?func=choosenadd&(?:amp;)?type_real_field=.*?</section>#s',
            '',
            $subject,
            1,
        ) ?? $subject;

        // And the button next to it, which is what the PAGE_CHECKED guard
        // above turns away when someone reaches for the URL anyway.
        $subject = preg_replace(
            '#<a[^>]+func=updatetablewithdelete[^>]*>.*?</a>#s',
            '',
            $subject,
        ) ?? $subject;

        return $subject === $before ? null : $subject;
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
