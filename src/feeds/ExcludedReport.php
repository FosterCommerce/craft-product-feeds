<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use yii\base\Exception;

final class ExcludedReport
{
	private const HEADER = ['id', 'title', 'cp_url', 'issue'];

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * @throws Exception
	 */
	public function __construct(string $filePath)
	{
		// FeedBuildException would stop the queue job retrying, and a temp file that won't open is
		// usually transient (disk full, permissions).
		$handle = fopen($filePath, 'wb');
		if ($handle === false) {
			throw new Exception(sprintf('Could not open the excluded report at “%s”.', $filePath));
		}

		$this->handle = $handle;
		fputcsv($this->handle, self::HEADER, ',', '"', '');
	}

	/**
	 * @param array{id: string, title: string, cpUrl: string, issue: string} $row
	 */
	public function write(array $row): void
	{
		fputcsv($this->handle, [$row['id'], $row['title'], $row['cpUrl'], $row['issue']], ',', '"', '');
	}

	public function close(): void
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}
}
