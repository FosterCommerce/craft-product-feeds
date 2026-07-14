<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\i18n\Locale;
use DateTime;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * One row per feed for the index table.
 *
 * Every column but `title` is rendered through `v-html`, so those values are encoded here.
 */
final class FeedIndexTable
{
	/**
	 * @param Feed[] $feeds
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	public static function rows(array $feeds, bool $canEdit): array
	{
		$feedsService = ProductFeeds::plugin()->getFeeds();
		$formatter = Craft::$app->getFormatter();
		$rows = [];

		foreach ($feeds as $feed) {
			$status = $feed->getLastBuildStatus();
			$finishedAt = $feed->lastBuildFinishedAt;
			$bytes = $feed->lastBuildBytes;

			$rows[] = [
				'id' => $feed->id,
				'title' => $feed->name,
				'url' => $feed->getCpEditUrl(),
				'feedStatus' => Cp::componentStatusLabelHtml($feed),
				'platform' => Html::encode($feed->getPlatform()->label()),
				'source' => Html::encode($feed->getSource()->label()),
				'lastBuilt' => [
					'label' => Html::encode($status->label()),
					'at' => $finishedAt instanceof DateTime
						? Html::encode($formatter->asDatetime($finishedAt, Locale::LENGTH_SHORT))
						: null,
					'error' => Html::encode((string) $feed->lastBuildError),
				],
				'items' => $feed->lastBuildItemCount ?? '',
				'issues' => $feed->lastBuildSkippedCount ?? 0,
				'size' => $bytes === null || $bytes === 0 ? '' : $formatter->asShortSize($bytes),
				'build' => $feed->id,
				'feedUrl' => Html::encode($feedsService->getFeedUrl($feed)),
				'_showDelete' => $canEdit,
			];
		}

		return $rows;
	}
}
