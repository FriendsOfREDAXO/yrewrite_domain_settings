<?php

/**
 * Frame for all subpages.
 *
 * @var rex_addon $this
 */

echo rex_view::title(rex_i18n::msg('domain_settings_title'));

rex_be_controller::includeCurrentPageSubPath();
