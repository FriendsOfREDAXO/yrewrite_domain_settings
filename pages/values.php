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

// The languages depend on the domain: yrewrite decides per domain which ones
// it serves, so they are resolved after the domain is known.
$clangs = Backend::getEditableClangs($domainId);

if ([] === $clangs) {
    // Still offer the domain switch: without it there would be no way off a
    // domain whose languages this user may not touch.
    echo Backend::renderContextBar($domainId);
    echo rex_view::warning(rex_i18n::msg('domain_settings_no_clang_permission'));
    return;
}

$fallbackClangId = DomainSettings::getFallbackClangId($domainId);
$clangId = Backend::getActiveClangId($domainId);

// Tabs assigned to this domain. The assignment narrows what is offered here;
// the permission that decides what may be edited at all is YForm's, applied
// in getSections() either way.
$available = Backend::getSections($domainId);

if ([] === $available) {
    // rawMsg() does not escape its arguments, and the domain name is free
    // text from yrewrite - same reason settings.php escapes it by hand.
    echo rex_view::warning(rex_i18n::rawMsg(
        'domain_settings_no_section_for_domain',
        rex_escape((string) ($domains[$domainId] ?? '')),
    ));
    return;
}

// The section travels in the URL, not in the session: it is where you are on
// this page, not the context the page is about. An unknown slug - or one this
// domain does not offer - falls back to the first available tab rather than
// to an error.
$table = Backend::sectionBySlug(rex_request::get('section', 'string', ''));
if (null === $table || !isset($available[$table])) {
    $table = (string) array_key_first($available);
}

$baseParams = [
    'section' => Backend::sectionSlug($table),
    'domain_id' => $domainId,
    'clang_id' => $clangId,
];

echo Backend::renderContextBar($domainId, ['section' => Backend::sectionSlug($table)]);

// ------------------------------------------------------------ section tabs
// Handed to the panel as "before" rather than echoed: the fragment puts it
// inside the same <section>, which is what lets the active tab dock onto the
// panel below instead of floating above it.
$sectionTabs = '';

if (count($available) > 1) {
    $items = '';
    foreach ($available as $sectionTable => $label) {
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

// "Save and switch" from the unsaved-changes dialog: where to go rides along
// in the post, and only a completed save may follow it - otherwise a
// validation error would silently drop what was typed.
if ($saved) {
    $goto = rex_post('domain_settings_goto', 'string', '');

    // The value comes from the page, so it is checked rather than trusted: a
    // relative backend link and nothing else. That rules out another host, a
    // protocol-relative "//evil", and a header injected through a newline.
    if (1 === preg_match('#^index\.php\?[A-Za-z0-9_\-=&;%./+]*$#', $goto)) {
        rex_response::sendRedirect($goto);
    }
}

// Tell the editor that an empty field reads as "taken from German" rather
// than "missing". Shown on every tab of every language but the fallback one -
// the rule holds there whether or not a field happens to be empty right now,
// and a hint that comes and goes is one nobody learns to rely on.
if ($clangId !== $fallbackClangId) {
    // No rex_escape() on the argument: rex_i18n::msg() escapes the
    // interpolated message with html_simplified, doing it twice turns
    // an & into &amp;amp;.
    $body .= rex_view::info(rex_i18n::msg(
        'domain_settings_inherited_hint',
        rex_clang::get($fallbackClangId)?->getName() ?? '',
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
// language files. Bootstrap 3 markup, which is what the backend ships. Always
// rendered: every link off this page goes through it, not just the language.
echo '
<div class="modal fade" id="domain-settings-unsaved-dialog" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title">' . rex_i18n::msg('domain_settings_unsaved_title') . '</h4>
      </div>
      <div class="modal-body"><p>' . rex_i18n::msg('domain_settings_unsaved_body') . '</p></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default pull-left" data-dismiss="modal">'
            . rex_i18n::msg('domain_settings_unsaved_cancel') . '</button>
        <button type="button" class="btn btn-default" data-domain-settings-action="discard">'
            . rex_i18n::msg('domain_settings_unsaved_discard') . '</button>
        <button type="button" class="btn btn-save" data-domain-settings-action="save">'
            . rex_i18n::msg('domain_settings_unsaved_save') . '</button>
      </div>
    </div>
  </div>
</div>';
