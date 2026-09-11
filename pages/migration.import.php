<?php

/**
 * Import from the global_settings addon.
 *
 * Included from migration.php, and only when that addon is installed. Two
 * steps: a preview that writes nothing, then the run - because the import
 * replaces everything in the target section.
 *
 * @var rex_csrf_token $csrf
 * @var string $hidden
 */

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\Import\Importer;
use FriendsOfRedaxo\DomainSettings\Import\MappedField;
use FriendsOfRedaxo\DomainSettings\Import\Source;

$importer = new Importer();
$importSections = Backend::getSections();
$importDomains = Backend::getDomains();

$func = rex_post('func', 'string', '');
$showPreview = false;
$target = rex_post('import_target', 'string', '');
$targetNew = trim(rex_post('import_target_new', 'string', ''));
$importDomainId = rex_post('import_domain', 'int', 0);

if (in_array($func, ['import_preview', 'import_run'], true)) {
    if (!$csrf->isValid()) {
        echo rex_view::warning(rex_i18n::msg('csrf_token_invalid'));
    } elseif (!isset($importDomains[$importDomainId])) {
        // The domain decides which rows are written, so it has to be one this
        // user may edit - not merely one that exists.
        echo rex_view::warning(rex_i18n::msg('domain_settings_copy_invalid'));
    } elseif ('new' !== $target && !isset($importSections[$target])) {
        echo rex_view::warning(rex_i18n::msg('domain_settings_import_error_no_target'));
    } elseif ('new' === $target && '' === $targetNew) {
        echo rex_view::warning(rex_i18n::msg('domain_settings_import_error_no_target'));
    } elseif ('import_preview' === $func) {
        $showPreview = true;
    } else {
        $table = $target;

        if ('new' === $target) {
            $table = Backend::createSection($targetNew);

            if (null === $table) {
                $table = '';
                echo rex_view::warning(rex_i18n::msg('domain_settings_import_error_create'));
            }
        }

        if ('' !== $table) {
            // Every language both sides know. global_settings keeps a row per
            // language, so this is a one to one copy - languages the target
            // domain does not serve are dropped by the domain check below.
            $clangIds = array_values(array_intersect(
                Source::getClangIds(),
                Backend::getEditableClangIds($importDomainId),
            ));

            $result = $importer->run($table, $importDomainId, $clangIds);

            foreach ($result->getErrors() as $error) {
                echo rex_view::warning(rex_escape($error));
            }

            if (!$result->hasErrors() || $result->countFields() > 0) {
                $summary = rex_i18n::msg(
                    'domain_settings_import_summary',
                    $result->countFields(),
                    $result->countValues(),
                );

                if ($result->countSkipped() > 0) {
                    $summary .= ' ' . rex_i18n::msg('domain_settings_import_summary_skipped', $result->countSkipped());
                }

                $attention = count($result->getFieldsNeedingAttention());

                if ($attention > 0) {
                    $summary .= ' ' . rex_i18n::msg('domain_settings_import_summary_attention', $attention);
                }

                echo rex_view::success($summary);

                // The section list and the field list both changed.
                $importSections = Backend::getSections();
            }
        }
    }
}

// ------------------------------------------------------------------- preview
$body = '<p>' . rex_i18n::msg('domain_settings_import_intro') . '</p>';

