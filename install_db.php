<?php
require_once __DIR__ . '/vendor/autoload.php';

use Config\AppConfig;

try {
    $config = AppConfig::get();
    $db = $config['database'];

    $mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
    if ($mysqli->connect_errno) {
        throw new Exception("❌ خطا در اتصال به دیتابیس: " . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");

    $sqlFiles = [
        '01_database_schema.sql' => 'ساختار دیتابیس و جداول',
        '02_initial_data.sql'    => 'داده‌های اولیه و تنظیمات',
        '03_sample_data.sql'     => 'داده‌های نمونه برای تست'
    ];

    echo "🚀 شروع فرآیند نصب دیتابیس...\n";

    foreach ($sqlFiles as $fileName => $description) {
        $filePath = __DIR__ . '/sql/' . $fileName;

        if (!file_exists($filePath)) {
            throw new Exception("❌ فایل SQL پیدا نشد: {$filePath}");
        }

        $sql = file_get_contents($filePath);
        if ($mysqli->multi_query($sql)) {
            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->next_result());

            echo "✅ '{$description}' با موفقیت اجرا شد.\n";
        } else {
            throw new Exception("❌ خطا در اجرای فایل '{$fileName}': " . $mysqli->error);
        }
    }

    echo "🎉 تمام مراحل نصب دیتابیس با موفقیت انجام شد.\n";

    $mysqli->close();
} catch (Exception $e) {
    if (PHP_SAPI === 'cli') {
        echo "\033[31m" . $e->getMessage() . "\033[0m\n";
    } else {
        echo "<b style='color:red'>" . $e->getMessage() . "</b><br>";
    }
    exit(1);
}
