<?php

class rex_yrewrite_domains_select extends rex_select
{

    /**
     * @var bool
     */
    private $loaded = false;

    public function __construct()
    {
        parent::__construct();
    }

    public static function getDomains() {
        $aDomains = array();
        $sql = rex_sql::factory();
        $sql->setQuery("SELECT * FROM " . rex::getTable("yrewrite_domain") . " ORDER BY domain ASC");
        foreach ($sql as $oItem) {
            array_push($aDomains,array("domain" => $oItem->getValue("domain"),"id" => $oItem->getValue("id")));
        }
        return $aDomains;
    }

    public function get()
    {
        if (!$this->loaded) {
            $aDomains = $this->getDomains();
            foreach($aDomains AS $aDomain) {
                $this->addOption($aDomain["domain"], $aDomain["id"]);

            }
            $this->loaded = true;
        }

        $this->setAttribute('class', 'selectpicker');
        $this->setAttribute('data-live-search', 'true');

        return parent::get();
    }
}
