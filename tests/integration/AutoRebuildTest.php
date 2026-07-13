<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Entry;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\sources\FeedSource;

class AutoRebuildTest extends IntegrationTestCase
{
	/**
	 * Saving a product a feed contains queues a build, so a price change reaches the platform without
	 * waiting for the next scheduled one.
	 */
	public function testSavingAProductInAFeedQueuesABuild(): void
	{
		$feed = $this->enabledVariantFeed('inFeed');
		$variant = $this->firstVariantIn($feed);

		$this->plugin()->getAutoRebuild()->onSave($this->productOf($variant), false);

		$this->assertTrue($this->feeds()->isBuildPending((int) $feed->id));
	}

	/**
	 * A burst of saves, which is what one product save looks like from here, collapses into one build.
	 */
	public function testABurstOfSavesQueuesOneBuild(): void
	{
		$feed = $this->enabledVariantFeed('burst');
		$variant = $this->firstVariantIn($feed);
		$product = $this->productOf($variant);

		$autoRebuild = $this->plugin()->getAutoRebuild();
		$autoRebuild->onSave($product, false);
		$autoRebuild->onSave($variant, false);
		$autoRebuild->onSave($product, false);

		$this->assertTrue($this->feeds()->isBuildPending((int) $feed->id));
		$this->assertSame(1, $this->queuedBuildCount(), 'Three saves of one product should queue one build.');
	}

	/**
	 * Craft resaves the whole catalog after a field layout edit. Rebuilding per element would cost a
	 * membership query per feed and republish an unchanged feed.
	 */
	public function testAResaveDoesNotQueueABuild(): void
	{
		$feed = $this->enabledVariantFeed('resaving');
		$product = $this->productOf($this->firstVariantIn($feed));
		$product->resaving = true;

		$this->plugin()->getAutoRebuild()->onSave($product, false);

		$this->assertFalse($this->feeds()->isBuildPending((int) $feed->id));
	}

	public function testAPropagatingSaveDoesNotQueueABuild(): void
	{
		$feed = $this->enabledVariantFeed('propagating');
		$product = $this->productOf($this->firstVariantIn($feed));
		$product->propagating = true;

		$this->plugin()->getAutoRebuild()->onSave($product, false);

		$this->assertFalse($this->feeds()->isBuildPending((int) $feed->id));
	}

	/**
	 * An entry feed does not read products, so a product save is not its business.
	 */
	public function testAFeedIgnoresAnElementTypeItDoesNotRead(): void
	{
		$variantFeed = $this->enabledVariantFeed('readsVariants');
		$entryFeed = $this->makeFeed('readsEntries', [
			'source' => Source::Entries->value,
			'enabled' => true,
		]);

		$product = $this->productOf($this->firstVariantIn($variantFeed));

		$this->assertTrue(FeedSource::forFeed($variantFeed)->reads($product));
		$this->assertFalse(FeedSource::forFeed($entryFeed)->reads($product));

		$entry = Entry::find()->one();
		if ($entry instanceof Entry) {
			$this->assertFalse(FeedSource::forFeed($variantFeed)->reads($entry));
		}
	}

	/**
	 * A build that has already been queued has not started, so it will read this change when it runs.
	 */
	public function testAnAlreadyQueuedBuildAbsorbsFurtherEdits(): void
	{
		$feed = $this->enabledVariantFeed('absorbs');
		$product = $this->productOf($this->firstVariantIn($feed));

		$this->feeds()->requestBuild((int) $feed->id);
		$queuedBefore = $this->queuedBuildCount();

		$this->plugin()->getAutoRebuild()->onSave($product, false);

		$this->assertSame($queuedBefore, $this->queuedBuildCount());
	}

	/**
	 * A deleted element is trashed, so an element query no longer returns it and membership cannot be
	 * tested. The coarse check off its product type stands in, so removing a product still rebuilds.
	 */
	public function testDeletingAProductInAFeedQueuesABuild(): void
	{
		$feed = $this->enabledVariantFeed('delete');
		$product = $this->productOf($this->firstVariantIn($feed));

		$this->assertTrue(FeedSource::forFeed($feed)->mightRead($product));

		$this->plugin()->getAutoRebuild()->onDelete($product);

		$this->assertTrue($this->feeds()->isBuildPending((int) $feed->id));
	}

	private function enabledVariantFeed(string $handle): Feed
	{
		return $this->makeFeed($handle, [
			'enabled' => true,
		]);
	}

	private function firstVariantIn(Feed $feed): Variant
	{
		$variant = FeedSource::forFeed($feed)->query()->one();

		if (! $variant instanceof Variant) {
			$this->markTestSkipped('This install has no live variants.');
		}

		return $variant;
	}

	private function productOf(Variant $variant): Product
	{
		$product = $variant->getProduct();

		if (! $product instanceof Product) {
			$this->markTestSkipped('This install has a variant with no product.');
		}

		return $product;
	}
}
