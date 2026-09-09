<?php

namespace FriendsOfRedaxo\DomainSettings\Test;

use RuntimeException;

/**
 * Thrown by a test that cannot run on this instance.
 *
 * Some checks need a second language, a second YForm table, or a domain
 * without values - none of which exist everywhere. Returning early would count
 * them as passed, which is how a single-language installation ends up
 * reporting "all green" without ever touching the language logic.
 */
final class SkippedException extends RuntimeException {}
