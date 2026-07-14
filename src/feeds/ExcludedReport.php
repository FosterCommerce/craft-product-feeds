<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use Craft;
use craft\helpers\FileHelper;
use fostercommerce\productfeeds\ProductFeeds;
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
	public function __construct(
		private readonly string $filePath,
	) {
		// FeedBuildException would stop the queue job retrying, and a temp file that won't open is
		// usually transient (disk full, permissions).
		$handle = fopen($this->filePath, 'wb');
		if ($handle === false) {
			throw new Exception(Craft::t(ProductFeeds::HANDLE, 'error.excludedReportOpenFailed', [
				'path' => $this->filePath,
			]));
		}

		$this->handle = $handle;

		// An empty escape character stops PHP writing a stray backslash before a quote in the value.
		fputcsv($this->handle, self::HEADER, ',', '"', '');
	}

	/**
	 * @param array{id: string, title: string, cpUrl: string, issue: string} $row
	 */
	public function write(array $row): void
	{
		fputcsv($this->handle, array_map(
			$this->escapeFormula(...),
			[$row['id'], $row['title'], $row['cpUrl'], $row['issue']]
		), ',', '"', '');
	}

	public function close(): void
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}

	/**
	 * Closes the report and throws the partial file away, for a build that ended before it could finish.
	 */
	public function abort(): void
	{
		$this->close();

		FileHelper::unlink($this->filePath);
	}

	/**
	 * Quotes a value Excel and Sheets would otherwise run as a formula.
	 */
	private function escapeFormula(string $value): string
	{
		return str_starts_with($value, '=')
			|| str_starts_with($value, '+')
			|| str_starts_with($value, '-')
			|| str_starts_with($value, '@')
			? "'" . $value
			: $value;
	}
}
