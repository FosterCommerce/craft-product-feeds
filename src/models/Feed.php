<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\base\Model;
use craft\base\Statusable;
use craft\commerce\models\Store;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\records\Feed as FeedRecord;
use fostercommerce\productfeeds\sources\FeedSource;
use Money\Currency;
use yii\base\InvalidConfigException;

class Feed extends Model implements Statusable
{
	/**
	 * The token is the only credential on the public feed route. The site route's regex and the record's
	 * column are both sized from this.
	 */
	public const TOKEN_LENGTH = 32;

	public ?int $id = null;

	public string $name = '';

	public string $handle = '';

	public string $platform = Platform::Google->value;

	public string $source = Source::Variants->value;

	public ?int $siteId = null;

	/**
	 * Product type IDs for variants, `sectionId:entryTypeId` for entries. Empty means every source whose
	 * items have a public URL: see `FeedSource::effectiveSourceIds()`.
	 *
	 * @var string[]
	 */
	public array $sourceIds = [];

	/**
	 * @var array<string, array{source: string, default: string}>
	 */
	public array $fieldMapping = [];

	public string $imageEngine = ImageEngine::None->value;

	public ?string $imageTransform = null;

	public ?int $imageWidth = null;

	public ?int $imageHeight = null;

	public string $imageFit = ImageFit::Crop->value;

	/**
	 * @var array<string, mixed> Serialized `ElementCondition` config, applied to the source query.
	 */
	public array $filterCondition = [];

	public string $token = '';

	public bool $enabled = true;

	public ?int $sortOrder = null;

	public string $lastBuildStatus = BuildStatus::Pending->value;

	public ?DateTime $lastBuildStartedAt = null;

	public ?DateTime $lastBuildFinishedAt = null;

	public ?int $lastBuildItemCount = null;

	public ?int $lastBuildSkippedCount = null;

	public ?int $lastBuildBytes = null;

	public ?int $lastBuildBytesUncompressed = null;

	public ?string $lastBuildError = null;

	public BuildDiagnostics $lastBuildDiagnostics;

	public ?string $uid = null;

	public function init(): void
	{
		parent::init();

		// A typed property with no default: a Feed built straight from `new` still has to be readable.
		if (! isset($this->lastBuildDiagnostics)) {
			$this->lastBuildDiagnostics = new BuildDiagnostics();
		}
	}

	public function getPlatform(): Platform
	{
		return Platform::from($this->platform);
	}

	public function getSource(): Source
	{
		return Source::from($this->source);
	}

	public function getLastBuildStatus(): BuildStatus
	{
		return BuildStatus::from($this->lastBuildStatus);
	}

	public function getStore(): ?Store
	{
		if ($this->siteId === null) {
			return null;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce->getStores()->getStoreBySiteId($this->siteId);
	}

	public function getCurrency(): ?Currency
	{
		return $this->getStore()?->getCurrency();
	}

	/**
	 * @return array<string, string>
	 */
	public static function statuses(): array
	{
		return [
			Element::STATUS_ENABLED => Craft::t('app', 'Enabled'),
			Element::STATUS_DISABLED => Craft::t('app', 'Disabled'),
		];
	}

	public function getStatus(): string
	{
		return $this->enabled ? Element::STATUS_ENABLED : Element::STATUS_DISABLED;
	}

	public function getSpec(): FeedSpec
	{
		return FeedSpec::forPlatform($this->getPlatform());
	}

	/**
	 * Where the artifact lives on the filesystem. Never public: the feed is served through the plugin's
	 * own route, which carries the handle and token rather than this path.
	 *
	 * Not timestamped, so a build overwrites its predecessor rather than accumulating artifacts.
	 */
	public function getPath(): string
	{
		return sprintf('%s/%s.%s.gz', ProductFeeds::FILE_PREFIX, $this->token, $this->getSpec()->fileExtension());
	}

	public function getExcludedReportPath(): string
	{
		return sprintf('%s/%s-excluded.csv', ProductFeeds::FILE_PREFIX, $this->token);
	}

	/**
	 * Checks each default against its attribute: an enumerated attribute against the platform's
	 * vocabulary, the rest for shape.
	 */
	public function validateFieldMapping(string $attribute): void
	{
		$spec = FeedSpec::forPlatform($this->getPlatform());

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			$default = trim($this->fieldMapping[$name]['default'] ?? '');
			if ($default === '') {
				continue;
			}

			$error = $this->defaultValueError($attributeDefinition, $default);
			if ($error !== null) {
				$this->addError($attribute, $error);
			}
		}
	}

