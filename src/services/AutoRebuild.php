<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\conditions\NotRelatedToConditionRule;
use craft\elements\conditions\RelatedToConditionRule;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\WatchedFields;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Queues a feed rebuild when an element the feed includes is saved or deleted, so the feed does not
 * wait for its scheduled interval to reflect the change.
 */
class AutoRebuild extends Component
{
	/**
	 * Native attributes an entry feed derives from. The list errs wide: a missed change leaves the feed
	 * stale, where a false positive costs a full catalog pass and a republish.
	 */
	private const NATIVE_TRIGGERS = ['title', 'slug', 'uri', 'postDate', 'expiryDate'];

	/**
	 * @var Feed[]|null
	 */
	private ?array $enabledFeeds = null;

	/**
	 * Memoized: an import runs thousands of saves through here, and a feed's mapping and filter cannot
	 * change while it does.
	 *
	 * @var array<int, WatchedFields>
	 */
	private array $watchedFields = [];

	/**
	 * @throws InvalidConfigException
	 */
	public function onSave(ElementInterface $element, bool $isNew): void
	{
		$this->handle($element, false, $isNew);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function onRestore(ElementInterface $element): void
	{
		$this->handle($element, false, true);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function onDelete(ElementInterface $element): void
	{
		$this->handle($element, true, false);
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function handle(ElementInterface $element, bool $deleting, bool $reappearing): void
	{
		// Craft resaves the whole catalog after a field layout edit, which would cost a membership
		// query per element per feed and republish an unchanged feed.
		if ($element->getIsDraft() || $element->getIsRevision() || $element->isProvisionalDraft || $element->propagating || $element->resaving) {
			return;
		}

		if (! $element instanceof Variant && ! $element instanceof Product && ! $element instanceof Entry) {
			return;
		}

		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();
		$feeds = $plugin->getFeeds();

		foreach ($this->enabledFeeds() as $feed) {
			// A queued build has not started, so it will read this change when it runs.
			if ($feeds->isBuildPending((int) $feed->id)) {
				continue;
			}

			try {
				$source = FeedSource::forFeed($feed);
				if ($source->reads($element) && $this->shouldRebuild($feed, $source, $element, $deleting, $reappearing)) {
					$feeds->requestBuild((int) $feed->id);
				}
			} catch (Throwable $throwable) {
				// One feed whose mapping or filter can no longer be resolved must not fail the save that
				// triggered this, nor stop the feeds after it from rebuilding. It rebuilds on its interval.
				Craft::error(sprintf(
					'Product feed “%s” auto-rebuild check failed: %s',
					$feed->handle,
					$throwable->getMessage()
				), ProductFeeds::HANDLE);
			}
		}
	}

	/**
	 * @return Feed[]
	 * @throws InvalidConfigException
	 */
	private function enabledFeeds(): array
	{
		if ($this->enabledFeeds === null) {
			/** @var ProductFeeds $plugin */
			$plugin = ProductFeeds::getInstance();
			$this->enabledFeeds = $plugin->getFeeds()->getEnabledFeeds();
		}

		return $this->enabledFeeds;
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function shouldRebuild(Feed $feed, FeedSource $source, ElementInterface $element, bool $deleting, bool $isNew): bool
	{
		if ($deleting) {
			return $source->mightRead($element);
		}

		if ($isNew) {
			return $source->inScope($element);
		}

		$watched = $this->watchedFields($feed);
		$filterDirty = $this->dirtyField($element, $watched->filter)
			|| ($watched->hasRelationRule && $this->dirtyRelation($element));

		if (! $this->relevantEdit($element, $watched->mapped, $filterDirty)) {
			return false;
		}

		if ($source->inScope($element)) {
			return true;
		}

		return $filterDirty;
	}

	/**
	 * @param list<string> $mappedHandles
	 */
	private function relevantEdit(ElementInterface $element, array $mappedHandles, bool $filterDirty): bool
	{
		if ($filterDirty) {
			return true;
		}

		// Commerce does not set dirty attributes on a Variant, so a price or SKU edit reports none, and a
		// Product's are its own record's. Any purchasable save counts instead.
		if ($element instanceof Variant || $element instanceof Product) {
			return true;
		}

		$attributes = $element->getDirtyAttributes();
		if (in_array('enabledForSite', $attributes, true) || array_intersect($attributes, self::NATIVE_TRIGGERS) !== []) {
			return true;
		}

		return $this->dirtyField($element, $mappedHandles);
	}

	/**
	 * @param list<string> $handles
	 */
	private function dirtyField(ElementInterface $element, array $handles): bool
	{
		return $handles !== [] && array_intersect($element->getDirtyFields(), $handles) !== [];
	}

	private function dirtyRelation(ElementInterface $element): bool
	{
		$layout = $element->getFieldLayout();
		foreach ($element->getDirtyFields() as $handle) {
			if ($layout?->getFieldByHandle($handle) instanceof BaseRelationField) {
				return true;
			}
		}

		return false;
	}

	private function watchedFields(Feed $feed): WatchedFields
	{
		return $this->watchedFields[(int) $feed->id] ??= $this->resolveWatchedFields($feed);
	}

	private function resolveWatchedFields(Feed $feed): WatchedFields
	{
		$mapped = [];
		foreach ($feed->fieldMapping as $row) {
			$parsed = Mapping::parse($row['source']);
			if (in_array($parsed['kind'], [Mapping::FIELD, Mapping::PRODUCT_FIELD], true)) {
				$mapped[] = $parsed['value'];
			}
		}

		$filter = [];
		$hasRelationRule = false;
		$rules = $feed->filterCondition['conditionRules'] ?? [];
		foreach (is_array($rules) ? $rules : [] as $rule) {
			if (! is_array($rule)) {
				continue;
			}

			$fieldUid = $rule['fieldUid'] ?? null;
			if (is_string($fieldUid)) {
				$handle = Craft::$app->getFields()->getFieldByUid($fieldUid)?->handle;
				if ($handle !== null) {
					$filter[] = $handle;
				}
			}

			$class = $rule['class'] ?? null;
			if (is_string($class) && (is_a($class, RelatedToConditionRule::class, true) || is_a($class, NotRelatedToConditionRule::class, true))) {
				$hasRelationRule = true;
			}
		}

		return new WatchedFields(
			array_values(array_unique($mapped)),
			array_values(array_unique($filter)),
			$hasRelationRule,
		);
	}
}
