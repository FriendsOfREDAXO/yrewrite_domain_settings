<?php

/**
 * Update to 2.4.0: values gain a language axis.
 * Update to 2.5.0: the value cache file is gone.
 *
 * Runs before the new files are in place, so it must not touch this addon's
 * classes - see the note in migrate.php. rex_file and rex_path are core, so
 * they are safe here.
 *
 * @var rex_addon $this
 */

require __DIR__ . '/migrate.php';

// Values are read from the tables now. The old file would otherwise sit in
// var/cache until someone clears it - harmless, but nothing reads it and it
// invites the question what it is.
rex_file::delete(rex_path::addonCache('yrewrite_domain_settings', 'values.json'));
