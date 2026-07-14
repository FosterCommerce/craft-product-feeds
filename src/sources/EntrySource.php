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
		return $this->baseQuery(Entry::STATUS_LIVE)
			->with($this->eagerLoadPaths())
			->orderBy([
				'elements.id' => SORT_ASC,
			]);
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

	public function handles(ElementInterface $element): bool
	{
		return $element instanceof Entry;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function mightContain(ElementInterface $element): bool
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
	public function contains(ElementInterface $element): bool
	{
		if (! $element instanceof Entry) {
			return false;
		}

		return $this->baseQuery(null)->id($element->id)->exists();
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
	 * A source key names an entry type, but URLs are configured per section, so the section is what the
	 * admin is shown.
	 */
	protected function sourceName(string $sourceId): ?string
	{
		$section = Craft::$app->getEntries()->getSectionById($this->sectionIdOf($sourceId));

		return $section instanceof Section ? (string) $section->name : null;
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
		// A `sectionId` on its own is not a pair the picker can post, but it can still be sitting in a row.
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
	 * Every entry this feed covers, at the given status.
	 *
	 * @return EntryQuery<int, Entry>
	 * @throws InvalidConfigException
	 */
	private function baseQuery(?string $status): EntryQuery
	{
		$query = Entry::find()
			->siteId($this->feed->siteId)
			->status($status);

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
		$sections = Craft::$app->getEntries()->getAllSections();

		foreach ($sections as $section) {
			if ($section->type === Section::TYPE_SINGLE) {
				continue;
			}

			$siteSettings = $section->getSiteSettings()[$this->feed->siteId] ?? null;
			if ($section->id !== null && $siteSettings !== null && $siteSettings->hasUrls) {
				$withUrls[] = $section;
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
}
