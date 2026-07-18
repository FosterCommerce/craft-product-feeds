<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\helpers\MoneyHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTimeInterface;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\models\ImageTransform;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Money;
use Stringable;
use yii\base\InvalidConfigException;

/**
 * Turns whatever Craft hands back into the flat strings a feed attribute takes.
 *
 * An instance is scoped to one build: its category path memo must not outlive the pass that filled
 * it, or a renamed category would be republished under its old path.
 */
final class FeedValue
{
	/**
	 * @var array<int, string>
	 */
	private array $categoryPaths = [];

	/**
	 * Takes the site's base URL rather than calling `UrlHelper::siteUrl()` itself, so the class stays
	 * constructible without a booted Craft app and the unit suite can keep covering it.
	 */
	public function __construct(
		private readonly string $siteBaseUrl,
	) {
	}

	public static function money(Money $money): string
	{
		return sprintf('%s %s', MoneyHelper::toDecimal($money), $money->getCurrency()->getCode());
	}

	public static function moneyFromDecimal(string $amount, Currency $currency): ?Money
	{
		// The two guards catch different values. MoneyPHP reads an empty string as zero, and `is_numeric()`
		// rejects it; `is_numeric()` accepts a leading `+` and exponent notation, and MoneyPHP throws on
		// both. An unparseable price has to become a blank, which excludes the one item, where an escaping
		// exception would fail the whole build.
		if (! is_numeric($amount)) {
			return null;
		}

		try {
			$money = MoneyHelper::toMoney([
				'value' => $amount,
				'currency' => $currency,
			]);
		} catch (ParserException) {
			return null;
		}

		return $money instanceof Money ? $money : null;
	}

	/**
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function normalize(
		mixed $value,
		AttributeKind $kind,
		?ImageTransform $imageTransform = null,
		?int $maxLength = null,
	): array {
		$values = array_values(array_filter(
			array_map(
				fn (mixed $item): string => $this->scalarize($item, $kind, $imageTransform),
				$this->flatten($value)
			),
			static fn (string $item): bool => $item !== ''
		));

		// Only an image value is safe to absolutize blind: every one is asset-derived. A URL value is
		// hand-typed or plugin-supplied, so free text in a PlainText field would resolve to a live link.
		// This runs after the blank filter and before `encodeUrl()`: a blank would otherwise resolve to
		// the site's origin, and `encodeUrl()` leaves a relative URL untouched.
		if ($kind === AttributeKind::Image) {
			return array_map(fn (string $item): string => self::encodeUrl($this->absolutize($item)), $values);
		}

		if ($kind === AttributeKind::Url) {
			return array_map(static fn (string $item): string => self::encodeUrl($item), $values);
		}

		if ($maxLength === null) {
			return $values;
		}

		return array_map(static fn (string $item): string => StringHelper::safeTruncate($item, $maxLength), $values);
	}

	/**
	 * Percent-encodes a URL's path so `link` and `image_link` meet Google's RFC 3986 requirement.
	 *
	 * The query is left alone: encoding it would break the `&` and `=` that image services such as
	 * Small Pics pass transform parameters through.
	 */
	public static function encodeUrl(string $url): string
	{
		// `parse_url()` would read a `#` in a filename as a fragment delimiter and drop the rest.
		$parts = parse_url(str_replace('#', '%23', $url));

		if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
			return $url;
		}

		$path = implode('/', array_map(
			static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
			explode('/', $parts['path'])
		));

		return sprintf(
			'%s://%s%s%s%s%s',
			$parts['scheme'],
			// `parse_url()` splits userinfo out of the host, so dropping it here would publish a URL with
			// its credentials stripped.
			isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '',
			$parts['host'],
			isset($parts['port']) ? ':' . $parts['port'] : '',
			$path,
			isset($parts['query']) ? '?' . $parts['query'] : ''
		);
	}

	/**
	 * Resolves against the site's origin rather than through `UrlHelper::siteUrl()`, which joins against
	 * the site's full base URL and would splice a subdirectory site's path into every asset URL.
	 *
	 * A feed is not served from a containing page, so a document-relative value has no referent and is
	 * resolved as root-relative.
	 */
	private function absolutize(string $url): string
	{
		if (UrlHelper::isAbsoluteUrl($url)) {
			return $url;
		}

		$base = parse_url($this->siteBaseUrl);

		if (! isset($base['scheme'], $base['host'])) {
			return $url;
		}

		if (UrlHelper::isProtocolRelativeUrl($url)) {
			return $base['scheme'] . ':' . $url;
		}

		$origin = sprintf(
			'%s://%s%s',
			$base['scheme'],
			$base['host'],
			isset($base['port']) ? ':' . $base['port'] : ''
		);

		return $origin . '/' . ltrim($url, '/');
	}

	/**
	 * @return list<mixed>
	 */
	private function flatten(mixed $value): array
	{
		if ($value instanceof ElementQueryInterface) {
			$value = $value->all();
		}

		if ($value instanceof ElementCollection) {
			$value = $value->all();
		}

		if (is_array($value)) {
			return array_values($value);
		}

		return $value === null ? [] : [$value];
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function scalarize(mixed $value, AttributeKind $kind, ?ImageTransform $imageTransform = null): string
	{
		if ($value instanceof Asset) {
			return $imageTransform instanceof ImageTransform
				? (string) ImageUrl::forAsset($value, $imageTransform)
				: (string) $value->getUrl();
		}

		if ($value instanceof Category && $kind === AttributeKind::CategoryPath) {
			return $this->categoryPath($value);
		}

		if ($value instanceof ElementInterface) {
			return $value->title ?? '';
		}

		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		if (is_bool($value)) {
			return $value ? 'yes' : 'no';
		}

		if (is_array($value)) {
			return '';
		}

		$string = $value instanceof Stringable || is_scalar($value) ? (string) $value : '';

		return match ($kind) {
			AttributeKind::LongText, AttributeKind::Text => $this->plainText($string),
			default => trim($string),
		};
	}

	/**
	 * Google rejects markup in `title` and `description`.
	 */
	private function plainText(string $value): string
	{
		$stripped = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $value));

		return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5)));
	}

	private function categoryPath(Category $category): string
	{
		$id = $category->id;
		if ($id === null) {
			return (string) $category->title;
		}

		if (! isset($this->categoryPaths[$id])) {
			$titles = [];
			foreach ($category->getAncestors()->all() as $ancestor) {
				if ($ancestor instanceof ElementInterface) {
					$titles[] = (string) $ancestor->title;
				}
			}

			$titles[] = (string) $category->title;
			$this->categoryPaths[$id] = implode(' > ', array_filter($titles));
		}

		return $this->categoryPaths[$id];
	}
}
