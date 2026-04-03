<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                         => true,
        'array_syntax'                   => ['syntax' => 'short'],
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'              => true,
        'single_quote'                   => true,
        'trailing_comma_in_multiline'    => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_extra_blank_lines'           => true,
        'blank_line_after_namespace'     => true,
        'visibility_required'            => ['elements' => ['property', 'method', 'const']],
        'modernize_types_casting'        => true,
        'phpdoc_trim'                    => true,
        'binary_operator_spaces'         => [
            'operators' => [
                '=>' => 'align_single_space_minimal',
                '='  => 'single_space',
            ],
        ],
        'concat_space'                   => ['spacing' => 'one'],
        'phpdoc_line_span'               => [
            'const'    => 'multi',
            'property' => 'multi',
            'method'   => 'multi',
        ],
    ])
    ->setFinder($finder);
