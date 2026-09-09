<?php

/**
 * The editing page: pick a domain, then a section, then a language.
 *
 * The three axes are nested rather than side by side. The domain is the
 * context everything below is edited in and sits on top; the section decides
 * which fields are shown and is a tab row of its own; the language is the
 * innermost step and stays inside the panel with the form.
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

// Domain and language are the editing context and live in the session, so
// they survive leaving the page and coming back. Both are validated against
// what this user may edit before they are handed out.
$domainId = Backend::getActiveDomainId();

echo Backend::renderDomainSwitch();

// The languages depend on the domain: yrewrite decides per domain which ones
// it serves, so they are resolved after the domain is known.
$clangs = Backend::getEditableClangs($domainId);

if ([] === $clangs) {
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_clang_permission'));
    return;
}

$clangIds = array_map(static fn (rex_clang $clang) => $clang->getId(), $clangs);
$fallbackClangId = DomainSettings::getFallbackClangId($domainId);
$clangId = Backend::getActiveClangId($domainId);

// The section travels in the URL, not in the session: it is where you are on
// this page, not the context the page is about. An unknown slug falls back to
// the first section rather than to an error.
$table = Backend::sectionBySlug(rex_request::get('section', 'string', ''));
if (null === $table || !isset($sections[$table])) {
    $table = (string) array_key_first($sections);
}

$baseParams = [
    'section' => Backend::sectionSlug($table),
    'domain_id' => $domainId,
    'clang_id' => $clangId,
];

// ------------------------------------------------------------ section tabs
// Handed to the panel as "before" rather than echoed: the fragment puts it
// inside the same <section>, which is what lets the active tab dock onto the
// panel below instead of floating above it.
$sectionTabs = '';

if (count($sections) > 1) {
    $items = '';
    foreach ($sections as $sectionTable => $label) {
        $isActive = $sectionTable === $table;

        // btn classes for the same reason as the language tabs below - see
        // the comment there.
        $items .= '<li' . ($isActive ? ' class="active"' : '') . '>'
            . '<a class="btn btn-default"'
            . ' href="' . rex_url::currentBackendPage([
                'section' => Backend::sectionSlug($sectionTable),
                'domain_id' => $domainId,
                'clang_id' => $clangId,
            ]) . '">'
            . rex_escape($label)
            . '</a></li>';
    }

    $sectionTabs = '<ul class="nav nav-tabs domain-settings-tabs domain-settings-section-tabs">' . $items . '</ul>';
}

$body = '';

// -------------------------------------------------------- no fields defined
if (!Backend::hasFields($table)) {
    $body .= rex_view::info(
        rex_i18n::msg('domain_settings_no_fields')
        . ' <a href="' . Backend::getFieldsUrl($table) . '">' . rex_i18n::msg('domain_settings_edit_fields') . '</a>',
    );

    $fragment = new rex_fragment();
    $fragment->setVar('before', $sectionTabs, false);
    if ('' === $sectionTabs) {
        $fragment->setVar('title', rex_escape($sections[$table]), false);
    }
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
    $body .= '<ul class="nav nav-tabs domain-settings-tabs domain-settings-language-tabs">' . $items . '</ul>';
}

// Render first, then work out what is inherited: a save happens inside
// renderForm(), so asking earlier would describe the state before it.
$dataset = Backend::getDataset($table, ['domain_id' => $domainId, 'clang_id' => $clangId]);
// Admins get the way into the field definitions from here, next to the save
// button - not only from the "no fields yet" hint, which disappears as soon
// as the first field exists.
$fieldsLink = rex::getUser()?->isAdmin()
    ? '<a class="btn btn-default" href="' . Backend::getFieldsUrl($table) . '">'
        . '<i class="rex-icon fa-list"></i> ' . rex_i18n::msg('domain_settings_edit_fields') . '</a>'
    : '';

$saved = false;
$form = Backend::renderForm(
    $dataset,
    'domain_settings',
    ['page' => rex_be_controller::getCurrentPage()] + $baseParams,
    $saved,
    $fieldsLink,
);

// "Save and switch" from the unsaved-changes dialog: the language to go to
// rides along in the post, and only a completed save may follow it - otherwise
// a validation error would silently drop what was typed.
if ($saved) {
    $gotoClangId = rex_post('domain_settings_goto_clang', 'int', 0);

    if (0 !== $gotoClangId && $gotoClangId !== $clangId && in_array($gotoClangId, $clangIds, true)) {
        rex_response::sendRedirect(rex_url::currentBackendPage([
            'domain_id' => $domainId,
            'clang_id' => $gotoClangId,
        ], false));
    }
}

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

// No panel title while there are tabs: the active one already names the
// section, and without a header the panel is one plain surface for the tabs
// to sit on. With a single section there are no tabs, so the panel takes the
// name itself rather than standing there unlabelled.
$fragment = new rex_fragment();
$fragment->setVar('before', $sectionTabs, false);
if ('' === $sectionTabs) {
    $fragment->setVar('title', rex_escape($sections[$table]), false);
}
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
