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
use fostercommerce\productfeeds\enums\ElementChange;
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
		$this->queueBuildsFor($element, $isNew ? ElementChange::Created : ElementChange::Updated);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function onRestore(ElementInterface $element): void
	{
		$this->queueBuildsFor($element, ElementChange::Created);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function onDelete(ElementInterface $element): void
	{
		$this->queueBuildsFor($element, ElementChange::Deleted);
	}

	/**
	 * Queues a build of every enabled feed this element belongs to.
	 *
	 * @throws InvalidConfigException
	 */
	private function queueBuildsFor(ElementInterface $element, ElementChange $change): void
	{
		// Drafts, revisions and propagated saves are not the live element. `resaving` is Craft re-saving the
		// whole catalog after a field layout edit: a membership query per element per feed, and a republish
		// of a feed nothing changed in.
		if ($element->getIsDraft() || $element->getIsRevision() || $element->isProvisionalDraft || $element->propagating || $element->resaving) {
			return;
		}

		if (! $element instanceof Variant && ! $element instanceof Product && ! $element instanceof Entry) {
			return;
		}

		$buildQueue = ProductFeeds::plugin()->getBuildQueue();

		foreach ($this->enabledFeeds() as $feed) {
			// A queued build has not started, so it will read this change when it runs.
			if ($buildQueue->isBuildPending((int) $feed->id)) {
				continue;
			}

			try {
				$source = FeedSource::forFeed($feed);
				if ($source->handles($element) && $this->shouldRebuild($feed, $source, $element, $change)) {
					$buildQueue->requestBuild((int) $feed->id);
				}
			} catch (Throwable $throwable) {
				// A feed whose mapping or filter no longer resolves must not fail the save, nor block the feeds
				// after it. It rebuilds on its interval instead.
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
			$plugin = ProductFeeds::plugin();
			$this->enabledFeeds = $plugin->getFeeds()->getEnabledFeeds();
		}

		return $this->enabledFeeds;
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function shouldRebuild(Feed $feed, FeedSource $source, ElementInterface $element, ElementChange $change): bool
	{
		if ($change === ElementChange::Deleted) {
			return $source->mightContain($element);
		}

		// A created element has no edit to judge, so membership is the whole question.
		if ($change === ElementChange::Created) {
			return $source->contains($element);
		}

		$watched = $this->watchedFields($feed);

		// The filter reads different values now, so the element may have just joined or left the feed.
		// Either way it has to rebuild, whether or not the element is still in scope.
		if (
			$this->hasDirtyField($element, $watched->filter)
			|| ($watched->hasRelationRule && $this->hasDirtyRelation($element))
		) {
			return true;
		}

		return $this->hasRelevantEdit($element, $watched->mapped) && $source->contains($element);
	}

	/**
	 * @param list<string> $mappedHandles
	 */
	private function hasRelevantEdit(ElementInterface $element, array $mappedHandles): bool
	{
		// Commerce does not set dirty attributes on a Variant, so a price or SKU edit reports none, and a
		// Product's are its own record's. Any purchasable save counts instead.
		if ($element instanceof Variant || $element instanceof Product) {
			return true;
		}

		$attributes = $element->getDirtyAttributes();
		if (in_array('enabledForSite', $attributes, true) || array_intersect($attributes, self::NATIVE_TRIGGERS) !== []) {
			return true;
		}

		return $this->hasDirtyField($element, $mappedHandles);
	}

	/**
	 * @param list<string> $handles
	 */
	private function hasDirtyField(ElementInterface $element, array $handles): bool
	{
		return $handles !== [] && array_intersect($element->getDirtyFields(), $handles) !== [];
	}

	private function hasDirtyRelation(ElementInterface $element): bool
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
