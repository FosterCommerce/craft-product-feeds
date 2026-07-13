<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\StandardAttribute;
use fostercommerce\productfeeds\helpers\Mapping;
use yii\base\InvalidConfigException;

/**
 * Craft entries, for stores whose advertisable item is a landing page rather than a Commerce product.
 *
 * Price and availability are mapped rather than derived: an entry has neither.
 */
class EntrySource extends FeedSource
{
	/**
	 * @throws InvalidConfigException
	 */
	public function query(): ElementQueryInterface
	{
		$query = Entry::find()
			->siteId($this->feed->siteId)
			->status(Entry::STATUS_LIVE)
			->with($this->eagerLoadPaths())
			->orderBy([
				'elements.id' => SORT_ASC,
			]);

		$this->applySourceIds($query);
		$this->filterCondition()?->modifyQuery($query);

		return $query;
	}

	/**
	 * `item_group_id`, `sale_price` and `sale_price_effective_date` have no entry equivalent. They
	 * are listed as computed so they never appear on the mapping screen, and compute to null.
	 */
	public function computedAttributes(): array
	{
		return ['id', 'item_group_id', 'sale_price', 'sale_price_effective_date'];
	}

	public function compute(ElementInterface $element, string $attribute): string|array|null
	{
		return $attribute === 'id' ? (string) $element->id : null;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function fieldLayouts(): array
	{
		$layouts = [];

		foreach ($this->entryTypes() as $entryType) {
			$layouts[] = $entryType->getFieldLayout();
		}

		return [
			Mapping::FIELD => $layouts,
		];
	}

	public function elementType(): string
	{
		return Entry::class;
	}

	public function conditionElementType(): string
	{
		return Entry::class;
	}

	public function reads(ElementInterface $element): bool
	{
		return $element instanceof Entry;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function mightRead(ElementInterface $element): bool
	{
		if (! $element instanceof Entry || $element->sectionId === null) {
			return false;
		}

		return in_array(
			$this->sourceKey($element->sectionId, $element->typeId),
			$this->effectiveSourceIds(),
			true
		);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function inScope(ElementInterface $element): bool
	{
		if (! $element instanceof Entry) {
			return false;
		}

		return $this->scopeQuery()->id($element->id)->exists();
	}

	public function reportRow(ElementInterface $element, string $issue): array
	{
		return [
			'id' => (string) $element->id,
			'title' => $element->title ?? '',
			'cpUrl' => $element->getCpEditUrl() ?? '',
			'issue' => $issue,
		];
	}

	public function fieldGroupLabels(): array
	{
		return [
			Mapping::FIELD => 'mapping.entryFields',
		];
	}

	public function defaultMapping(): array
	{
		return [
			'title' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'title'),
				'default' => '',
			],
			'link' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'url'),
				'default' => '',
			],
			StandardAttribute::Availability->value => [
				'source' => Mapping::USE_DEFAULT,
				'default' => Availability::InStock->value,
			],
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
		];
	}

	public function elementPaths(): array
	{
		return [
			'mapping.entryProperties' => [
				'title' => 'Title',
				'url' => 'URL',
				'slug' => 'Slug',
				'id' => 'Entry ID',
			],
		];
	}

	public function selectableSourceIds(): array
	{
		$sourceIds = [];

		foreach ($this->selectableSourceGroups() as $group) {
			foreach ($group['options'] as $option) {
				$sourceIds[] = $option['value'];
			}
		}

		return $sourceIds;
	}

	public function selectableSourceGroups(): array
	{
		$groups = [];

		foreach ($this->sectionsWithUrls() as $section) {
			$options = [];

			foreach ($section->getEntryTypes() as $entryType) {
				$options[] = [
					'value' => $this->sourceKey((int) $section->id, $entryType->id),
					'label' => (string) $entryType->name,
				];
			}

			$groups[] = [
				'heading' => (string) $section->name,
				'options' => $options,
			];
		}

		return $groups;
	}

	/**
	 * Only reports explicitly chosen entry types. An empty choice means "everything that can work".
	 */
	public function sourcesWithoutUrls(): array
	{
		if ($this->feed->sourceIds === []) {
			return [];
		}

		$withUrls = $this->selectableSourceIds();
		$names = [];

		foreach ($this->feed->sourceIds as $sourceId) {
			if (in_array($sourceId, $withUrls, true)) {
				continue;
			}

			$section = Craft::$app->getEntries()->getSectionById($this->sectionIdOf($sourceId));
			if ($section instanceof Section) {
				$names[] = (string) $section->name;
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * An entry type can sit on several sections, so a feed picks the pair. This is the form the picker
	 * posts.
	 */
	private function sourceKey(int $sectionId, ?int $entryTypeId): string
	{
		return sprintf('%d:%d', $sectionId, $entryTypeId ?? 0);
	}

	private function sectionIdOf(string $sourceId): int
	{
		return (int) explode(':', $sourceId)[0];
	}

	private function entryTypeIdOf(string $sourceId): int
	{
		return (int) (explode(':', $sourceId)[1] ?? '');
	}

	/**
	 * A nested Matrix entry has no section, so it can never satisfy one of these pairs and never
	 * reaches the feed.
	 *
	 * @param EntryQuery<int, Entry> $query
	 * @throws InvalidConfigException
	 */
	private function applySourceIds(EntryQuery $query): void
	{
		$pairs = ['or'];

		foreach ($this->effectiveSourceIds() as $sourceId) {
			$pairs[] = [
				'entries.sectionId' => $this->sectionIdOf($sourceId),
				'entries.typeId' => $this->entryTypeIdOf($sourceId),
			];
		}

		// An empty OR builds no condition at all, which would feed every entry on the site.
		$query->andWhere($pairs === ['or'] ? '1 = 0' : $pairs);
	}

	/**
	 * @return EntryQuery<int, Entry>
	 * @throws InvalidConfigException
	 */
	private function scopeQuery(): EntryQuery
	{
		$query = Entry::find()
			->siteId($this->feed->siteId)
			->status(null);
		$this->applySourceIds($query);
		$this->filterCondition()?->modifyQuery($query);

		return $query;
	}

	/**
	 * Singles are excluded: a single holds one entry, so it cannot be the source of a catalog.
	 *
	 * @return list<Section>
	 */
	private function sectionsWithUrls(): array
	{
		$withUrls = [];

		foreach (Craft::$app->getEntries()->getAllSections() as $allSection) {
			if ($allSection->type === Section::TYPE_SINGLE) {
				continue;
			}

			$siteSettings = $allSection->getSiteSettings()[$this->feed->siteId] ?? null;
			if ($allSection->id !== null && $siteSettings !== null && $siteSettings->hasUrls) {
				$withUrls[] = $allSection;
			}
		}

		return $withUrls;
	}

	/**
	 * @return list<EntryType>
	 * @throws InvalidConfigException
	 */
	private function entryTypes(): array
	{
		$entries = Craft::$app->getEntries();
		$entryTypes = [];

		foreach ($this->effectiveSourceIds() as $sourceId) {
			$entryType = $entries->getEntryTypeById($this->entryTypeIdOf($sourceId));
			if ($entryType instanceof EntryType) {
				$entryTypes[(int) $entryType->id] = $entryType;
			}
		}

		return array_values($entryTypes);
	}

	/**
	 * @return string[]
	 */
	private function eagerLoadPaths(): array
	{
		$paths = [];

		foreach ($this->feed->fieldMapping as $mapping) {
			$parsed = Mapping::parse($mapping['source']);
			if ($parsed['kind'] === Mapping::FIELD) {
				$paths[] = $parsed['value'];
			}
		}

		return array_values(array_unique($paths));
	}
}
