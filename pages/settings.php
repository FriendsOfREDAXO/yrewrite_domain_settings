<?php

/**
 * Settings: the tabs and the fallback language.
 *
 * A tab is an ordinary YForm table. That is not an implementation detail to
 * hide but the point of it: YForm's own table permission then governs who may
 * edit which tab, and it shows up in the role form without a line of code
 * here - which is why the panel says so out loud.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;

$csrf = rex_csrf_token::factory('domain_settings_section');

// The delete buttons live inside the rename form - a <form> inside <tr> is
// invalid markup - so a pressed delete button overrides the form's own func.
$func = '' !== rex_post('delete_section', 'string', '') ? 'delete' : rex_post('func', 'string');

if ('' !== $func) {
    // Say so rather than doing nothing: an expired token is otherwise
    // indistinguishable from a save that silently failed.
    if (!$csrf->isValid()) {
        echo rex_view::warning(rex_i18n::msg('csrf_token_invalid'));
    } elseif ('add' === $func) {
        $label = rex_post('section_label', 'string', '');
        $created = Backend::createSection($label);

        if (null === $created) {
            echo rex_view::warning(rex_i18n::msg('domain_settings_section_invalid'));
        } else {
            // Assigned right away rather than in a second step: a tab that is
            // meant for one domain should not appear on the others in between.
            Backend::setSectionDomains($created, (array) rex_post('new_section_domains', 'array', []));
            echo rex_view::success(rex_i18n::msg('domain_settings_section_added', $label));
        }
    } elseif ('delete' === $func) {
        $table = rex_post('delete_section', 'string', '');
        echo Backend::deleteSection($table)
            ? rex_view::success(rex_i18n::msg('domain_settings_section_deleted'))
            : rex_view::warning(rex_i18n::msg('domain_settings_section_not_deletable'));
    } elseif ('fallback' === $func) {
        $clangId = rex_post('fallback_clang_id', 'int', 0);

        if (rex_clang::exists($clangId)) {
            $this->setConfig('fallback_clang_id', $clangId);
            echo rex_view::success(rex_i18n::msg('domain_settings_saved'));
        } else {
            echo rex_view::warning(rex_i18n::msg('domain_settings_section_invalid'));
        }
    } elseif ('rename' === $func) {
        $failed = 0;
        /** @var array<string, string> $labels */
        $labels = (array) rex_post('section_labels', 'array', []);
        /** @var array<string, list<string>> $assigned */
        $assigned = (array) rex_post('section_domains', 'array', []);
        $allDomains = Backend::getDomains();
        $orphaned = [];

        foreach ($labels as $table => $label) {
            $table = (string) $table;

            if (!Backend::renameSection($table, (string) $label)) {
                ++$failed;
            }

            if (count($allDomains) < 2) {
                continue;
            }

            // An empty assignment means every domain, so both sides are
            // compared as the set of domains the section actually shows up on.
            $before = Backend::getSectionDomainIds($table);
            $before = [] === $before ? array_keys($allDomains) : $before;

            Backend::setSectionDomains($table, (array) ($assigned[$table] ?? []));

            $after = Backend::getSectionDomainIds($table);
            $after = [] === $after ? array_keys($allDomains) : $after;

            // Taking a domain away stops the values from being delivered
            // there. The rows stay, so it is reversible - but it changes the
            // live site, which nobody should learn about later.
            foreach (array_diff($before, $after) as $domainId) {
                if (Backend::sectionHasValues($table, (int) $domainId)) {
                    // Escaped here rather than by rawMsg(): both halves are
                    // free text an admin typed - the tab label and the domain
                    // name from yrewrite.
                    $orphaned[] = rex_escape(Backend::getAllSections()[$table])
                        . ' / ' . rex_escape((string) ($allDomains[$domainId] ?? $domainId));
                }
            }
        }

        // Tied to what actually failed, not to what was renamed: this form
        // saves the domain assignment as well, and that is written even when
        // no label changed.
        echo 0 === $failed
            ? rex_view::success(rex_i18n::msg('domain_settings_sections_saved'))
            : rex_view::warning(rex_i18n::msg('domain_settings_section_invalid'));

        if ([] !== $orphaned) {
            echo rex_view::warning(rex_i18n::rawMsg('domain_settings_section_domain_orphaned', implode(', ', $orphaned)));
        }
    }
}

// ---------------------------------------------------------------------- tabs
$allDomains = Backend::getDomains();
$hasDomains = count($allDomains) > 1;

