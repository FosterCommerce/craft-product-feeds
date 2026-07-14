<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\StandardAttribute;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\feeds\GoogleFeed;
use fostercommerce\productfeeds\feeds\KlaviyoFeed;
use fostercommerce\productfeeds\feeds\MetaFeed;
use fostercommerce\productfeeds\feeds\MicrosoftFeed;
use fostercommerce\productfeeds\feeds\PinterestFeed;
use fostercommerce\productfeeds\feeds\TikTokFeed;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedSpecTest extends TestCase
{
	/**
	 * @return array<string, array{FeedSpec}>
	 */
	public static function allSpecs(): array
	{
		$specs = [];

		foreach (Platform::cases() as $platform) {
			$specs[$platform->value] = [FeedSpec::forPlatform($platform)];
		}

		return $specs;
	}

	/**
	 * The shopping platforms. Klaviyo is a catalog for emails, and shares neither their document nor
	 * their attributes.
	 *
	 * @return array<string, array{FeedSpec}>
	 */
	public static function shoppingSpecs(): array
	{
		$specs = self::allSpecs();
		unset($specs[Platform::Klaviyo->value]);

		return $specs;
	}

	public function testPlatformResolvesToItsSpec(): void
	{
		$this->assertInstanceOf(GoogleFeed::class, FeedSpec::forPlatform(Platform::Google));
		$this->assertInstanceOf(KlaviyoFeed::class, FeedSpec::forPlatform(Platform::Klaviyo));
		$this->assertInstanceOf(MetaFeed::class, FeedSpec::forPlatform(Platform::Meta));
		$this->assertInstanceOf(MicrosoftFeed::class, FeedSpec::forPlatform(Platform::Microsoft));
		$this->assertInstanceOf(PinterestFeed::class, FeedSpec::forPlatform(Platform::Pinterest));
		$this->assertInstanceOf(TikTokFeed::class, FeedSpec::forPlatform(Platform::TikTok));
	}

	/**
	 * Microsoft only accepts an XML file that is already a Google-formatted one, and the rest take the
	 * same RSS 2.0 document. All five therefore share one writer.
	 */
	#[DataProvider('shoppingSpecs')]
	public function testEveryShoppingSpecCarriesTheSameDocument(FeedSpec $feedSpec): void
	{
		$this->assertSame('xml', $feedSpec->fileExtension());
		$this->assertSame('application/xml', $feedSpec->mimeType());
	}

	#[DataProvider('shoppingSpecs')]
	public function testEveryShoppingSpecRequiresTheCoreAttributes(FeedSpec $feedSpec): void
	{
		foreach (['id', 'title', 'description', 'link', 'image_link', 'availability', 'price'] as $attribute) {
			$this->assertContains($attribute, $feedSpec->requiredAttributes());
		}
	}

	/**
	 * The pricing model a source reads to decide what `price` carries: a shopping spec has a `sale_price`
	 * field for the promotion, so `price` is the list price.
	 */
	#[DataProvider('shoppingSpecs')]
	public function testShoppingSpecsSeparateTheSalePrice(FeedSpec $feedSpec): void
	{
		$this->assertTrue($feedSpec->separatesSalePrice());
		$this->assertArrayHasKey(StandardAttribute::SalePrice->value, $feedSpec->attributes());
	}

	/**
	 * Klaviyo has no sale-price field, so a source has to publish the promotional price as `price`
	 * directly, or the email quotes a discount the feed never applies.
	 */
	public function testKlaviyoDoesNotSeparateTheSalePrice(): void
	{
		$this->assertFalse((new KlaviyoFeed())->separatesSalePrice());
	}

	public function testKlaviyoCarriesAJsonDocument(): void
	{
		$klaviyo = new KlaviyoFeed();

		$this->assertSame('json', $klaviyo->fileExtension());
		$this->assertSame('application/json', $klaviyo->mimeType());
	}

	/**
	 * Klaviyo carries stock as a number rather than an availability string, and holds one image per item.
	 */
	public function testKlaviyoDropsTheShoppingAttributes(): void
	{
		$klaviyo = new KlaviyoFeed();
		$attributes = $klaviyo->attributes();

		$this->assertArrayNotHasKey('availability', $attributes);
		$this->assertArrayNotHasKey('additional_image_link', $attributes);
		$this->assertArrayNotHasKey('brand', $attributes);
		$this->assertArrayNotHasKey('item_group_id', $attributes);
		$this->assertNull($klaviyo->galleryAttribute());
		$this->assertSame('image_link', $klaviyo->imageAttribute());
	}

	public function testKlaviyoRequiresItsCoreAttributes(): void
	{
		$required = (new KlaviyoFeed())->requiredAttributes();

		$this->assertSame(['id', 'title', 'description', 'link', 'image_link'], $required);
	}

	/**
	 * Klaviyo names its own fields with a `$` prefix, and reads anything else as custom metadata.
	 */
	public function testKlaviyoPrefixesItsOwnFieldNames(): void
	{
		$klaviyo = new KlaviyoFeed();

		$this->assertSame('$id', $klaviyo->documentName('id'));
		$this->assertSame('$image_link', $klaviyo->documentName('image_link'));
		$this->assertSame('$inventory_quantity', $klaviyo->documentName('inventory_quantity'));
		$this->assertSame('categories', $klaviyo->documentName('categories'));
	}

	public function testKlaviyoCarriesCategoriesAsAList(): void
	{
		$attributes = (new KlaviyoFeed())->attributes();

		$this->assertSame(AttributeKind::CategoryList, $attributes['categories']->attributeKind);
	}

	/**
	 * Klaviyo takes the currency from the account, so the price is a bare decimal where every shopping
	 * platform wants the currency code alongside it.
	 */
	public function testKlaviyoPricesWithoutACurrencyCode(): void
	{
		$money = new Money(1999, new Currency('USD'));

		$this->assertSame('19.99', (new KlaviyoFeed())->formatMoney($money));
		$this->assertSame('19.99 USD', (new GoogleFeed())->formatMoney($money));
	}

	public function testKlaviyoOffersItsInventoryPolicyVocabulary(): void
	{
		$this->assertSame(['0', '1', '2'], (new KlaviyoFeed())->attributes()['inventory_policy']->values);
	}

	/**
	 * The kind is what tells the JSON writer to publish a value as a number, so a stock count declared as
	 * text would ship quoted.
	 */
	public function testKlaviyoCarriesItsStockFieldsAsNumbers(): void
	{
		$attributes = (new KlaviyoFeed())->attributes();

		$this->assertSame(AttributeKind::Number, $attributes['inventory_quantity']->attributeKind);
		$this->assertSame(AttributeKind::Number, $attributes['inventory_policy']->attributeKind);
	}

	public function testOnlyGoogleUsesUnderscoredAvailability(): void
	{
		$item = [
			StandardAttribute::Availability->value => Availability::InStock->value,
		];

		$this->assertSame('in_stock', (new GoogleFeed())->finalizeItem($item)['availability']);
		$this->assertSame('in stock', (new MetaFeed())->finalizeItem($item)['availability']);
		$this->assertSame('in stock', (new MicrosoftFeed())->finalizeItem($item)['availability']);
		$this->assertSame('in stock', (new PinterestFeed())->finalizeItem($item)['availability']);
		$this->assertSame('in stock', (new TikTokFeed())->finalizeItem($item)['availability']);
	}

	/**
	 * `preorder` carries no underscore, so the spaced platforms leave it alone.
	 */
	public function testPreorderSurvivesTheAvailabilityRewrite(): void
	{
		$item = [
			StandardAttribute::Availability->value => Availability::Preorder->value,
		];

		$this->assertSame('preorder', (new MetaFeed())->finalizeItem($item)['availability']);
		$this->assertSame('preorder', (new GoogleFeed())->finalizeItem($item)['availability']);
	}

	/**
	 * Microsoft carries `identifier_exists` for the same reason Google does. Meta, Pinterest and TikTok
	 * have no such field and must not be sent one.
	 */
	public function testIdentifierExistsOnlyWhereThePlatformHasIt(): void
	{
		$item = [
			'title' => 'A thing',
		];

		$this->assertSame('no', (new GoogleFeed())->finalizeItem($item)['identifier_exists'] ?? null);
		$this->assertSame('no', (new MicrosoftFeed())->finalizeItem($item)['identifier_exists'] ?? null);

		$this->assertArrayNotHasKey('identifier_exists', (new MetaFeed())->finalizeItem($item));
		$this->assertArrayNotHasKey('identifier_exists', (new PinterestFeed())->attributes());
		$this->assertArrayNotHasKey('identifier_exists', (new TikTokFeed())->attributes());
	}

	/**
	 * A brand is required exactly where the platform has no `identifier_exists` to fall back on: Meta and
	 * TikTok. Microsoft's Content API marks `condition` and the identifiers as required for an insert,
	 * but its feed file requires neither.
	 *
	 * No platform requires `gtin` or `mpn`: a product may have neither.
	 */
	public function testBrandIsRequiredWhereThereIsNoIdentifierExists(): void
	{
		foreach ([new GoogleFeed(), new MicrosoftFeed(), new PinterestFeed()] as $spec) {
			$this->assertNotContains('brand', $spec->requiredAttributes(), $spec::class);
			$this->assertNotContains('condition', $spec->requiredAttributes(), $spec::class);
		}

		foreach ([new MetaFeed(), new TikTokFeed()] as $spec) {
			$this->assertContains('brand', $spec->requiredAttributes(), $spec::class);
			$this->assertContains('condition', $spec->requiredAttributes(), $spec::class);
		}

		foreach (self::shoppingSpecs() as [$spec]) {
			$this->assertNotContains('gtin', $spec->requiredAttributes(), $spec::class);
			$this->assertNotContains('mpn', $spec->requiredAttributes(), $spec::class);
		}
	}

	/**
	 * Microsoft's docs say the extra images exist for Google compatibility and are never read, so the
	 * mapping screen does not offer them.
	 */
	public function testMicrosoftDropsTheGallery(): void
	{
		$microsoft = new MicrosoftFeed();

		$this->assertNull($microsoft->galleryAttribute());
		$this->assertArrayNotHasKey('additional_image_link', $microsoft->attributes());
		$this->assertSame('image_link', $microsoft->imageAttribute());
	}

	public function testEachPlatformCarriesItsOwnLimits(): void
	{
		$this->assertSame(500, (new PinterestFeed())->attributes()['title']->maxLength);
		$this->assertSame(10_000, (new PinterestFeed())->attributes()['description']->maxLength);

		$this->assertSame(150, (new MicrosoftFeed())->attributes()['title']->maxLength);
		$this->assertSame(10_000, (new MicrosoftFeed())->attributes()['description']->maxLength);
	}

	public function testPinterestWantsAPortraitImage(): void
	{
		$this->assertSame([1000, 1500], (new PinterestFeed())->minimumImageSize());
		$this->assertSame([500, 500], (new GoogleFeed())->minimumImageSize());
	}

	public function testMicrosoftPublishesNoImageMinimum(): void
	{
		$this->assertNull((new MicrosoftFeed())->minimumImageSize());
		$this->assertNotNull((new MicrosoftFeed())->imageSizeNote());
	}

	public function testTikTokEmitsTheIdAsSkuId(): void
	{
		$tiktok = new TikTokFeed();

		$this->assertSame('sku_id', $tiktok->documentName('id'));
		$this->assertSame('title', $tiktok->documentName('title'));
		$this->assertArrayHasKey('id', $tiktok->attributes());
		$this->assertContains('id', $tiktok->requiredAttributes());
	}

	public function testEveryOtherPlatformLeavesAttributeNamesAlone(): void
	{
		foreach ([new GoogleFeed(), new MetaFeed(), new MicrosoftFeed(), new PinterestFeed()] as $spec) {
			$this->assertSame('id', $spec->documentName('id'), $spec::class);
		}
	}

	#[DataProvider('allSpecs')]
	public function testEveryAttributeIsDocumented(FeedSpec $feedSpec): void
	{
		foreach (array_keys($feedSpec->attributes()) as $name) {
			$this->assertNotNull($feedSpec->docUrl($name), sprintf('No doc URL for %s', $name));
		}
	}

	public function testIdentifierExistsIsNotSentWhenAnIdentifierIsPresent(): void
	{
		$item = [
			'brand' => 'Kooima',
		];

		$this->assertArrayNotHasKey('identifier_exists', (new GoogleFeed())->finalizeItem($item));
	}

	/**
	 * A derived attribute has no source for an admin to point at, so it never reaches the mapping screen.
	 */
	public function testDerivedAttributesAreNotMappable(): void
	{
		$this->assertSame(['identifier_exists'], (new GoogleFeed())->derivedAttributes());
		$this->assertSame([], (new MetaFeed())->derivedAttributes());
	}

	/**
	 * The builder asks the spec which attribute carries the image, rather than knowing Google's name for
	 * it. A platform with neither returns null from both.
	 */
	public function testSpecNamesItsOwnImageAttributes(): void
	{
		foreach ([new GoogleFeed(), new MetaFeed()] as $spec) {
			$this->assertSame('image_link', $spec->imageAttribute());
			$this->assertSame('additional_image_link', $spec->galleryAttribute());
		}
	}

	/**
	 * A free-text default for these gets the item rejected, so the mapping screen offers a select.
	 */
	public function testEnumeratedAttributesCarryTheirVocabulary(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertSame(['in_stock', 'out_of_stock', 'preorder'], $attributes['availability']->values);
		$this->assertSame(['new', 'refurbished', 'used'], $attributes['condition']->values);
	}

	public function testFreeTextAttributesCarryNoVocabulary(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertSame([], $attributes['title']->values);
		$this->assertSame([], $attributes['brand']->values);
	}

	/**
	 * Both platforms take the same condition values, so the vocabulary must not diverge the way
	 * availability does.
	 */
	public function testConditionVocabularyIsSharedAcrossPlatforms(): void
	{
		$this->assertSame(
			(new GoogleFeed())->attributes()['condition']->values,
			(new MetaFeed())->attributes()['condition']->values
		);
	}

	/**
	 * The mapping screen renders this as Craft's info bubble beside the attribute name.
	 */
	public function testMoneyAttributesCarryTheZeroPriceNote(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertSame('attribute.priceNote', $attributes['price']->note);
		$this->assertSame('attribute.priceNote', $attributes['sale_price']->note);
		$this->assertNull($attributes['title']->note);
	}

	public function testAttributesCarryGooglesDocumentedLimits(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertSame(150, $attributes['title']->maxLength);
		$this->assertSame(5000, $attributes['description']->maxLength);
		$this->assertSame(70, $attributes['brand']->maxLength);
		$this->assertSame(70, $attributes['mpn']->maxLength);
		$this->assertSame(750, $attributes['product_type']->maxLength);
		$this->assertSame(100, $attributes['custom_label_0']->maxLength);
	}

	/**
	 * A shortened `gtin` fails its checksum, and a shortened `id` is a different product. Google's
	 * limits for them exist, but truncating to fit is never the right answer.
	 */
	public function testIdentifiersAreNeverTruncated(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertNull($attributes['id']->maxLength);
		$this->assertNull($attributes['gtin']->maxLength);
		$this->assertNull($attributes['link']->maxLength);
	}

	public function testGoogleLinksEachAttributeToItsOwnPage(): void
	{
		$google = new GoogleFeed();

		$this->assertNotSame($google->docUrl('price'), $google->docUrl('brand'));
		$this->assertStringStartsWith('https://support.google.com/merchants/answer/', (string) $google->docUrl('price'));
	}

	public function testUnknownAttributesHaveNoDocUrl(): void
	{
		$this->assertNull((new GoogleFeed())->docUrl('not_an_attribute'));
		$this->assertNull((new MetaFeed())->docUrl('not_an_attribute'));
	}

	/**
	 * It maps like any other attribute now, but as an image so the dropdown offers asset fields.
	 */
	public function testAdditionalImageLinkIsAMappableImage(): void
	{
		$attributes = (new GoogleFeed())->attributes();

		$this->assertArrayHasKey('additional_image_link', $attributes);
		$this->assertSame(AttributeKind::Image, $attributes['additional_image_link']->attributeKind);
	}
}
