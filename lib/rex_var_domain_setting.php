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
        $var = $this->getParsedArg('var', null, true);
        if (null === $var) {
            return false;
        }
        return "yrewrite_domain_settings::getValue($var)";
    }
}