// A grid rather than a table: a table row cannot be reflowed, and this list
// carries a text field, a multi select and two buttons - side by side they
// need more room than the panel has until the window is very wide. The
// columns stack instead: one per line on a phone, the three fields next to
// each other from the small breakpoint on with the buttons below them, and
// everything on one line from the large one.
$colName = 'col-xs-12 col-sm-4 domain-settings-col-name';
$colDomains = 'col-xs-12 col-sm-4 domain-settings-col-domains';
$colCount = 'col-xs-12 col-sm-4 domain-settings-col-count';
$colActions = 'col-xs-12 domain-settings-col-actions';

if (!$hasDomains) {
    $colName = 'col-xs-12 col-sm-8 domain-settings-col-name';
    $colCount = 'col-xs-12 col-sm-4 domain-settings-col-count';
}

$rows = '';
foreach (Backend::getAllSections() as $table => $label) {
    $count = count(rex_yform_manager_table::get($table)?->getValueFields() ?? []);

    $domainCol = '';
    if ($hasDomains) {
        // No selection at all is stored as "every domain", so every domain is
        // ticked when nothing is configured. The editor then unticks what a
        // tab is not needed for, which reads the way it behaves.
        $selected = Backend::getSectionDomainIds($table);
        $selected = [] === $selected ? array_keys($allDomains) : $selected;

        $selectId = 'domain-settings-domains-' . Backend::sectionSlug($table);

        $select = new rex_select();
        $select->setName('section_domains[' . rex_escape($table) . '][]');
        $select->setId($selectId);
        // selectpicker turns it into the dropdown with a tick per entry that
        // YForm uses; bootstrap-select ships with the backend, so nothing has
        // to be loaded here. Without JavaScript it stays a plain multiple
        // select and still works.
        $select->setAttribute('class', 'form-control selectpicker');
        $select->setAttribute('data-selected-text-format', 'count > 1');
        $select->setAttribute('data-actions-box', 'true');
        $select->setAttribute('data-width', '100%');
        $select->setAttribute('title', rex_i18n::msg('domain_settings_section_domains_none'));
        $select->setMultiple(true);
        $select->setSelected($selected);
        $select->addArrayOptions($allDomains);

        // The heading row is gone on a phone, so the field says for itself
        // what it is - "4 Elemente ausgewählt" alone names nothing.
        $domainCol = '<div class="' . $colDomains . '">'
            . '<label class="visible-xs-block" for="' . rex_escape($selectId) . '">'
            . rex_i18n::msg('domain_settings_section_domains') . '</label>'
            . $select->get() . '</div>';
    }

    $rows .= '<div class="row domain-settings-section-row">'
        . '<div class="' . $colName . '">'
        . '<input class="form-control" type="text" name="section_labels[' . rex_escape($table) . ']"'
        . ' value="' . rex_escape($label) . '" required'
        . ' aria-label="' . rex_escape(rex_i18n::msg('domain_settings_section_label')) . '">'
        . '<small class="text-muted domain-settings-section-table">' . rex_escape($table) . '</small>'
        . '</div>'
        . $domainCol
        . '<div class="' . $colCount . '">' . rex_i18n::msg('domain_settings_field_count', $count) . '</div>'
        . '<div class="' . $colActions . '">'
        . '<div class="domain-settings-section-buttons">'
        . '<a class="btn btn-default" href="' . Backend::getFieldsUrl($table) . '">'
        . '<i class="rex-icon fa-list"></i> ' . rex_i18n::msg('domain_settings_edit_fields') . '</a>'
        . (Backend::isSectionDeletable($table)
            ? '<button class="btn btn-delete" type="submit" name="delete_section"'
                . ' value="' . rex_escape($table) . '"'
                // rawMsg, not msg: getMsg() already escapes the interpolated
                // arguments with html_simplified, so escaping the finished
                // message on top turns "Angebote & Preise" into "&amp;amp;".
                . ' data-confirm="' . rex_escape(rex_i18n::rawMsg('domain_settings_section_delete_confirm', $label)) . '">'
                . '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('domain_settings_section_delete') . '</button>'
            : '')
        . '</div>'
        . '</div>'
        . '</div>';
}

// Column headings, from the breakpoint on where there are columns to head.
$head = '<div class="row domain-settings-section-head hidden-xs">'
    . '<div class="' . $colName . '">' . rex_i18n::msg('domain_settings_section_label') . '</div>'
    . ($hasDomains ? '<div class="' . $colDomains . '">' . rex_i18n::msg('domain_settings_section_domains') . '</div>' : '')
    . '<div class="' . $colCount . '"></div>'
    . '</div>';

$hidden = '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';

