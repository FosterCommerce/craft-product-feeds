<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\records;

use craft\db\ActiveRecord;
use fostercommerce\productfeeds\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $platform
 * @property string $source
 * @property int $siteId
 * @property ?string $sourceIds
 * @property ?string $fieldMapping
 * @property ?string $filterCondition
 * @property string $imageEngine
 * @property ?string $imageTransform
 * @property ?int $imageWidth
 * @property ?int $imageHeight
 * @property string $imageFit
 * @property string $token
 * @property bool $enabled
 * @property ?int $sortOrder
 * @property string $lastBuildStatus
 * @property ?string $lastBuildStartedAt
 * @property ?string $lastBuildFinishedAt
 * @property ?int $lastBuildItemCount
 * @property ?int $lastBuildSkippedCount
 * @property ?int $lastBuildBytes
 * @property ?int $lastBuildBytesUncompressed
 * @property ?string $lastBuildError
 * @property ?string $lastBuildDiagnostics
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class Feed extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::FEEDS;
	}
}
