<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use Craft;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use yii\base\InvalidConfigException;

final class MappingOptions
{
	/**
	 * @return array<string, array<int, array<string, string>>> attribute name => select options
	 * @throws InvalidConfigException
	 */
	public static function forSource(FeedSource $source, FeedSpec $spec): array
	{
		$unmappable = [...$source->computedAttributes(), ...$spec->derivedAttributes()];
		$options = [];

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			if (in_array($name, $unmappable, true)) {
				continue;
			}

			$options[$name] = self::forAttribute($source, $spec, $attributeDefinition);
		}

		return $options;
	}

	/**
	 * @return array<int, array<string, string>>
	 * @throws InvalidConfigException
	 */
	private static function forAttribute(FeedSource $source, FeedSpec $spec, AttributeDefinition $definition): array
	{
		if ($definition->name === $spec->galleryAttribute()) {
			return array_merge([
				[
					'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.dontInclude'),
					'value' => Mapping::NO_INCLUDE,
				],
				[
					'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.imageOverflow', [
						'attribute' => $spec->imageAttribute(),
					]),
					'value' => Mapping::IMAGE_OVERFLOW,
				],
			], self::fieldOptions($source, $definition));
		}

		$options = [
			$definition->required
				? [
					'label' => '',
					'value' => '',
				]
				: [
					'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.dontInclude'),
					'value' => Mapping::NO_INCLUDE,
				],
			[
				'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.useDefaultValue'),
				'value' => Mapping::USE_DEFAULT,
			],
		];

		// No native element value is an image, so image attributes offer field sources only.
		if ($definition->attributeKind !== AttributeKind::Image) {
			foreach ($source->elementPaths() as $groupLabel => $paths) {
				if ($paths === []) {
					continue;
				}

				$options[] = [
					'optgroup' => Craft::t(ProductFeeds::HANDLE, $groupLabel),
				];

				foreach ($paths as $path => $label) {
					$options[] = [
						'label' => $label,
						'value' => Mapping::build(Mapping::ELEMENT, $path),
					];
				}
			}
		}

		return array_merge($options, self::fieldOptions($source, $definition));
	}

	/**
	 * @return array<int, array<string, string>>
	 * @throws InvalidConfigException
	 */
	private static function fieldOptions(FeedSource $source, AttributeDefinition $definition): array
	{
		$options = [];
		$fieldGroupLabels = $source->fieldGroupLabels();

		foreach ($source->fieldLayouts() as $prefix => $layouts) {
			$fields = [];

			foreach ($layouts as $layout) {
				foreach ($layout->getCustomFields() as $field) {
					if ($field->handle !== null && $definition->attributeKind->acceptsField($field)) {
						$fields[$field->handle] = (string) $field->name;
					}
				}
			}

			if ($fields === []) {
				continue;
			}

			asort($fields);

			$options[] = [
				'optgroup' => Craft::t(ProductFeeds::HANDLE, $fieldGroupLabels[$prefix]),
			];

			foreach ($fields as $handle => $label) {
				$options[] = [
					'label' => $label,
					'value' => Mapping::build($prefix, (string) $handle),
				];
			}
		}

		return $options;
	}
}
