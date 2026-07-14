<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementCondition;
use craft\helpers\Component;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use yii\base\InvalidConfigException;

/**
 * The condition builder on the edit screen, and what it posts back.
 */
final class FilterCondition
{
	/**
	 * @throws InvalidConfigException
	 */
	public static function builder(Feed $feed, FeedSource $source): ElementCondition
	{
		$condition = self::fromConfig($source->conditionElementType(), $feed->filterCondition);
		$condition->mainTag = 'div';
		$condition->name = 'filterCondition';
		$condition->addRuleLabel = Craft::t(ProductFeeds::HANDLE, 'filter.addRule');

		return $condition;
	}

	/**
	 * Built against the element type the builder rendered against, not the item's type: Craft drops any
	 * rule whose element type has no matching field, which would lose a variant feed's product-field rules.
	 *
	 * @return array<string, mixed> config to store on the feed
	 */
	public static function posted(FeedSource $source, mixed $posted): array
	{
		$rules = is_array($posted) ? ($posted['conditionRules'] ?? []) : [];

		return self::fromConfig($source->conditionElementType(), [
			'conditionRules' => is_array($rules) ? $rules : [],
		])->getConfig();
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @param array<string, mixed> $config
	 */
	public static function fromConfig(string $elementType, array $config): ElementCondition
	{
		// Stored config carries `class` from getConfig(), and the builder posts a `config` input. Neither is
		// a settable property on ElementCondition, so passing them through would throw.
		unset($config['class'], $config['config']);

		return new ElementCondition($elementType, Component::cleanseConfig($config));
	}
}
