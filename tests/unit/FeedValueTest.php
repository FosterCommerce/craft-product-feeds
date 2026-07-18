<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\helpers\FeedValue;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

class FeedValueTest extends TestCase
{
	/**
	 * Google wants `129.00 USD`. Built from Money's minor units, never from a rounded float.
	 */
	public function testMoneyIsFormattedWithItsCurrencyCode(): void
	{
		$currency = new Currency('USD');

		$this->assertSame('129.00 USD', FeedValue::money(new Money(12900, $currency)));
		$this->assertSame('0.05 USD', FeedValue::money(new Money(5, $currency)));
	}

	public function testMoneyUsesTheStoresCurrency(): void
	{
		$this->assertSame('9.99 GBP', FeedValue::money(new Money(999, new Currency('GBP'))));
	}

	/**
	 * A mapped Number field yields a bare number. Emitting `<g:price>199</g:price>` gets every item
	 * on an entry feed rejected.
	 */
	public function testMappedDecimalsBecomeGooglesPriceFormat(): void
	{
		$currency = new Currency('USD');

		$this->assertSame('199.00 USD', $this->emitted('199', $currency));
		$this->assertSame('199.50 USD', $this->emitted('199.5', $currency));
		$this->assertSame('0.99 USD', $this->emitted('0.99', $currency));
	}

	public function testMappedDecimalsUseTheStoresCurrency(): void
	{
		$this->assertSame('199.00 GBP', $this->emitted('199', new Currency('GBP')));
	}

	/**
	 * A price of zero or less is what the build counts as a data issue, so it has to be answerable
	 * from the Money object rather than by casting the emitted string back to a float.
	 */
	public function testNonPositivePricesAreDetectable(): void
	{
		$currency = new Currency('USD');

		$this->assertFalse(FeedValue::moneyFromDecimal('0', $currency)?->isPositive());
		$this->assertFalse(FeedValue::moneyFromDecimal('-1.50', $currency)?->isPositive());
		$this->assertTrue(FeedValue::moneyFromDecimal('0.01', $currency)?->isPositive());
	}

	/**
	 * A non-numeric value becomes a blank, and `price` being required then skips the item, rather
	 * than publishing something Google will reject.
	 */
	public function testNonNumericAmountsYieldNothing(): void
	{
		$currency = new Currency('USD');

		$this->assertNull(FeedValue::moneyFromDecimal('from 199', $currency));
		$this->assertNull(FeedValue::moneyFromDecimal('', $currency));
	}

	/**
	 * `is_numeric()` accepts a leading `+` and exponent notation, which MoneyPHP's decimal parser
	 * rejects by throwing. One unparseable price has to become a blank that skips the item, not an
	 * exception that fails the whole build. A large float stringifies to exponent form, so this is the
	 * value a real catalog produces.
	 */
	public function testAmountsOutsideTheDecimalGrammarYieldNothing(): void
	{
		$currency = new Currency('USD');

		$this->assertNull(FeedValue::moneyFromDecimal('+19.99', $currency));
		$this->assertNull(FeedValue::moneyFromDecimal('1e3', $currency));
		$this->assertNull(FeedValue::moneyFromDecimal((string) 1.0E+16, $currency));
	}

	/**
	 * Money values must not be stripped or truncated the way prose is.
	 */
	public function testMoneyKindPassesValuesThroughUntouched(): void
	{
		$this->assertSame(['199.50'], (new FeedValue('https://example.test'))->normalize('199.50', AttributeKind::Money));
	}

	public function testDescriptionsAreStrippedOfMarkup(): void
	{
		$value = '<p>A <strong>light-filtering</strong> shade.</p><p>Made to measure.</p>';

		$this->assertSame(
			['A light-filtering shade. Made to measure.'],
			(new FeedValue('https://example.test'))->normalize($value, AttributeKind::LongText)
		);
	}

	/**
	 * A rich text field stores markup, so its entities have to come back as characters before the
	 * XML writer escapes them again.
	 */
	public function testEntitiesAreDecoded(): void
	{
		$this->assertSame(['Black & White'], (new FeedValue('https://example.test'))->normalize('<p>Black &amp; White</p>', AttributeKind::Text));
	}

