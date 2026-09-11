<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use Redaxo\PhpCsFixerConfig\Config;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
;

return Config::redaxo5()
    ->setFinder($finder)
;
