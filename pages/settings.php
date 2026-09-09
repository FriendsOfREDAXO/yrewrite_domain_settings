<?php

/**
 * Settings: the fallback language, and managing the sections.
 *
 * A section is an ordinary YForm table. That is not an implementation detail
 * to hide but the point of it: YForm's own table permission then governs who
 * may edit which section, and it shows up in the role form without a line of
 * code here.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\DomainSettings\Backend;

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
        echo null === Backend::createSection($label)
            ? rex_view::warning(rex_i18n::msg('domain_settings_section_invalid'))
            : rex_view::success(rex_i18n::msg('domain_settings_section_added', $label));
    } elseif ('delete' === $func) {
        $table = rex_post('delete_section', 'string', '');
        echo Backend::deleteSection($table)
            ? rex_view::success(rex_i18n::msg('domain_settings_section_deleted'))
            : rex_view::warning(rex_i18n::msg('domain_settings_section_not_deletable'));
    } elseif ('copy' === $func) {
        $sections = Backend::getSections();
        $domains = Backend::getDomains();
        $clangIds = array_map(static fn (rex_clang $clang) => $clang->getId(), Backend::getEditableClangs());

        $onlyTable = rex_post('copy_section', 'string', '');
        $from = [rex_post('copy_from_domain', 'int', 0), rex_post('copy_from_clang', 'int', 0)];
        $to = [rex_post('copy_to_domain', 'int', 0), rex_post('copy_to_clang', 'int', 0)];

        // Everything the request names has to be something this user may see -
        // otherwise the form would be a way to read one domain into another.
        $allowed = isset($domains[$from[0]], $domains[$to[0]])
            && in_array($from[1], $clangIds, true)
            && in_array($to[1], $clangIds, true)
            && ('' === $onlyTable || isset($sections[$onlyTable]));

        if (!$allowed) {
            echo rex_view::warning(rex_i18n::msg('domain_settings_copy_invalid'));
        } else {
            $copied = Backend::copyValues($from[0], $from[1], $to[0], $to[1], '' === $onlyTable ? null : $onlyTable);
            echo $copied > 0
                ? rex_view::success(rex_i18n::msg('domain_settings_copy_done', $copied))
                : rex_view::warning(rex_i18n::msg('domain_settings_copy_nothing'));
        }
    } elseif ('rename' === $func) {
        $renamed = 0;
        /** @var array<string, string> $labels */
        $labels = (array) rex_post('section_labels', 'array', []);
        foreach ($labels as $table => $label) {
            if (Backend::renameSection((string) $table, (string) $label)) {
                ++$renamed;
            }
        }
        echo $renamed > 0
            ? rex_view::success(rex_i18n::msg('domain_settings_sections_saved'))
            : rex_view::warning(rex_i18n::msg('domain_settings_section_invalid'));
    }
}

// ------------------------------------------------------------------ sections
$rows = '';
foreach (Backend::getAllSections() as $table => $label) {
    $count = count(rex_yform_manager_table::get($table)?->getValueFields() ?? []);

    $rows .= '<tr>'
        . '<td><input class="form-control" type="text" name="section_labels[' . rex_escape($table) . ']"'
        . ' value="' . rex_escape($label) . '" required>'
        . '<small class="text-muted">' . rex_escape($table) . '</small></td>'
        . '<td>' . rex_i18n::msg('domain_settings_field_count', $count) . '</td>'
        . '<td class="rex-table-action"><a class="btn btn-default" href="' . rex_escape(Backend::getFieldsUrl($table)) . '">'
        . '<i class="rex-icon fa-list"></i> ' . rex_i18n::msg('domain_settings_edit_fields') . '</a></td>'
        . '<td class="rex-table-action">'
        . (Backend::isSectionDeletable($table)
            ? '<button class="btn btn-delete" type="submit" name="delete_section"'
                . ' value="' . rex_escape($table) . '"'
                . ' data-confirm="' . rex_escape(rex_i18n::msg('domain_settings_section_delete_confirm', $label)) . '">'
                . '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('domain_settings_section_delete') . '</button>'
            : '')
        . '</td>'
        . '</tr>';
}

$hidden = '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';