	/**
	 * The limit belongs to the attribute, not the kind: `brand` and `title` are both Text and cap at
	 * 70 and 150.
	 */
	public function testValuesAreTruncatedToTheGivenLimit(): void
	{
		$normalized = (new FeedValue('https://example.test'))->normalize(str_repeat('a', 200), AttributeKind::Text, null, 150);

		$this->assertCount(1, $normalized);
		$this->assertSame(150, mb_strlen($normalized[0]));

		$brand = (new FeedValue('https://example.test'))->normalize(str_repeat('a', 200), AttributeKind::Text, null, 70);
		$this->assertSame(70, mb_strlen($brand[0]));
	}

	public function testValuesAreNotTruncatedWithoutALimit(): void
	{
		$normalized = (new FeedValue('https://example.test'))->normalize(str_repeat('a', 6000), AttributeKind::LongText);

		$this->assertSame(6000, mb_strlen($normalized[0]));
	}

	public function testUrlsAreNotTruncatedOrStripped(): void
	{
		$url = 'https://example.test/shades/roller?a=1&b=2';

		$this->assertSame([$url], (new FeedValue('https://example.test'))->normalize($url, AttributeKind::Url));
	}

	/**
	 * An asset filesystem with a relative base URL yields relative asset URLs, which the feed cannot
	 * publish. Every branch resolves against the site's origin.
	 */
	public function testRelativeUrlsAreResolvedAgainstTheSitesOrigin(): void
	{
		$feedValue = new FeedValue('https://example.test');

		$this->assertSame(
			['https://cdn.example.test/slike/a.jpg'],
			$feedValue->normalize('//cdn.example.test/slike/a.jpg', AttributeKind::Image)
		);

		$this->assertSame(
			['https://example.test/doc/slike/a.jpg'],
			$feedValue->normalize('/doc/slike/a.jpg', AttributeKind::Image)
		);
	}

	/**
	 * A feed is not served from a containing page, so a document-relative base URL has no referent.
	 * Resolving it as root-relative is the only reading that terminates.
	 */
	public function testDocumentRelativeUrlsAreResolvedAsRootRelative(): void
	{
		$this->assertSame(
			['https://example.test/doc/slike/a.jpg'],
			(new FeedValue('https://example.test'))->normalize('doc/slike/a.jpg', AttributeKind::Image)
		);
	}

	/**
	 * `UrlHelper::siteUrl()` joins against the site's full base URL, path included, so resolving through
	 * it would splice the subdirectory into every asset URL.
	 */
	public function testASubdirectorySiteDoesNotSpliceItsPathIntoAssetUrls(): void
	{
		$this->assertSame(
			['https://example.test/doc/slike/a.jpg'],
			(new FeedValue('https://example.test/en'))->normalize('/doc/slike/a.jpg', AttributeKind::Image)
		);
	}

	/**
	 * Image values are asset-derived, so resolving one blind is safe. A URL value is hand-typed or
	 * plugin-supplied, so free text in a PlainText field would otherwise publish as a live link.
	 */
	public function testOnlyImageValuesAreResolved(): void
	{
		$feedValue = new FeedValue('https://example.test');

		// Left as typed, so `ItemBuilder`'s absolute-only filter drops it rather than publishing a link.
		$this->assertSame(['Out of stock'], $feedValue->normalize('Out of stock', AttributeKind::Url));
		$this->assertSame(
			['https://example.test/Out%20of%20stock'],
			$feedValue->normalize('Out of stock', AttributeKind::Image)
		);
	}

	/**
	 * With no resolvable origin the value is left alone, which drops it at the absolute-only filter in
	 * `ItemBuilder` and is the one path that still reaches the CP's relative-URL warning.
	 */
	public function testAnUnresolvableBaseUrlLeavesTheValueAlone(): void
	{
		$this->assertSame(
			['/doc/slike/a.jpg'],
			(new FeedValue('/not-a-site-url'))->normalize('/doc/slike/a.jpg', AttributeKind::Image)
		);
	}

