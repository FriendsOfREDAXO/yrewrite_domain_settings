<?php class yrewrite_domain_settings
{


    public function __construct()
    {
        $this->addon = rex_addon::get(rex::getTablePrefix().'yrewrite_domain_settings');
        $this->domain = rex_yrewrite::getCurrentDomain();
    }

    public static function getValue($sKey = null)
    {

        $oSettings = new yrewrite_domain_settings;

        $iDomainsId = $oSettings->domain->getId();

        if (!$iDomainsId) {
            return;
        }

        $oQuery = rex_yform_manager_table::get(rex::getTablePrefix().'yrewrite_domain_settings')->query();
        $oQuery->where('domains_id', $iDomainsId, '=');
        $oItem = $oQuery->findOne();
        if (!count($oItem)) {
            return;
        }

        if (!$oItem->getValue('id')) {
            return;
        }

        if ($sKey != "") {
            return $oItem->getValue($sKey);
        } else {
            return $oItem->getData();
        }
    }
}

