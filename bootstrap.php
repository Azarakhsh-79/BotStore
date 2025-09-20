<?php

// bootstrap.php

// تعریف یک ثابت برای مسیر اصلی پروژه که در همه جا قابل استفاده باشد
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__);
}

// بارگذاری فایل autoload.php که توسط Composer ساخته شده است
// این فایل مسئول بارگذاری تمام کلاس‌ها و کتابخانه‌های پروژه است
require_once ROOT_PATH . '/vendor/autoload.php';
