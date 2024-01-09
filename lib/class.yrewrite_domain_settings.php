<?php

class yrewrite_domain_settings
{
    private static $instance = null;
    private $addon;
    private $domain;

    private function __construct()
    {
        $this->addon = rex_addon::get('yrewrite_domain_settings');
        $this->domain = rex_yrewrite::getDomainByArticleId(rex_article::getCurrentId(), rex_clang::getCurrentId());
    }

    public static function getInstance(): YRewriteDomainSettings
    {
        if (self::$instance === null) {
            self::$instance = new yrewrite_domain_settings();
        }
        return self::$instance;
    }

    public static function getValue(string $key = ''): ?array
    {
        $settings = self::getInstance();

        $domainId = $settings->domain->getId();
        if (!$domainId) {
            return null;
        }

        $query = rex_yform_manager_table::get('yrewrite_domain_settings')->query();
        $query->where('domain_id', $domainId);
        $item = $query->findOne();

        if (!$item || !$item->getValue('id')) {
            return null;
        }

        return $key !== '' ? $item->getValue($key) : $item->getData();
    }

    public static function getAllowedDomains(): array
    {
        $allDomains = rex_yrewrite_domains_select::getDomains();
        $user = rex::getUser();

        if ($user->isAdmin() || $user->getComplexPerm('yrewrite_domains')->getDomains() === 'all') {
            return $allDomains;
        }

        $allowedDomains = $user->getComplexPerm('yrewrite_domains')->getDomains();
        return array_filter($allDomains, function($domain) use ($allowedDomains) {
            return in_array($domain['id'], $allowedDomains, true);
        });
    }
}
