<?php

$dataset='
{
  "yrewrite_domain_settings": {
    "table": {
      "id": "2",
      "status": "1",
      "table_name": "'. rex::getTablePrefix() .'yrewrite_domain_settings",
      "name": "Domaineinstellungen",
      "description": "",
      "list_amount": "50",
      "list_sortfield": "id",
      "list_sortorder": "ASC",
      "prio": "2",
      "search": "0",
      "hidden": "1",
      "export": "1",
      "import": "1",
      "mass_deletion": "0",
      "mass_edit": "0",
      "schema_overwrite": "1",
      "history": "0"
    },
    "fields": [
      {
        "id": "1",
        "table_name": "'. rex::getTablePrefix() .'yrewrite_domain_settings",
        "prio": "1",
        "type_id": "value",
        "type_name": "choice",
        "db_type": "text",
        "list_hidden": "0",
        "search": "1",
        "name": "domain_id",
        "label": "Domain",
        "not_required": "",
        "choices": "SELECT id AS value, domain AS label FROM rex_yrewrite_domain ORDER BY domain ASC",
        "placeholder": " ",
        "attributes": "{\"class\": \"selectpicker\",\"data-live-search\": \"true\"}",
        "no_db": "0",
        "group_attributes": "",
        "message": "",
        "compare_value": "",
        "compare_type": ""
      },
      {
        "id": "2",
        "table_name": "'. rex::getTablePrefix() .'yrewrite_domain_settings",
        "prio": "2",
        "type_id": "validate",
        "type_name": "unique",
        "db_type": "",
        "list_hidden": "1",
        "search": "0",
        "name": "domain_id",
        "label": "",
        "not_required": "",
        "choices": "",
        "placeholder": "",
        "attributes": "",
        "no_db": "",
        "group_attributes": "",
        "message": "Diese Domain wird bereits verwendet",
        "compare_value": "",
        "compare_type": ""
      }
    ]
  }
}
';
rex_yform_manager_table_api::importTablesets($dataset);
rex_yform_manager_table::deleteCache();
