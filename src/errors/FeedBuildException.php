<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\errors;

use yii\base\Exception;

/**
 * A build failure retrying will not fix, so the job reports it instead of re-queueing.
 */
class FeedBuildException extends Exception
{
}
