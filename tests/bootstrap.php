<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

require_once __DIR__ . '/../vendor/autoload.php';

$craftBasePath = getenv('CRAFT_BASE_PATH');

// The unit suite is pure PHP and needs no Craft. The integration suite runs against a real install,
// named by CRAFT_BASE_PATH, and every test in it skips itself when that is not set.
if ($craftBasePath === false || $craftBasePath === '') {
	return;
}

define('CRAFT_BASE_PATH', $craftBasePath);
define('CRAFT_VENDOR_PATH', $craftBasePath . '/vendor');

// Both this plugin and the Craft install ship a `craft\` namespace. PHPUnit has already registered the
// plugin's autoloader, so the install's has to jump the queue or Craft would boot against the copy of
// itself sitting in the plugin's dev dependencies.
/** @var ClassLoader $craftLoader */
$craftLoader = require CRAFT_VENDOR_PATH . '/autoload.php';
$craftLoader->unregister();
$craftLoader->register(true);

if (class_exists(Dotenv\Dotenv::class)) {
	Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
}

require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
