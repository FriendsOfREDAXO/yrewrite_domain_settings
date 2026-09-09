<?php

namespace FriendsOfRedaxo\DomainSettings;

use rex_complex_perm;
use rex_i18n;

use function count;
use function in_array;

/**
 * Restricts which domains a user may edit.
 *
 * Registered under `yrewrite_domains` - the name this addon has used since
 * 2.0, so roles keep the domain permissions they were given before the update.
 *
 * The class actually registered in boot.php is rex_yrewrite_domains_perm,
 * which extends this one and keeps the old getDomains() signature.
 */
class DomainPerm extends rex_complex_perm
{
    public function hasPerm(int|string $domainId): bool
    {
        return $this->hasAll() || in_array((string) $domainId, array_map('strval', $this->perms), true);
    }

    public function count(): int
    {
        return $this->hasAll() ? count(Backend::getAllDomains()) : count($this->perms);
    }

    /** @return array{label: string, all_label: string, options: array<int, string>} */
    public static function getFieldParams(): array
    {
        return [
            'label' => rex_i18n::msg('domain_settings_domains'),
            'all_label' => rex_i18n::msg('domain_settings_domains_all'),
            'options' => Backend::getAllDomains(),
        ];
    }
}
