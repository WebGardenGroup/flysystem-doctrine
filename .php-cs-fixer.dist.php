<?php

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'phpdoc_order' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'phpdoc_to_comment' => [
            'ignored_tags' => [
                'phpstan-use',
                'phpstan-var',
            ],
        ],
        'single_line_throw' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in(__DIR__)
            ->exclude('var')
            ->exclude('node_modules')
            ->exclude('vendor')
    );