	/**
	 * Absolutizing runs before encoding, so a relative URL still meets Google's RFC 3986 requirement.
	 */
	public function testResolvedUrlsAreStillEncoded(): void
	{
		$this->assertSame(
			['https://example.test/doc/slike/a%20b.jpg'],
			(new FeedValue('https://example.test'))->normalize('/doc/slike/a b.jpg', AttributeKind::Image)
		);
	}

	/**
	 * Blanks are filtered before absolutizing. Resolving one would publish a link to the homepage on
	 * every item whose image is missing.
	 */
	public function testBlankValuesDoNotResolveToTheSitesOrigin(): void
	{
		$this->assertSame([], (new FeedValue('https://example.test'))->normalize('', AttributeKind::Image));
	}

	/**
	 * Google requires RFC 3986 URLs and flags an item whose image URL has a raw space or `#`.
	 */
	public function testUrlPathsAreEncoded(): void
	{
		$this->assertSame(
			['https://example.test/assets/a%20b.jpg'],
			(new FeedValue('https://example.test'))->normalize('https://example.test/assets/a b.jpg', AttributeKind::Image)
		);

		$this->assertSame(
			['https://example.test/assets/no%23-1.jpg'],
			(new FeedValue('https://example.test'))->normalize('https://example.test/assets/no#-1.jpg', AttributeKind::Image)
		);
	}

	/**
	 * Decoding each segment before re-encoding is what makes this safe to apply to a URL an image
	 * service already encoded.
	 */
	public function testEncodingIsIdempotent(): void
	{
		$encoded = 'https://example.test/assets/a%20b.jpg';

		$this->assertSame($encoded, FeedValue::encodeUrl($encoded));
		$this->assertSame($encoded, FeedValue::encodeUrl(FeedValue::encodeUrl($encoded)));
	}

	/**
	 * Small Pics and Imgix carry their transform parameters in the query. Encoding it would destroy
	 * the `&` and `=` separators.
	 */
	public function testQueryStringsAreLeftAlone(): void
	{
		$url = 'https://media.example.test/photo.jpg?w=800&h=600&fit=cover&fm=avif';

		$this->assertSame($url, FeedValue::encodeUrl($url));
	}

	public function testPortsAndUnicodeSurvive(): void
	{
		$this->assertSame('https://example.test:8080/a.jpg', FeedValue::encodeUrl('https://example.test:8080/a.jpg'));
		$this->assertSame('https://example.test/caf%C3%A9.jpg', FeedValue::encodeUrl('https://example.test/café.jpg'));
	}

	/**
	 * `parse_url()` splits userinfo off the host, so an image URL that authenticates has to be
	 * reassembled with it or the platform's crawler is refused and the item disapproved.
	 */
	public function testCredentialsSurvive(): void
	{
		$this->assertSame('https://user:pass@cdn.example.test/img.jpg', FeedValue::encodeUrl('https://user:pass@cdn.example.test/img.jpg'));
		$this->assertSame('https://user@cdn.example.test/img.jpg', FeedValue::encodeUrl('https://user@cdn.example.test/img.jpg'));
	}

	/**
	 * A relative URL is dropped by the builder, not mangled here.
	 */
	public function testRelativeUrlsPassThroughUntouched(): void
	{
		$this->assertSame('/assets/a b.jpg', FeedValue::encodeUrl('/assets/a b.jpg'));
	}

	public function testBlankValuesAreDropped(): void
	{
		$this->assertSame([], (new FeedValue('https://example.test'))->normalize(null, AttributeKind::Text));
		$this->assertSame([], (new FeedValue('https://example.test'))->normalize('', AttributeKind::Text));
		$this->assertSame([], (new FeedValue('https://example.test'))->normalize(['', '  '], AttributeKind::Text));
	}

	public function testListsSurviveAsLists(): void
	{
		$this->assertSame(
			['https://example.test/1.jpg', 'https://example.test/2.jpg'],
			(new FeedValue('https://example.test'))->normalize(['https://example.test/1.jpg', 'https://example.test/2.jpg'], AttributeKind::Image)
		);
	}

	/**
	 * What the feed actually emits for a decimal amount: the two-step the builder performs.
	 */
	private function emitted(string $amount, Currency $currency): ?string
	{
		$money = FeedValue::moneyFromDecimal($amount, $currency);

		return $money instanceof Money ? FeedValue::money($money) : null;
	}
}
