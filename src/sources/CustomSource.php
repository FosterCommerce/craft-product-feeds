<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use Craft;
use craft\base\ElementInterface;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\ProductFeeds;
use Throwable;

/**
 * A source registered through `ProductFeeds::EVENT_REGISTER_SOURCES`. Its `query()` and `items()` choose the
 * elements and items, so its feeds have no type picker or filter.
 */
abstract class CustomSource extends FeedSource
{
	/**
	 * Stored on the feed. Changing it leaves existing feeds on a missing source.
	 */
	abstract public static function handle(): string;

	abstract public static function displayName(): string;

	/**
	 * Describe the variables from the first element's first item, so the list matches what `items()` returns.
	 */
	public function twigVariables(): array
	{
		$suppliedItem = new SuppliedItem();

		// Log a failing query or items() instead of throwing, since the edit screen renders this list.
		try {
			$element = $this->query()->one();
			if ($element instanceof ElementInterface) {
				$this->prepareBatch([$element]);
				$suppliedItem = $this->items($element)[0] ?? $suppliedItem;
			}
		} catch (Throwable $throwable) {
			Craft::warning(sprintf('Product feed source “%s” could not list its Twig variables: %s', static::handle(), $throwable->getMessage()), ProductFeeds::HANDLE);
		}

		$variables = [
			'object' => $this->elementType()::displayName(),
			'item' => $this->describe($suppliedItem->values),
		];

		foreach ($suppliedItem->variables as $name => $value) {
			$variables[$name] = $this->describe($value);
		}

		return $variables;
	}

	public function compute(ElementInterface $element, string $attribute): string|array|null
	{
		return null;
	}

	public function fieldGroupLabels(): array
	{
		return [
			Mapping::FIELD => 'mapping.fields',
		];
	}

	/**
	 * The properties every element has. A source whose elements have more can override this.
	 */
	public function elementPaths(): array
	{
		return [
			'mapping.properties' => [
				'title' => 'Title',
				'url' => 'URL',
				'slug' => 'Slug',
				'id' => 'ID',
			],
		];
	}

	public function defaultMapping(): array
	{
		return [];
	}

	public function selectableSourceGroups(): array
	{
		return [];
	}

	public function conditionElementType(): string
	{
		return $this->elementType();
	}

	public function mightContain(ElementInterface $element): bool
	{
		return $this->handles($element);
	}

	public function reportRow(ElementInterface $element, string $issue): array
	{
		return [
			'id' => (string) $element->id,
			'title' => (string) $element,
			'cpUrl' => $element->getCpEditUrl() ?? '',
			'issue' => $issue,
		];
	}

	protected function sourceName(string $sourceId): ?string
	{
		return null;
	}

	private function describe(mixed $value): string
	{
		return match (true) {
			$value instanceof ElementInterface => $value::displayName(),
			is_array($value) => Craft::t(ProductFeeds::HANDLE, 'twig.keys', [
				'keys' => implode(', ', array_keys($value)),
			]),
			default => get_debug_type($value),
		};
	}
}
