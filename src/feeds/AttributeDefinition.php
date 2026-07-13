<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\AttributeKind;

readonly class AttributeDefinition
{
	/**
	 * @param string $name attribute handle, used as the mapping key and the form input name
	 * @param list<string> $values the only values the platform accepts, or empty for free text
	 * @param string|null $note translation key for a caveat the mapping screen shows in an info bubble
	 * @param int|null $maxLength characters the platform accepts, where truncating still leaves a
	 * usable value
	 */
	public function __construct(
		public string $name,
		public AttributeKind $attributeKind = AttributeKind::Text,
		public bool $required = false,
		public array $values = [],
		public ?string $note = null,
		public ?int $maxLength = null,
	) {
	}
}
