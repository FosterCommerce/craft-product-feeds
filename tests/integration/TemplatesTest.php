<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * No other tool in the project parses Twig, so a template can be syntactically broken while PHPStan, ECS,
 * Rector and every other test report clean. The CP is most of this plugin's surface.
 */
class TemplatesTest extends IntegrationTestCase
{
	public function testEveryTemplateCompiles(): void
	{
		$twig = Craft::$app->getView()->getTwig();
		$templateDirectory = dirname(__DIR__, 2) . '/src/templates';
		$compiled = 0;

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDirectory));

		foreach ($files as $file) {
			if (! $file instanceof SplFileInfo) {
				continue;
			}

			if ($file->getExtension() !== 'twig') {
				continue;
			}

			$path = $file->getPathname();
			$name = substr($path, strlen($templateDirectory) + 1);

			try {
				$twig->parse($twig->tokenize(new Source((string) file_get_contents($path), $name)));
				$compiled++;
			} catch (SyntaxError $syntaxError) {
				$this->fail(sprintf('%s does not compile: %s', $name, $syntaxError->getMessage()));
			}
		}

		$this->assertGreaterThan(0, $compiled, 'No templates were found to compile.');
	}
}
