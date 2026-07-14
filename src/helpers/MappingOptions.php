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
		$options = [];

		foreach ($source->mappableAttributes($spec) as $name => $attributeDefinition) {
			$options[$name] = self::forAttribute($source, $spec, $attributeDefinition);
		}

		return $options;
	}

	/**
	 * @return array<int, array<string, string>>
	 * @throws InvalidConfigException
	 */
	private static function forAttribute(FeedSource $source, FeedSpec $spec, AttributeDefinition $attributeDefinition): array
	{
		// A required attribute cannot be left out, so its row opens blank instead: the admin has to choose.
		$options = [
			$attributeDefinition->required
				? [
					'label' => '',
					'value' => '',
				]
				: [
					'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.dontInclude'),
					'value' => Mapping::NO_INCLUDE,
				],
		];

		// The gallery fills itself from the main image's leftovers or from a field of its own, so it takes
		// neither a default nor an element value.
		if ($attributeDefinition->name === $spec->galleryAttribute()) {
			$options[] = [
				'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.imageOverflow', [
					'attribute' => $spec->imageAttribute(),
				]),
				'value' => Mapping::IMAGE_OVERFLOW,
			];

			return array_merge($options, self::fieldOptions($source, $attributeDefinition));
		}

		$options[] = [
			'label' => Craft::t(ProductFeeds::HANDLE, 'mapping.useDefaultValue'),
			'value' => Mapping::USE_DEFAULT,
		];

		// No native element value is an image, so image attributes offer field sources only.
		if ($attributeDefinition->attributeKind !== AttributeKind::Image) {
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

		return array_merge($options, self::fieldOptions($source, $attributeDefinition));
	}

	/**
	 * @return array<int, array<string, string>>
	 * @throws InvalidConfigException
	 */
	private static function fieldOptions(FeedSource $source, AttributeDefinition $attributeDefinition): array
	{
		$options = [];
		$fieldGroupLabels = $source->fieldGroupLabels();

		foreach ($source->fieldLayouts() as $prefix => $layouts) {
			$fields = [];

			foreach ($layouts as $layout) {
				foreach ($layout->getCustomFields() as $field) {
					if ($field->handle !== null && $attributeDefinition->attributeKind->acceptsField($field)) {
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