// Same control as in the list below, with everything ticked: a new tab is
// offered on every domain unless the editor says otherwise right here.
$newSectionDomains = '';
if ($hasDomains) {
    $select = new rex_select();
    $select->setName('new_section_domains[]');
    $select->setId('domain-settings-new-domains');
    $select->setAttribute('class', 'form-control selectpicker');
    $select->setAttribute('data-selected-text-format', 'count > 1');
    $select->setAttribute('data-actions-box', 'true');
    $select->setAttribute('data-width', '100%');
    $select->setAttribute('title', rex_i18n::msg('domain_settings_section_domains_none'));
    $select->setMultiple(true);
    $select->setSelected(array_keys($allDomains));
    $select->addArrayOptions($allDomains);

    $newSectionDomains = '<div class="form-group">'
        . '<label for="domain-settings-new-domains">' . rex_i18n::msg('domain_settings_section_domains') . '</label>'
        . $select->get()
        . '</div>';
}

// Creating and editing sit in one panel: both are about sections, and the
// editor should not have to hunt for them in two places.
$body = '<p>' . rex_i18n::msg('domain_settings_sections_notice') . '</p>'
    . '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . $hidden
    . '<input type="hidden" name="func" value="add">'
    . $csrf->getHiddenField()
    . '<div class="row">'
    . '<div class="' . ($hasDomains ? 'col-sm-6' : 'col-sm-12') . '">'
    . '<div class="form-group">'
    . '<label for="domain-settings-section-label">' . rex_i18n::msg('domain_settings_section_label') . '</label>'
    . '<input class="form-control" type="text" id="domain-settings-section-label" name="section_label" value="" required>'
    . '</div>'
    . '</div>'
    . ($hasDomains ? '<div class="col-sm-6">' . $newSectionDomains . '</div>' : '')
    . '</div>'
    . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('domain_settings_section_add') . '</button>'
    . '</form>';

// Creating is a panel of its own: it is a different act from maintaining the
// tabs that already exist, and putting both under one heading made the list
// look like part of the form above it.
$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_section_add_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// One form around the whole list: the delete buttons submit it too, and a
// form per row would nest forms - browsers drop those.
$body = '<form class="domain-settings-sections" action="' . rex_url::currentBackendPage() . '" method="post">'
    . $hidden
    . '<input type="hidden" name="func" value="rename">'
    . $csrf->getHiddenField()
    . $head
    . $rows
    . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('domain_settings_sections_save') . '</button>'
    . '</form>';

// A field name used by two tabs of the same domain resolves to whichever
// table is read first - worth saying here, where tabs and their domains are
// managed. Checked on every visit rather than on every request: it can only
// change through the YForm table manager, which has no event to hook into.
$duplicates = Backend::getDuplicateFieldNames();

if ([] !== $duplicates) {
    $body .= rex_view::warning(rex_i18n::rawMsg(
        'domain_settings_duplicate_fields',
        rex_escape(implode(', ', $duplicates)),
    ));
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_sections_existing'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// ---------------------------------------------------------------- fallback
// Hand-built rather than rex_config_form: that one puts its submit button
// into a panel-footer, which sits visually apart from the buttons of the
// other panels on this page. Saving still goes through rex_config.
$select = new rex_select();
$select->setId('domain-settings-fallback-clang');
$select->setName('fallback_clang_id');
$select->setAttribute('class', 'form-control');
$select->setSelected(DomainSettings::getConfiguredFallbackClangId());
$select->addArrayOptions(array_map(
    static fn (rex_clang $clang) => $clang->getName(),
    rex_clang::getAll(),
));

$body = '<p>' . rex_i18n::msg('domain_settings_fallback_notice') . '</p>'
    . '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . $hidden
    . '<input type="hidden" name="func" value="fallback">'
    . $csrf->getHiddenField()
    . '<div class="form-group">'
    . '<label for="domain-settings-fallback-clang">' . rex_i18n::msg('domain_settings_fallback_clang') . '</label>'
    . $select->get()
    . '</div>'
    . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('domain_settings_save') . '</button>'
    . '</form>';

// What the setting above actually means per domain. The chain is short, but
// its result is not obvious - a domain can carry a start language it does not
// serve - so it is spelled out rather than left to be guessed.
$body .= '<p class="help-block">' . rex_i18n::msg('domain_settings_fallback_chain') . '</p>';

if (count($allDomains) > 1) {
    $rows = '';
    foreach ($allDomains as $domainId => $domainName) {
        $effective = DomainSettings::getFallbackClangId($domainId);
        $rows .= '<tr><td>' . rex_escape($domainName) . '</td>'
            . '<td>' . rex_escape(rex_clang::get($effective)?->getName() ?? (string) $effective) . '</td></tr>';
    }

    $body .= '<table class="table table-condensed domain-settings-fallback-list">'
        . '<thead><tr>'
        . '<th>' . rex_i18n::msg('domain_settings_domain') . '</th>'
        . '<th>' . rex_i18n::msg('domain_settings_fallback_effective') . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_fallback_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
