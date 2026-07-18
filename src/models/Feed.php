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
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * A feed.
 *
 * `Cp::componentStatusLabelHtml()` requires `Statusable`; the index table uses it for the enabled badge.
 */
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

	private ?FeedSpec $spec = null;

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

	public function getCpEditUrl(): string
	{
		return UrlHelper::cpUrl('product-feeds/' . $this->id);
	}

	public function getSpec(): FeedSpec
	{
		// A spec parses its attributes once and remembers them, so a fresh one per call would reparse them.
		return $this->spec ??= FeedSpec::forPlatform($this->getPlatform());
	}

	/**
	 * Named by token, not by handle: the token is what the public route resolves a feed by. Not
	 * timestamped, so a build overwrites its predecessor rather than piling up.
	 */
	public function getFileName(): string
	{
		return sprintf('%s.%s.gz', $this->token, $this->getSpec()->fileExtension());
	}

	public function getExcludedReportFileName(): string
	{
		return sprintf('%s-excluded.csv', $this->token);
	}

	/**
	 * Where the artifact lives on the filesystem. Never public: the feed is served through the plugin's
	 * own route.
	 */
	public function getPath(): string
	{
		return sprintf('%s/%s', ProductFeeds::FILE_PREFIX, $this->getFileName());
	}

	public function getExcludedReportPath(): string
	{
		return sprintf('%s/%s', ProductFeeds::FILE_PREFIX, $this->getExcludedReportFileName());
	}

	/**
	 * The path the site route serves the feed from. The token identifies the feed; the handle is there
	 * to make the URL readable.
	 */
	public function getUrlPath(): string
	{
		return sprintf('%s/%s-%s', ProductFeeds::FILE_PREFIX, $this->handle, $this->getFileName());
	}

	/**
	 * @throws Exception if the feed names a site that no longer exists
	 */
	public function getSiteBaseUrl(): string
	{
		return UrlHelper::siteUrl('', null, null, $this->siteId);
	}

	/**
	 * Where an attribute reads its value from. An unmapped gallery falls back to the images the main
	 * image left over; every other unmapped attribute is left out of the feed.
	 */
	public function mappingSource(string $attribute, FeedSpec $spec): string
	{
		return $this->fieldMapping[$attribute]['source'] ?? ($attribute === $spec->galleryAttribute()
			? Mapping::IMAGE_OVERFLOW
			: Mapping::NO_INCLUDE);
	}

	/**
	 * The value an attribute falls back to when its source resolves to nothing. An asset ID for an
	 * image attribute, the value itself for the rest.
	 */
	public function mappingDefault(string $attribute): string
	{
		return trim($this->fieldMapping[$attribute]['default'] ?? '');
	}

	/**
	 * Checks each default against its attribute: an enumerated attribute against the platform's
	 * vocabulary, the rest for shape.
	 */
	public function validateFieldMapping(string $modelAttribute): void
	{
		// Yii runs every rule whatever the ones before it found, and `getSpec()` resolves the platform
		// through an enum that throws on the value the `in` rule has already rejected.
		if ($this->hasErrors('platform')) {
			return;
		}

		$spec = $this->getSpec();

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			$default = $this->mappingDefault($name);
			if ($default === '') {
				continue;
			}

			$error = $this->defaultValueError($attributeDefinition, $default);
			if ($error !== null) {
				$this->addError($modelAttribute, $error);
			}
		}
	}

	/**
	 * Checks each mapping's source is one the dropdown would have offered for that attribute's kind. Only
	 * the browser enforced it, so without this `image_link` could point at a text field and the build
	 * would fetch whatever it holds. A handle on no layout is left alone: it resolves to a blank.
	 *
	 * @throws InvalidConfigException
	 */
	public function validateFieldMappingSources(string $modelAttribute): void
	{
		// As in `validateFieldMapping()`: `FeedSource::forFeed()` resolves the source through an enum that
		// throws on a value the `in` rules have already rejected.
		if ($this->siteId === null || $this->hasErrors('platform') || $this->hasErrors('source')) {
			return;
		}

		$spec = $this->getSpec();
		$source = FeedSource::forFeed($this);
		$layoutsByPrefix = $source->fieldLayouts();

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			$parsed = Mapping::parse($this->mappingSource($name, $spec));
			if (! in_array($parsed['kind'], [Mapping::FIELD, Mapping::PRODUCT_FIELD], true)) {
				continue;
			}

			$field = $this->fieldOnLayouts($layoutsByPrefix[$parsed['kind']] ?? [], $parsed['value']);
			if ($field instanceof FieldInterface && ! $attributeDefinition->attributeKind->acceptsField($field)) {
				$this->addError($modelAttribute, Craft::t(ProductFeeds::HANDLE, 'error.mappingSourceNotAllowed', [
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
			// Ahead of the mapping validators: both resolve the platform and the source through the enums,
			// which throw on a value these rules are here to reject.
			[
				['platform'],
				'in',
				'range' => Platform::values(),
			],
			[
				['source'],
				'in',
				'range' => Source::values(),
			],
			[['fieldMapping'], 'validateFieldMapping'],
			[['fieldMapping'], 'validateFieldMappingSources'],
			[['name', 'handle', 'platform', 'source', 'siteId', 'token'], 'required'],
			[['name', 'handle', 'token', 'imageEngine', 'imageTransform', 'imageFit', 'lastBuildError'], 'string'],
			[['name', 'handle', 'imageEngine', 'imageTransform', 'imageFit'],
				'string',
				'max' => 255],
			// `smallInteger()->unsigned()`, and Postgres ignores the unsigned, so 32767 is the portable ceiling.
			[['imageWidth', 'imageHeight'],
				'integer',
				'min' => 1,
				'max' => 32767],
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
				['imageEngine'],
				'in',
				'range' => ImageEngine::values(),
			],
			[
				['imageFit'],
				'in',
				'range' => ImageFit::values(),
			],
			[['siteId', 'sortOrder'], 'integer'],
			[['enabled'], 'boolean'],
			[['sourceIds', 'fieldMapping', 'filterCondition'], 'safe'],
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

		if (($kind === AttributeKind::Money || $kind === AttributeKind::Number) && ! is_numeric($default)) {
			return Craft::t(ProductFeeds::HANDLE, 'error.defaultNotNumeric', [
				'attribute' => $attributeDefinition->name,
				'value' => $default,
			]);
		}

		if ($kind === AttributeKind::Url && ! UrlHelper::isAbsoluteUrl($default)) {
			return Craft::t(ProductFeeds::HANDLE, 'error.defaultNotAbsoluteUrl', [
				'attribute' => $attributeDefinition->name,
				'value' => $default,
			]);
		}

		return null;
	}
}
