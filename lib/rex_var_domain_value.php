<?php

/**
 * REX_DOMAIN_VALUE[key=…] for templates and modules.
 *
 * Deliberately outside the addon's namespace: REDAXO resolves REX_* variables
 * by class name (`rex_var_` . strtolower(name), see rex_var::getVar()), so the
 * class has to be called exactly this and live in the global namespace.
 *
 * Output is escaped by default, following the core's REX_VALUE convention
 * (rex_var_value): an editor who may fill in these fields must not be able to
 * inject markup into every page that reads them. Pass output="html" to opt out
 * where the value is deliberately HTML.
 */
class rex_var_domain_value extends rex_var
{
    protected function getOutput(): string|false
    {
        $key = $this->getParsedArg('key', null, true);

        if (null === $key) {
            return false;
        }

        $call = 'FriendsOfRedaxo\\DomainSettings\\DomainSettings::get(' . $key . ')';

        return 'html' === $this->getArg('output') ? $call : 'rex_escape(' . $call . ')';
    }
}
