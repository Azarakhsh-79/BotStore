<?php
require_once __DIR__ . '/vendor/autoload.php';

use Config\AppConfig;

try {
    $config = AppConfig::get();
    $db = $config['database'];

    echo "🚀 Starting database installation process...\n";

    // Step 1: Connect to Database
    echo "🔄 Step 1: Connecting to database '{$db['database']}'...\n";
    $mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
    if ($mysqli->connect_errno) {
        throw new Exception("❌ Failed to connect to database: " . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
    echo "✅ Database connection established successfully.\n\n";

    // Step 2: Define SQL files
    $sqlFiles = [
        '01_database_schema.sql' => 'Database schema & tables',
        '02_initial_data.sql'    => 'Initial data & settings',
        '03_sample_data.sql'     => 'Sample data for testing'
    ];

    // Step 3: Run SQL files
    foreach ($sqlFiles as $fileName => $description) {
        $filePath = __DIR__ . '/sql/' . $fileName;

        echo "🔄 Executing step: {$description}...\n";

        if (!file_exists($filePath)) {
            throw new Exception("❌ SQL file not found: {$filePath}");
        }

        $sql = file_get_contents($filePath);

        if ($mysqli->multi_query($sql)) {
            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->next_result());

            echo "✅ {$description} completed successfully.\n\n";
        } else {
            throw new Exception("❌ Error executing '{$fileName}': " . $mysqli->error);
        }
    }

    // Step 4: Finish
    echo "🎉 Database installation completed successfully!\n";

    $mysqli->close();
} catch (Exception $e) {
    if (PHP_SAPI === 'cli') {
        echo "\033[31m" . $e->getMessage() . "\033[0m\n"; // red
    } else {
        echo "<b style='color:red'>" . $e->getMessage() . "</b><br>";
    }
    exit(1);
}
