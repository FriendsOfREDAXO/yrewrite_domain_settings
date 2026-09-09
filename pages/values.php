<?php

/**
 * Editing page: pick a section, a domain and a language, fill in the values.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;

$sections = Backend::getSections();
$domains = Backend::getDomains();

if ([] === $sections) {
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_section_permission'));
    return;
}

// Bail out rather than fall back: with no domain permission at all,
// array_key_first([]) is null and would cast to 0 - which is a real domain
// (yrewrite's implicit "default"), so the fallback would hand out write
// access instead of denying it.
if ([] === $domains) {
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_domain_permission'));
    return;
}

// The section is the backend page we are on, so it needs no request
// parameter - and an unknown or forbidden slug simply has no page.
$table = Backend::sectionBySlug((string) rex_be_controller::getCurrentPagePart(2));
if (null === $table || !isset($sections[$table])) {
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_section_permission'));
    return;
}

$domainId = rex_get('domain_id', 'int', 0);
if (!isset($domains[$domainId])) {
    $domainId = (int) array_key_first($domains);
}

// The languages depend on the domain: yrewrite decides per domain which ones
// it serves, so the tabs are built after the domain is known.
$clangs = Backend::getEditableClangs($domainId);

if ([] === $clangs) {
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_clang_permission'));
    return;
}

$clangIds = array_map(static fn (rex_clang $clang) => $clang->getId(), $clangs);
$fallbackClangId = DomainSettings::getFallbackClangId($domainId);
$clangId = rex_get('clang_id', 'int', 0);
if (!in_array($clangId, $clangIds, true)) {
    $clangId = in_array($fallbackClangId, $clangIds, true) ? $fallbackClangId : $clangIds[0];
}

$baseParams = ['domain_id' => $domainId, 'clang_id' => $clangId];

// ------------------------------------------------------------ domain picker
if (count($domains) > 1) {
    $select = new rex_select();
    $select->setId('domain-settings-domain');
    $select->setName('domain_id');
    $select->setAttribute('class', 'form-control');
    $select->setAttribute('onchange', 'this.form.submit()');
    $select->setSelected($domainId);
    foreach ($domains as $id => $label) {
        $select->addOption($label, $id);
    }

    $body = '<form action="' . rex_url::currentBackendPage() . '" method="get">'
        . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
        . '<input type="hidden" name="clang_id" value="' . $clangId . '">'
        . '<div class="form-group">'
        . '<label for="domain-settings-domain">' . rex_i18n::msg('domain_settings_domain') . '</label>'
        . $select->get()
        . '</div>'
        . '</form>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('domain_settings_domain'), false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
}

$body = '';

// -------------------------------------------------------- no fields defined
if (!Backend::hasFields($table)) {
    $body .= rex_view::info(
        rex_i18n::msg('domain_settings_no_fields')
        . ' <a href="' . rex_escape(Backend::getFieldsUrl($table)) . '">' . rex_i18n::msg('domain_settings_edit_fields') . '</a>',
    );

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('domain_settings_values'), false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
    return;
}

// ---------------------------------------------------------- language switch
if (count($clangs) > 1) {
    $items = '';
    foreach ($clangs as $clang) {
        $isActive = $clang->getId() === $clangId;
        $badge = $clang->getId() === $fallbackClangId
            ? ' <span class="label label-info">' . rex_i18n::msg('domain_settings_fallback') . '</span>'
            : '';

        // The links carry btn classes on purpose. REDAXO's dark theme styles
        // `.nav-tabs > li > .btn-default` but deliberately leaves plain
        // bootstrap tabs alone ("redaxo uses custom tab markup", see
        // be_style/…/bootstrap-dark-overrides/_navs.scss), so a bare
        // <li><a> tab stays white in dark mode.
        $items .= '<li' . ($isActive ? ' class="active"' : '') . '>'
            . '<a class="btn btn-default"'
            . ' data-clang-id="' . $clang->getId() . '"'
            . ' href="' . rex_url::currentBackendPage(['domain_id' => $domainId, 'clang_id' => $clang->getId()]) . '">'
            . rex_escape($clang->getName()) . $badge
            . '</a></li>';
    }
    $body .= '<ul class="nav nav-tabs domain-settings-language-tabs">' . $items . '</ul>';
}

// Render first, then work out what is inherited: a save happens inside
// renderForm(), so asking earlier would describe the state before it.
$dataset = Backend::getDataset($table, ['domain_id' => $domainId, 'clang_id' => $clangId]);
$form = Backend::renderForm($dataset, 'domain_settings', ['page' => rex_be_controller::getCurrentPage()] + $baseParams);

// Tell the editor which values this language takes from elsewhere, so an
// empty field reads as "taken from German" rather than "missing".
$inherited = Backend::getInheritedKeys($table, $domainId, $clangId);
if ([] !== $inherited) {
    // No rex_escape() on the arguments: rex_i18n::msg() escapes the
    // interpolated message with html_simplified, doing it twice turns
    // an & into &amp;amp;.
    $body .= rex_view::info(rex_i18n::msg(
        'domain_settings_inherited_hint',
        rex_clang::get($fallbackClangId)?->getName() ?? '',
        implode(', ', $inherited),
    ));
}

$body .= $form;

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_values'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// ------------------------------------------------------- unsaved changes
// Rendered here rather than built in JavaScript so the wording lives in the
// language files. Bootstrap 3 markup, which is what the backend ships.
if (count($clangs) > 1) {
    echo '
<div class="modal fade" id="domain-settings-unsaved-dialog" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title">' . rex_i18n::msg('domain_settings_unsaved_title') . '</h4>
      </div>
      <div class="modal-body"><p>' . rex_i18n::msg('domain_settings_unsaved_body') . '</p></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">'
            . rex_i18n::msg('domain_settings_unsaved_cancel') . '</button>
        <button type="button" class="btn btn-default" data-domain-settings-action="discard">'
            . rex_i18n::msg('domain_settings_unsaved_discard') . '</button>
        <button type="button" class="btn btn-save" data-domain-settings-action="save">'
            . rex_i18n::msg('domain_settings_unsaved_save') . '</button>
      </div>
    </div>
  </div>
</div>';
}
