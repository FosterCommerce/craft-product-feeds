<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use fostercommerce\productfeeds\enums\AttributeKind;

/**
 * A Craft field cannot be constructed without a booted app (`Number::__construct()` reads `Craft::$app`),
 * so these live in the integration suite rather than the unit one.
 */
class AttributeKindTest extends IntegrationTestCase
{
	/**
	 * A `link` has to be a URL. `Url` once fell through to the permissive default arm, which offered the
	 * admin a Lightswitch, a Checkboxes field and an Email field as sources for it.
	 */
	public function testAUrlTakesOnlyAPlainTextField(): void
	{
		$this->assertTrue(AttributeKind::Url->acceptsField(new PlainText()));

		$this->assertFalse(AttributeKind::Url->acceptsField(new Lightswitch()));
		$this->assertFalse(AttributeKind::Url->acceptsField(new Checkboxes()));
		$this->assertFalse(AttributeKind::Url->acceptsField(new Email()));
		$this->assertFalse(AttributeKind::Url->acceptsField(new Categories()));
		$this->assertFalse(AttributeKind::Url->acceptsField(new Dropdown()));
		$this->assertFalse(AttributeKind::Url->acceptsField(new Assets()));
	}

	public function testAnImageTakesOnlyAnAssetsField(): void
	{
		$this->assertTrue(AttributeKind::Image->acceptsField(new Assets()));

		$this->assertFalse(AttributeKind::Image->acceptsField(new PlainText()));
		$this->assertFalse(AttributeKind::Image->acceptsField(new Number()));
	}

	public function testAPriceTakesANumberOrTheTextThatHoldsOne(): void
	{
		$this->assertTrue(AttributeKind::Money->acceptsField(new Number()));
		$this->assertTrue(AttributeKind::Money->acceptsField(new PlainText()));

		$this->assertFalse(AttributeKind::Money->acceptsField(new Dropdown()));
		$this->assertFalse(AttributeKind::Money->acceptsField(new Assets()));
	}

	/**
	 * A text attribute is the permissive one: anything that can produce a string is a valid source.
	 */
	public function testATextAttributeTakesAnyFieldThatProducesAString(): void
	{
		$this->assertTrue(AttributeKind::Text->acceptsField(new PlainText()));
		$this->assertTrue(AttributeKind::Text->acceptsField(new Dropdown()));
		$this->assertTrue(AttributeKind::Text->acceptsField(new Lightswitch()));
		$this->assertTrue(AttributeKind::Text->acceptsField(new Email()));

		$this->assertFalse(AttributeKind::Text->acceptsField(new Assets()));
	}
}
