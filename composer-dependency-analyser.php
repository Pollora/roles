<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // The discovery package brings spatie/php-structure-discoverer: its data classes are part of discovery's contract.
    ->ignoreErrorsOnPackage('spatie/php-structure-discoverer', [ErrorType::SHADOW_DEPENDENCY]);
