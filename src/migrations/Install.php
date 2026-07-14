<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\productfeeds\db\Table;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;
use fostercommerce\productfeeds\models\Feed;

/**
 * Feeds are store-admin content, not project config, so they live only in the database.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->archiveTableIfExists(Table::FEEDS);

		$this->createTable(Table::FEEDS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'handle' => $this->string()->notNull(),
			'platform' => $this->string(16)->notNull(),
			'source' => $this->string(16)->notNull(),
			'siteId' => $this->integer()->notNull(),
			'sourceIds' => $this->text(),
			// A mapping row per attribute, each with its own default value, can outgrow TEXT's 64KB.
			'fieldMapping' => $this->mediumText(),
			'imageEngine' => $this->string()->notNull()->defaultValue(ImageEngine::None->value),
			'imageTransform' => $this->string(),
			'imageWidth' => $this->smallInteger()->unsigned(),
			'imageHeight' => $this->smallInteger()->unsigned(),
			'imageFit' => $this->string()->notNull()->defaultValue(ImageFit::Crop->value),
			'filterCondition' => $this->mediumText(),
			'token' => $this->string(Feed::TOKEN_LENGTH)->notNull(),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'sortOrder' => $this->smallInteger()->unsigned(),

			'lastBuildStatus' => $this->string(16)->notNull()->defaultValue(BuildStatus::Pending->value),
			'lastBuildStartedAt' => $this->dateTime(),
			'lastBuildFinishedAt' => $this->dateTime(),
			'lastBuildItemCount' => $this->integer(),
			'lastBuildSkippedCount' => $this->integer(),
			'lastBuildBytes' => $this->bigInteger(),
			'lastBuildBytesUncompressed' => $this->bigInteger(),
			'lastBuildError' => $this->text(),
			'lastBuildDiagnostics' => $this->text(),

			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// A feed belongs to one site, so a store can run the same handle on each of its sites.
		$this->createIndex(null, Table::FEEDS, ['handle', 'siteId'], true);
		// The public route resolves a feed by token alone, on every fetch.
		$this->createIndex(null, Table::FEEDS, ['token'], true);
		$this->createIndex(null, Table::FEEDS, ['siteId'], false);

		$this->addForeignKey(null, Table::FEEDS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::FEEDS);

		return true;
	}
}