if ($showPreview) {
    $plan = $importer->plan();

    if ([] === $plan) {
        $body .= rex_view::info(rex_i18n::msg('domain_settings_import_nothing_planned'));
    } else {
        $targetLabel = 'new' === $target
            ? $targetNew
            : ($importSections[$target] ?? $target);

        $rows = '';

        foreach ($plan as $mapped) {
            $label = match ($mapped->status) {
                MappedField::STATUS_AUTOMATIC => '<span class="text-success">'
                    . rex_i18n::msg('domain_settings_import_status_automatic') . '</span>',
                MappedField::STATUS_MANUAL => '<span class="text-warning">'
                    . rex_i18n::msg('domain_settings_import_status_manual') . '</span>',
                default => '<span class="text-muted">'
                    . rex_i18n::msg('domain_settings_import_status_skipped') . '</span>',
            };

            $notes = '';
            foreach ($mapped->notes as $note) {
                $notes .= '<br><small class="text-muted">' . rex_escape($note) . '</small>';
            }

            $rows .= '<tr>'
                . '<td>' . rex_escape($mapped->source->title) . '<br>'
                . '<small class="text-muted">' . rex_escape($mapped->source->name) . '</small></td>'
                . '<td>' . ($mapped->isSkipped() ? '—' : rex_escape($mapped->source->getKey())) . '</td>'
                . '<td>' . ($mapped->isSkipped() ? '—' : '<code>' . rex_escape($mapped->targetType) . '</code>') . '</td>'
                . '<td>' . $label . $notes . '</td>'
                . '</tr>';
        }

        $body .= '<table class="table table-striped">'
            . '<thead><tr>'
            . '<th>' . rex_i18n::msg('domain_settings_import_col_source') . '</th>'
            . '<th>' . rex_i18n::msg('domain_settings_import_col_key') . '</th>'
            . '<th>' . rex_i18n::msg('domain_settings_import_col_type') . '</th>'
            . '<th>' . rex_i18n::msg('domain_settings_import_col_status') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        // Callbacks are not carried over. A box of its own rather than a
        // line in the table, and the code in full: it would otherwise be lost
        // with the addon it currently lives in. Amber, not red - nothing is
        // destroyed here, there is something left to do.
        $callbacks = $importer->getCallbackFields();

        if ([] !== $callbacks) {
            $list = '';
            foreach ($callbacks as $field) {
                $list .= '<p><strong>' . rex_escape($field->title)
                    . '</strong> <small class="text-muted">' . rex_escape($field->name) . '</small></p>'
                    . '<pre class="pre-scrollable">' . rex_escape($field->callback) . '</pre>';
            }

            $body .= '<div class="alert alert-warning">'
                . '<h4>' . rex_i18n::msg('domain_settings_import_callbacks_title') . '</h4>'
                . '<p>' . rex_i18n::msg('domain_settings_import_callbacks_intro') . '</p>'
                . $list
                . '</div>';
        }

        $body .= rex_view::error(rex_i18n::msg('domain_settings_import_warning_replace'))
            . '<form action="' . rex_url::currentBackendPage() . '" method="post">'
            . $hidden
            . $csrf->getHiddenField()
            . '<input type="hidden" name="func" value="import_run">'
            . '<input type="hidden" name="import_target" value="' . rex_escape($target) . '">'
            . '<input type="hidden" name="import_target_new" value="' . rex_escape($targetNew) . '">'
            . '<input type="hidden" name="import_domain" value="' . $importDomainId . '">'
            . '<button class="btn btn-delete" type="submit"'
            . ' data-confirm="' . rex_escape(rex_i18n::rawMsg('domain_settings_import_confirm', $targetLabel)) . '">'
            . rex_i18n::msg('domain_settings_import_execute') . '</button> '
            . '<a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">'
            . rex_i18n::msg('domain_settings_import_back') . '</a>'
            . '</form>';
    }
} else {
    // --------------------------------------------------------- target picker
    $targetOptions = $importSections + ['new' => rex_i18n::msg('domain_settings_import_target_new')];

    $targetSelect = new rex_select();
    $targetSelect->setName('import_target');
    $targetSelect->setId('domain-settings-import-target');
    $targetSelect->setAttribute('class', 'form-control');
    $targetSelect->addArrayOptions($targetOptions);

    $domainField = '';

    if (count($importDomains) > 1) {
        $domainSelect = new rex_select();
        $domainSelect->setName('import_domain');
        $domainSelect->setId('domain-settings-import-domain');
        $domainSelect->setAttribute('class', 'form-control');
        $domainSelect->addArrayOptions($importDomains);
        // The first domain the user may edit, same default as the editing page.
        $domainSelect->setSelected((int) array_key_first($importDomains));

        $domainField = '<div class="form-group">'
            . '<label for="domain-settings-import-domain">' . rex_i18n::msg('domain_settings_import_domain') . '</label>'
            . $domainSelect->get() . '</div>';
    } else {
        $domainField = '<input type="hidden" name="import_domain" value="'
            . (int) array_key_first($importDomains) . '">';
    }

    $body .= '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . $hidden
        . $csrf->getHiddenField()
        . '<input type="hidden" name="func" value="import_preview">'
        . '<div class="form-group">'
        . '<label for="domain-settings-import-target">' . rex_i18n::msg('domain_settings_import_target') . '</label>'
        . $targetSelect->get()
        . '</div>'
        // Shown only while "new tab" is picked - the asset hides it, so
        // without JavaScript the field stays where it is and still works.
        . '<div class="form-group" data-domain-settings-visible-for="new">'
        . '<label for="domain-settings-import-target-new">'
        . rex_i18n::msg('domain_settings_import_target_new_label') . '</label>'
        . '<input class="form-control" type="text" id="domain-settings-import-target-new"'
        . ' name="import_target_new" value="' . rex_escape($targetNew) . '">'
        . '</div>'
        . $domainField
        . rex_view::error(rex_i18n::msg('domain_settings_import_warning_replace'))
        . '<button class="btn btn-default" type="submit">'
        . rex_i18n::msg('domain_settings_import_preview') . '</button>'
        . '</form>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('domain_settings_import_title'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
