<?php class yrewrite_domain_settings
{


    public function __construct()
    {
        $this->addon = rex_addon::get(rex::getTablePrefix().'yrewrite_domain_settings');
        $this->domain = rex_yrewrite::getDomainByArticleId(rex_article::getCurrentId(), rex_clang::getCurrentId());
    }

    public static function getValue($sKey = null, $sTranslate = false)
    {

        $oSettings = new yrewrite_domain_settings;

        $iDomainsId = $oSettings->domain->getId();

        if (!$iDomainsId) {
            return;
        }

        $oQuery = rex_yform_manager_table::get(rex::getTablePrefix().'yrewrite_domain_settings')->query();
        $oQuery->where('domain_id', $iDomainsId, '=');

        if ($sTranslate) {
            $oQuery->where('domain_lang', rex_clang::getCurrentId(), '=');
        }
        else {
            $oQuery->where('domain_lang', rex_clang::getStartId(), '=');
        }

        $oItem = $oQuery->findOne();
        if (!$oItem) {
            return;
        }

        if (!$oItem->getValue('id')) {
            return;
        }

        if ($sKey != "") {
            $keyValue = $oItem->getValue($sKey);
            // Fallback
            if ($keyValue === '' && $sTranslate) {
                $keyValue = self::getValue($sKey);
            }
            return $keyValue;
        } else {
            return $oItem->getData();
        }
    }

    public static function getAllowedDomains() {
        $aAllDomains = rex_yrewrite_domains_select::getDomains();
        if (rex::getUser()->getComplexPerm('yrewrite_domains')->getDomains() == "all" OR rex::getUser()->isAdmin()) {
            return $aAllDomains;
        }

        $aAllowedDomains = rex::getUser()->getComplexPerm('yrewrite_domains')->getDomains();
        $aDomains = array();
        foreach($aAllDomains AS $aDomain) {
            if (in_array($aDomain["id"],$aAllowedDomains)) {
                array_push($aDomains,$aDomain);
            }
        }
        return $aDomains;
    }
}
