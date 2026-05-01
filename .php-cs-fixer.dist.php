<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
	->in(__DIR__.'/src')
	->in(__DIR__.'/tests')
;

return (new PhpCsFixer\Config())
	->setRules([
		'@PER-CS2.0' => true,
		'declare_strict_types' => true,
		'no_unused_imports' => true,
		'ordered_imports' => ['sort_algorithm' => 'alpha'],
		'concat_space' => ['spacing' => 'none'],
		'array_syntax' => ['syntax' => 'short'],
		'single_quote' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
		'global_namespace_import' => [
			'import_classes' => true,
			'import_constants' => false,
			'import_functions' => false,
		],
	])
	->setIndent("\t")
	->setLineEnding("\n")
	->setFinder($finder)
	->setRiskyAllowed(true)
;
