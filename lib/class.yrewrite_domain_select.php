<?php

class rex_yrewrite_domains_select extends rex_select
{
    private bool $loaded = false;

    /** @return list<array{domain: string, id: string}> */
    public static function getDomains()
    {
        $aDomains = [];
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('yrewrite_domain') . ' ORDER BY domain ASC');
        foreach ($sql as $oItem) {
            $aDomains[] = [
                'domain' => (string) $oItem->getValue('domain'),
                'id' => (string) $oItem->getValue('id'),
            ];
        }
        return $aDomains;
    }

    public function get()
    {
        if (!$this->loaded) {
            $aDomains = $this->getDomains();
            foreach ($aDomains as $aDomain) {
                $this->addOption($aDomain['domain'], $aDomain['id']);
            }
            $this->loaded = true;
        }

        $this->setAttribute('class', 'selectpicker');
        $this->setAttribute('data-live-search', 'true');

        return parent::get();
    }
}
