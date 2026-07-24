<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\models\Feed;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase
{
	public function testTheSpecFollowsAPlatformChange(): void
	{
		$feed = $this->feed(Platform::Google);

		$this->assertSame('xml', $feed->getSpec()->fileExtension());

		$feed->platform = Platform::Klaviyo->value;

		$this->assertSame('json', $feed->getSpec()->fileExtension());
	}

	/**
	 * The stored artifact is named from the extension, so a stale spec strands the published file.
	 */
	public function testThePathFollowsAPlatformChange(): void
	{
		$feed = $this->feed(Platform::Google);
		$googlePath = $feed->getPath();

		$feed->platform = Platform::Klaviyo->value;

		$this->assertNotSame($googlePath, $feed->getPath());
		$this->assertStringEndsWith('.json.gz', $feed->getPath());
	}

	/**
	 * `clone` carries the resolved spec over, so a copy given a new platform would keep the old one.
	 */
	public function testACloneResolvesItsOwnSpec(): void
	{
		$feed = $this->feed(Platform::Google);
		$feed->getSpec();

		$duplicate = clone $feed;
		$duplicate->platform = Platform::Klaviyo->value;

		$this->assertSame('json', $duplicate->getSpec()->fileExtension());
		$this->assertSame('xml', $feed->getSpec()->fileExtension());
	}

	private function feed(Platform $platform): Feed
	{
		$feed = new Feed();
		$feed->token = str_repeat('a', Feed::TOKEN_LENGTH);
		$feed->platform = $platform->value;

		return $feed;
	}
}
