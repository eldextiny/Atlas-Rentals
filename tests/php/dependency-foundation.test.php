<?php
declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Run composer install before dependency verification.');
}
require $autoload;

if (!class_exists(\libphonenumber\PhoneNumberUtil::class)) {
    throw new RuntimeException('PHP libphonenumber is not available through Composer autoloading.');
}

$util = \libphonenumber\PhoneNumberUtil::getInstance();
$number = $util->parse('0802 855 7479', 'NG');
if (!$util->isValidNumber($number)) {
    throw new RuntimeException('PHP libphonenumber metadata validation failed.');
}
if ($util->format($number, \libphonenumber\PhoneNumberFormat::E164) !== '+2348028557479') {
    throw new RuntimeException('PHP libphonenumber E.164 normalization failed.');
}

echo "dependency foundation tests passed\n";
