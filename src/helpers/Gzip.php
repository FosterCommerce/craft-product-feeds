<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

final class Gzip
{
	/**
	 * ISIZE is the uncompressed size modulo 2^32 (RFC 1952), so a file over 4 GiB reports the remainder.
	 */
	public static function uncompressedSize(string $filePath): ?int
	{
		$size = filesize($filePath);
		if ($size === false || $size < 4) {
			return null;
		}

		$handle = fopen($filePath, 'rb');
		if ($handle === false) {
			return null;
		}

		fseek($handle, -4, SEEK_END);
		$isizeBytes = fread($handle, 4);
		fclose($handle);

		if ($isizeBytes === false || strlen($isizeBytes) !== 4) {
			return null;
		}

		/** @var array{1: int} $unpacked */
		$unpacked = unpack('V', $isizeBytes);

		return $unpacked[1];
	}
}
