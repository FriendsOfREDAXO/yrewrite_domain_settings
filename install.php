<?php

/**
 * Creates the storage table and registers it with the YForm table manager.
 *
 * One row per domain and language. Values that are identical in every
 * language are simply maintained in the fallback language and inherited from
 * there - the same mechanism that covers "not translated yet", so there is no
 * second table and no decision to make when adding a field.
 *
 * The structural columns are managed here rather than as YForm fields: the
 * editor never picks a domain or language inside the form, the backend page
 * does that. Everything else is added by the admin in the table manager.
 *
 * Reinstalling on top of a 2.3.0 table is covered as well, because migrate.php
 * only ever ensures what is missing.
 *
 * @var rex_addon $this
 */

require __DIR__ . '/migrate.php';
