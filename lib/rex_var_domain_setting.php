<?php 
/**
 * This file is part of the yrewrite_domain_settings package.
 *
 * @author (c) Friends Of REDAXO
 * @author <friendsof@redaxo.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class rex_var_domain_setting extends rex_var
{
    protected function getOutput()
    {
        $key = $this->getParsedArg('key', null, true);
        if (null === $key) {
            return false;
        }

        $translate = $this->getParsedArg('translate', false, true);

        return "yrewrite_domain_settings::getValue($key, $translate)";
    }
}
