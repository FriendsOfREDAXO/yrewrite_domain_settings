<?php

class rex_yrewrite_domains_perm extends rex_complex_perm
{
    public function getDomains() {
        return $this->perms;
    }

    public static function getFieldParams()
    {
        return [
            'label' => rex_i18n::msg('yrewrite_domain_settings_domains'),
            'all_label' => rex_i18n::msg('yrewrite_domain_settings_all_domains'),
            'select' => new rex_yrewrite_domains_select(),
        ];
    }
}
