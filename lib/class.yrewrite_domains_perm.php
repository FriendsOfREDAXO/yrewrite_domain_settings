<?php

use FriendsOfRedaxo\DomainSettings\DomainPerm;

/**
 * The complex permission registered under `yrewrite_domains`.
 *
 * Everything it does lives in DomainPerm; this subclass exists to keep the
 * class name and the return value of getDomains() exactly as they were before
 * 2.4.0, because project code calls both.
 */
class rex_yrewrite_domains_perm extends DomainPerm
{
    /**
     * The raw permission value: an array of domain ids, or the string `all`.
     *
     * Kept verbatim - callers compare it against `'all'`
     * (`$user->getComplexPerm('yrewrite_domains')->getDomains() === 'all'`),
     * which is why this must not be normalised into an array.
     *
     * @deprecated 2.4.0 use hasPerm() instead, which also covers admins
     *
     * @return array<int, string>|string
     */
    public function getDomains()
    {
        return $this->perms;
    }
}
