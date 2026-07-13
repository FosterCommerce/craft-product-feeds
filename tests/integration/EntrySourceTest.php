<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use craft\elements\Entry;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\sources\EntrySource;
use fostercommerce\productfeeds\sources\FeedSource;

class EntrySourceTest extends IntegrationTestCase
{
	/**
	 * The source IDs go into the query as an OR of section/type pairs. An empty OR builds no condition
	 * at all, which would put every entry on the site into the feed. The guard is the one line between a
	 * misconfigured feed and publishing the whole CMS, so it is asserted against the real query.
	 */
	public function testAFeedWithNoUsableSourcesReadsNothing(): void
	{
		$feed = $this->makeFeed('entriesNone', [
			'source' => Source::Entries->value,
			'sourceIds' => ['0:0'],
		]);

		$source = FeedSource::forFeed($feed);
		$this->assertInstanceOf(EntrySource::class, $source);

		$this->assertSame([], $source->query()->ids());
	}

	/**
	 * `effectiveSourceIds()` falls back to every source that can work, so an empty choice must not be
	 * read as "no sources" and hit the guard above.
	 */
	public function testAnEmptyChoiceMeansEverySourceThatCanWork(): void
	{
		$feed = $this->makeFeed('entriesAll', [
			'source' => Source::Entries->value,
			'sourceIds' => [],
		]);

		$source = FeedSource::forFeed($feed);

		$this->assertSame($source->selectableSourceIds(), $source->effectiveSourceIds());
	}

	/**
	 * The picker posts `sectionId:entryTypeId`, because one entry type can sit on several sections, and
	 * a feed reads only the pair it was given.
	 */
	public function testASourceIdNamesASectionAndEntryTypePair(): void
	{
		$feed = $this->makeFeed('entriesPair', [
			'source' => Source::Entries->value,
		]);

		$source = FeedSource::forFeed($feed);
		$selectable = $source->selectableSourceIds();

		if ($selectable === []) {
			$this->markTestSkipped('This install has no section with URLs.');
		}

		foreach ($selectable as $sourceId) {
			$this->assertMatchesRegularExpression('/^\d+:\d+$/', $sourceId);
		}

		// Reading one pair only returns entries of that section and type.
		$feed->sourceIds = [$selectable[0]];
		[$sectionId, $entryTypeId] = array_map(intval(...), explode(':', $selectable[0]));

		/** @var Entry[] $entries */
		$entries = FeedSource::forFeed($feed)->query()->limit(5)->all();

		foreach ($entries as $entry) {
			$this->assertSame($sectionId, $entry->sectionId);
			$this->assertSame($entryTypeId, $entry->typeId);
		}
	}

	/**
	 * An entry feed has no Commerce purchasable behind it, so it derives only `id`, and `price` and
	 * `availability` are the admin's to map.
	 */
	public function testAnEntryFeedDerivesOnlyItsId(): void
	{
		$feed = new Feed([
			'source' => Source::Entries->value,
			'siteId' => $this->primarySiteId(),
		]);

		$computed = FeedSource::forFeed($feed)->computedAttributes();

		$this->assertContains('id', $computed);
		$this->assertNotContains('price', $computed);
		$this->assertNotContains('availability', $computed);
	}
}
