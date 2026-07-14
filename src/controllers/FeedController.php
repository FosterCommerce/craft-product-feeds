<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\controllers;

use craft\errors\FsException;
use craft\web\Controller;
use DateTime;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\RangeNotSatisfiableHttpException;
use yii\web\Response;

class FeedController extends Controller
{
	private const GZIP_MIME_TYPE = 'application/gzip';

	protected array|bool|int $allowAnonymous = true;

	/**
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	public function actionServeCompressed(string $handle, string $token, string $extension): Response
	{
		$feed = $this->feedFor($handle, $token, $extension);

		return $this->sendFeed($feed, self::GZIP_MIME_TYPE, $feed->lastBuildBytes);
	}

	/**
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	public function actionServe(string $handle, string $token, string $extension): Response
	{
		$feed = $this->feedFor($handle, $token, $extension);

		return $this->sendFeed($feed, $feed->getSpec()->mimeType(), $feed->lastBuildBytesUncompressed, true);
	}

	/**
	 * `hash_equals` is not redundant with the lookup: MySQL's default collation is case insensitive,
	 * so the query alone would match a token whose case differs from the stored one.
	 *
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	private function feedFor(string $handle, string $token, string $extension): Feed
	{
		$feed = ProductFeeds::plugin()->getFeeds()->getFeedByToken($token);

		if (
			! $feed instanceof Feed
			|| ! $feed->enabled
			|| $feed->handle !== $handle
			|| ! hash_equals($feed->token, $token)
			|| $feed->getSpec()->fileExtension() !== $extension
		) {
			throw new NotFoundHttpException();
		}

		return $feed;
	}

	/**
	 * `fileSize` is mandatory. Without it Yii measures the stream by seeking to its end, which gives the
	 * wrong length for both a remote file and an inflated one.
	 *
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 * @throws RangeNotSatisfiableHttpException
	 */
	private function sendFeed(Feed $feed, string $mimeType, ?int $fileSize, bool $inflate = false): Response
	{
		$filesystem = ProductFeeds::plugin()->getFeeds()->findFs() ?? throw new NotFoundHttpException();

		if ($fileSize === null || ! $filesystem->fileExists($feed->getPath())) {
			throw new NotFoundHttpException();
		}

		$stream = $filesystem->getFileStream($feed->getPath());

		if ($inflate) {
			stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, [
				'window' => 31,
			]);
		}

		$response = $this->response;

		if ($feed->lastBuildFinishedAt instanceof DateTime) {
			$response->getHeaders()->set('Last-Modified', gmdate('D, d M Y H:i:s', $feed->lastBuildFinishedAt->getTimestamp()) . ' GMT');
		}

		$extension = $feed->getSpec()->fileExtension();

		return $response->sendStreamAsFile(
			$stream,
			sprintf('%s.%s%s', $feed->handle, $extension, $inflate ? '' : '.gz'),
			[
				'mimeType' => $mimeType,
				'inline' => true,
				'fileSize' => $fileSize,
			]
		);
	}
}
