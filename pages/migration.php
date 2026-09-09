<?php

/**
 * Migration: moving data from somewhere else into these tables.
 *
 * Kept apart from the settings because it is not one: the settings describe
 * how the addon behaves from now on, this page changes stored data in one go.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\DomainSettings\Backend;

$csrf = rex_csrf_token::factory('domain_settings_section');

if ('copy' === rex_post('func', 'string')) {
    // Say so rather than doing nothing: an expired token is otherwise
    // indistinguishable from a copy that silently failed.
    if (!$csrf->isValid()) {
        echo rex_view::warning(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $sections = Backend::getSections();
        $domains = Backend::getDomains();

        // Per domain, because yrewrite may serve different languages on each:
        // copying into a language the target domain does not have would write
        // rows that are never delivered.
        $clangIdsFor = Backend::getEditableClangIds(...);

        $onlyTable = rex_post('copy_section', 'string', '');
        $from = [rex_post('copy_from_domain', 'int', 0), rex_post('copy_from_clang', 'int', 0)];
        $to = [rex_post('copy_to_domain', 'int', 0), rex_post('copy_to_clang', 'int', 0)];

        // Everything the request names has to be something this user may see -
        // otherwise the form would be a way to read one domain into another.
        $allowed = isset($domains[$from[0]], $domains[$to[0]])
            && in_array($from[1], $clangIdsFor($from[0]), true)
            && in_array($to[1], $clangIdsFor($to[0]), true)
            && ('' === $onlyTable || isset($sections[$onlyTable]));

        // Copying into a domain the section does not count on would write
        // rows nothing ever reads. Named rather than counted as "nothing
        // copied": the reason is in the settings, not in the data.
        $notAssigned = '' !== $onlyTable
            && $allowed
            && !Backend::isSectionVisibleForDomain($onlyTable, $to[0]);

        if (!$allowed) {
            echo rex_view::warning(rex_i18n::msg('domain_settings_copy_invalid'));
        } elseif ($notAssigned) {
            echo rex_view::warning(rex_i18n::rawMsg(
                'domain_settings_copy_section_not_assigned',
                rex_escape($sections[$onlyTable]),
                rex_escape($domains[$to[0]]),
            ));
        } else {
            $copied = Backend::copyValues($from[0], $from[1], $to[0], $to[1], '' === $onlyTable ? null : $onlyTable);
            echo $copied > 0
                ? rex_view::success(rex_i18n::msg('domain_settings_copy_done', $copied))
                : rex_view::warning(rex_i18n::msg('domain_settings_copy_nothing'));
        }
    }
}

$hidden = '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';

// ------------------------------------------------------------ copy data over
$domains = Backend::getDomains();
$clangs = Backend::getEditableClangs();
$sections = Backend::getSections();

// With one domain and one language there is nothing to copy between - say so
// rather than leaving the page empty, since it is in the navigation either way.
if (count($domains) < 2 && count($clangs) < 2) {
    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('domain_settings_copy_title'), false);
    $fragment->setVar('body', rex_view::info(rex_i18n::msg('domain_settings_copy_nothing_to_do')), false);
    echo $fragment->parse('core/page/section.php');
} else {
    $pick = static function (string $name, array $options, string $label): string {
        $select = new rex_select();
        $select->setName($name);
        $select->setId('domain-settings-' . $name);
        $select->setAttribute('class', 'form-control');
        $select->addArrayOptions($options);

        return '<div class="form-group"><label for="domain-settings-' . $name . '">' . $label . '</label>'
            . $select->get() . '</div>';
    };

    $clangOptions = [];
    foreach ($clangs as $clang) {
        $clangOptions[$clang->getId()] = $clang->getName();
    }

    $sectionOptions = ['' => rex_i18n::msg('domain_settings_copy_all_sections')] + $sections;

    // With a single domain the picker is not rendered, so its value has to
    // travel as a hidden field - otherwise nothing is posted, the id falls
    // back to 0, and the permission check rejects every copy.
    $singleDomain = count($domains) < 2
        ? '<input type="hidden" name="copy_from_domain" value="' . (int) array_key_first($domains) . '">'
            . '<input type="hidden" name="copy_to_domain" value="' . (int) array_key_first($domains) . '">'
        : '';

    $body = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . $hidden
        . $singleDomain
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
        . ' data-confirm="' . rex_escape(rex_i18n::rawMsg('domain_settings_copy_confirm')) . '">'
        . rex_i18n::msg('domain_settings_copy_submit') . '</button>'
        . '</form>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('domain_settings_copy_title'), false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
}