// Creating and editing sit in one panel: both are about sections, and the
// editor should not have to hunt for them in two places.
$body = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . $hidden
    . '<input type="hidden" name="func" value="add">'
    . $csrf->getHiddenField()
    . '<div class="form-group">'
    . '<label for="domain-settings-section-label">' . rex_i18n::msg('domain_settings_section_label') . '</label>'
    . '<input class="form-control" type="text" id="domain-settings-section-label" name="section_label" value="" required>'
    . '<p class="help-block">' . rex_i18n::msg('domain_settings_section_label_notice') . '</p>'
    . '</div>'
    . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('domain_settings_section_add') . '</button>'
    . '</form>';

// One form around the whole table: a <form> inside <tr> is invalid markup and
// browsers drop it, so per-row forms would simply not submit.
$body .= '<form class="domain-settings-sections" action="' . rex_url::currentBackendPage() . '" method="post">'
    . $hidden
    . '<input type="hidden" name="func" value="rename">'
    . $csrf->getHiddenField()
    . '<fieldset><legend>' . rex_i18n::msg('domain_settings_sections_existing') . '</legend>'
    . '<table class="table table-hover"><tbody>' . $rows . '</tbody></table>'
    . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('domain_settings_sections_save') . '</button>'
    . '</fieldset>'
    . '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_sections'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// -------------------------------------------------------- copy values over
$domains = Backend::getDomains();
$clangs = Backend::getEditableClangs();
$sections = Backend::getSections();

if (count($domains) > 1 || count($clangs) > 1) {
    $pick = static function (string $name, array $options, string $label): string {
        $select = new rex_select();
        $select->setName($name);
        $select->setId('domain-settings-' . $name);
        $select->setAttribute('class', 'form-control');
        foreach ($options as $value => $text) {
            $select->addOption($text, $value);
        }

        return '<div class="form-group"><label for="domain-settings-' . $name . '">' . $label . '</label>'
            . $select->get() . '</div>';
    };

    $clangOptions = [];
    foreach ($clangs as $clang) {
        $clangOptions[$clang->getId()] = $clang->getName();
    }

    $sectionOptions = ['' => rex_i18n::msg('domain_settings_copy_all_sections')] + $sections;

    $body = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . $hidden
        . '<input type="hidden" name="func" value="copy">'
        . $csrf->getHiddenField()
        . '<p>' . rex_i18n::msg('domain_settings_copy_notice') . '</p>'
        . '<div class="row">'
        . '<div class="col-sm-6"><fieldset><legend>' . rex_i18n::msg('domain_settings_copy_from') . '</legend>'
        . (count($domains) > 1 ? $pick('copy_from_domain', $domains, rex_i18n::msg('domain_settings_domain')) : '')
        . $pick('copy_from_clang', $clangOptions, rex_i18n::msg('domain_settings_copy_language'))
        . '</fieldset></div>'
        . '<div class="col-sm-6"><fieldset><legend>' . rex_i18n::msg('domain_settings_copy_to') . '</legend>'
        . (count($domains) > 1 ? $pick('copy_to_domain', $domains, rex_i18n::msg('domain_settings_domain')) : '')
        . $pick('copy_to_clang', $clangOptions, rex_i18n::msg('domain_settings_copy_language'))
        . '</fieldset></div>'
        . '</div>'
        . $pick('copy_section', $sectionOptions, rex_i18n::msg('domain_settings_sections'))
        . '<button class="btn btn-save" type="submit"'
        . ' data-confirm="' . rex_escape(rex_i18n::msg('domain_settings_copy_confirm')) . '">'
        . rex_i18n::msg('domain_settings_copy_submit') . '</button>'
        . '</form>';

    if (count($domains) < 2) {
        $body = str_replace('name="copy_from_domain"', 'name="copy_from_domain" value="' . (int) array_key_first($domains) . '"', $body);
    }

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('domain_settings_copy_title'), false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
}

// ---------------------------------------------------------- fallback language
$form = rex_config_form::factory($this->getPackageId());

$field = $form->addSelectField('fallback_clang_id');
$field->setLabel(rex_i18n::msg('domain_settings_fallback_clang'));
$field->setNotice(rex_i18n::msg('domain_settings_fallback_clang_notice'));

$select = $field->getSelect();
foreach (rex_clang::getAll() as $clang) {
    $select->addOption($clang->getName(), $clang->getId());
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_settings'), false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
