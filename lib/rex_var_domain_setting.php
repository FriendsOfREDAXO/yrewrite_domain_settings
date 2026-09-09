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
    /**
     * Unescaped on purpose, exactly as before 2.4.0.
     *
     * Templates in the field have been storing markup in these values for
     * years - an address block with <br>, a footer text from a rich text
     * field. Escaping here would mangle all of it. REX_DOMAIN_VALUE is the
     * escaping variant for new code.
     */
    protected function getOutput()
    {
        $key = $this->getParsedArg('key', null, true);
        if (null === $key) {
            return false;
        }
        return "yrewrite_domain_settings::getValue($key)";
    }
}
