<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use craft\base\FieldInterface;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;

enum AttributeKind: string
{
	case Text = 'text';

	case LongText = 'longText';

	case Url = 'url';

	case Image = 'image';

	/** A ` > `-delimited category path. */
	case CategoryPath = 'categoryPath';

	/** Every value the source holds, each one a category in its own right rather than a path. */
	case CategoryList = 'categoryList';

	case Money = 'money';

	/** A bare number, which a typed document writes as one rather than as a string. */
	case Number = 'number';

	public function acceptsField(FieldInterface $field): bool
	{
		return match ($this) {
			self::Image => $field instanceof Assets,
			self::Url => $field instanceof PlainText,
			self::Money, self::Number => $field instanceof Number || $field instanceof PlainText,
			self::CategoryPath => $field instanceof Categories || $field instanceof PlainText,
			default => $field instanceof PlainText
				|| $field instanceof Number
				|| $field instanceof Dropdown
				|| $field instanceof RadioButtons
				|| $field instanceof Checkboxes
				|| $field instanceof Lightswitch
				|| $field instanceof Email
				|| $field instanceof Categories,
		};
	}
}