	/**
	 * Checks each mapping's source is one the dropdown would have offered for that attribute's kind.
	 *
	 * Only the browser enforced it, so without this `image_link` could be pointed at a text field, and
	 * both the build and the image test would fetch whatever the field holds as a URL. A handle on no
	 * layout at all is left alone: it resolves to a blank, as a field removed from a product type does.
	 *
	 * @throws InvalidConfigException
	 */
	public function validateFieldMappingSources(string $attribute): void
	{
		if ($this->siteId === null) {
			return;
		}

		$spec = FeedSpec::forPlatform($this->getPlatform());
		$source = FeedSource::forFeed($this);
		$layoutsByPrefix = $source->fieldLayouts();

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			$parsed = Mapping::parse($this->fieldMapping[$name]['source'] ?? Mapping::NO_INCLUDE);
			if (! in_array($parsed['kind'], [Mapping::FIELD, Mapping::PRODUCT_FIELD], true)) {
				continue;
			}

			$field = $this->fieldOnLayouts($layoutsByPrefix[$parsed['kind']] ?? [], $parsed['value']);
			if ($field instanceof FieldInterface && ! $attributeDefinition->attributeKind->acceptsField($field)) {
				$this->addError($attribute, Craft::t(ProductFeeds::HANDLE, 'error.mappingSourceNotAllowed', [
					'attribute' => $name,
					'field' => $field->name,
				]));
			}
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['fieldMapping'], 'validateFieldMapping'],
			[['fieldMapping'], 'validateFieldMappingSources'],
			[['name', 'handle', 'platform', 'source', 'siteId', 'token'], 'required'],
			[['name', 'handle', 'token', 'imageEngine', 'imageTransform', 'imageFit', 'lastBuildError'], 'string'],
			[['imageWidth', 'imageHeight'], 'integer'],
			[['handle'], HandleValidator::class],
			[
				['handle'],
				UniqueValidator::class,
				'targetClass' => FeedRecord::class,
				'targetAttribute' => ['handle', 'siteId'],
				'message' => Craft::t(ProductFeeds::HANDLE, 'error.attributeAlreadyTaken'),
			],
			[['token'],
				'string',
				'length' => self::TOKEN_LENGTH],
			[
				['platform'],
				'in',
				'range' => array_map(static fn (Platform $case): string => $case->value, Platform::cases()),
			],
			[
				['source'],
				'in',
				'range' => array_map(static fn (Source $case): string => $case->value, Source::cases()),
			],
			[
				['lastBuildStatus'],
				'in',
				'range' => array_map(static fn (BuildStatus $case): string => $case->value, BuildStatus::cases()),
			],
			[
				['imageEngine'],
				'in',
				'range' => array_map(static fn (ImageEngine $case): string => $case->value, ImageEngine::cases()),
			],
			[
				['imageFit'],
				'in',
				'range' => array_map(static fn (ImageFit $case): string => $case->value, ImageFit::cases()),
			],
			[['siteId', 'sortOrder', 'lastBuildItemCount', 'lastBuildSkippedCount', 'lastBuildBytes', 'lastBuildBytesUncompressed'], 'integer'],
			[['enabled'], 'boolean'],
			[['sourceIds', 'fieldMapping', 'filterCondition', 'lastBuildStartedAt', 'lastBuildFinishedAt'], 'safe'],
		];
	}

	/**
	 * @param FieldLayout[] $layouts
	 */
	private function fieldOnLayouts(array $layouts, string $handle): ?FieldInterface
	{
		foreach ($layouts as $layout) {
			$field = $layout->getFieldByHandle($handle);
			if ($field instanceof FieldInterface) {
				return $field;
			}
		}

		return null;
	}

	private function defaultValueError(AttributeDefinition $attributeDefinition, string $default): ?string
	{
		// An image default is an asset ID, so it is checked for existence rather than shape.
		if ($attributeDefinition->attributeKind === AttributeKind::Image) {
			$asset = is_numeric($default)
				? Craft::$app->getAssets()->getAssetById((int) $default)
				: null;

			return $asset instanceof Asset && $asset->kind === Asset::KIND_IMAGE
				? null
				: Craft::t(ProductFeeds::HANDLE, 'error.defaultNotAnImage');
		}

		if ($attributeDefinition->values !== [] && ! in_array($default, $attributeDefinition->values, true)) {
			return Craft::t(ProductFeeds::HANDLE, 'error.defaultNotAllowed', [
				'attribute' => $attributeDefinition->name,
				'value' => $default,
				'allowed' => implode(', ', $attributeDefinition->values),
			]);
		}

		$kind = $attributeDefinition->attributeKind;

		if ($kind === AttributeKind::Money && ! is_numeric($default)) {
			return Craft::t(ProductFeeds::HANDLE, 'error.defaultNotNumeric', [
				'attribute' => $attributeDefinition->name,
				'value' => $default,
			]);
		}

		if (($kind === AttributeKind::Url || $kind === AttributeKind::Image) && ! UrlHelper::isAbsoluteUrl($default)) {
			return Craft::t(ProductFeeds::HANDLE, 'error.defaultNotAbsoluteUrl', [
				'attribute' => $attributeDefinition->name,
				'value' => $default,
			]);
		}

		return null;
	}
}
