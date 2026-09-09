<?php

/**
 * Sends an old tab URL to where that tab lives now.
 *
 * Until 2.5.0 every tab was a backend page of its own
 * (`page=yrewrite_domain_settings/<slug>`). Bookmarks and links out there
 * still point at those, and an unregistered page would be bounced to the
 * start page by the controller - so they stay registered, hidden, and lead
 * here.
 *
 * @var rex_addon $this
 */

$slug = (string) rex_be_controller::getCurrentPagePart(2);

rex_response::sendRedirect(rex_url::backendPage(
    'yrewrite_domain_settings/data',
    ['section' => $slug],
    false,
));
