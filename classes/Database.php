<?php

namespace Bot;

use Config\AppConfig;
use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private ?PDO $pdo;
    private string $botLink;

    public function __construct()
    {
        $config = AppConfig::get();

        // Add a check to ensure config is loaded
        if (empty($config)) {
            throw new PDOException("Database configuration is not loaded. AppConfig might not be initialized for the current bot.");
        }

        $this->botLink = $config['bot']['bot_link'];
        $dbConfig = $config['database'];

        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        } catch (PDOException $e) {
            error_log("❌ Database Connection Failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function query(string $sql, array $params = []): PDOStatement|false
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("❌ SQL Query Failed: " . $e->getMessage());
            return false;
        }
    }




    //    -------------------------------- users
    public function saveUser($user, $entryToken = null): void
    {
        $sql = "
    INSERT INTO users (chat_id, username, first_name, last_name, language, entry_token, updated_at) 
    VALUES (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        username = VALUES(username), 
        first_name = VALUES(first_name), 
        last_name = VALUES(last_name), 
        language = VALUES(language),
        updated_at = NOW()
";

        $params = [
            $user['id'],
            $user['username'] ?? '',
            $user['first_name'] ?? '',
            $user['last_name'] ?? '',
            $user['language_code'] ?? 'en',
            $entryToken
        ];

        $this->query($sql, $params);
    }


    public function getUserFavorites(int $chatId): array
    {
        $sql = "
        SELECT p.* FROM favorites f
        JOIN products p ON f.product_id = p.id
        JOIN users u ON f.user_id = u.id
        WHERE u.chat_id = ?
    ";
        $stmt = $this->query($sql, [$chatId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function isProductInFavorites(int $chatId, int $productId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $stmt = $this->query("SELECT 1 FROM favorites WHERE user_id = ? AND product_id = ?", [$userId, $productId]);
        return $stmt && $stmt->fetchColumn();
    }


    public function addFavorite(int $chatId, int $productId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "INSERT IGNORE INTO favorites (user_id, product_id) VALUES (?, ?)";
        $stmt = $this->query($sql, [$userId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function removeFavorite(int $chatId, int $productId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "DELETE FROM favorites WHERE user_id = ? AND product_id = ?";
        $stmt = $this->query($sql, [$userId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function saveUserAddress(int $chatId, array $shippingData): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $stmt_check = $this->query("SELECT id FROM user_addresses WHERE user_id = ?", [$userId]);
        $existing_address = $stmt_check ? $stmt_check->fetch() : false;

        if ($existing_address) {
            $sql = "UPDATE user_addresses SET name = ?, phone = ?, address = ? WHERE user_id = ?";
            $params = [$shippingData['name'], $shippingData['phone'], $shippingData['address'], $userId];
        } else {
            $sql = "INSERT INTO user_addresses (user_id, name, phone, address) VALUES (?, ?, ?, ?)";
            $params = [$userId, $shippingData['name'], $shippingData['phone'], $shippingData['address']];
        }

        $stmt = $this->query($sql, $params);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getUserShippingInfo(int $chatId): array|false
    {
        $sql = "
        SELECT ua.name, ua.phone, ua.address 
        FROM user_addresses ua
        JOIN users u ON u.id = ua.user_id
        WHERE u.chat_id = ? 
        LIMIT 1
    ";
        $stmt = $this->query($sql, [$chatId]);
        return $stmt ? $stmt->fetch() : false;
    }
    public function getAllUsers(): array
    {
        $stmt = $this->query("SELECT * FROM users");
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function getUsernameByChatId($chatId): string
    {
        $stmt = $this->query("SELECT username FROM users WHERE chat_id = ?", [$chatId]);
        $result = $stmt ? $stmt->fetchColumn() : null;
        return $result ?? 'Unknown';
    }
    public function setUserLanguage($chatId, $language): bool
    {
        $stmt = $this->query("UPDATE users SET language = ? WHERE chat_id = ?", [$language, $chatId]);
        return (bool)$stmt;
    }
    public function getUserByUsername($username): array|false
    {
        $stmt = $this->query("SELECT * FROM users WHERE username = ? LIMIT 1", [$username]);
        return $stmt ? $stmt->fetch() : false;
    }

    public function getUserLanguage($chatId): string
    {
        $stmt = $this->query("SELECT language FROM users WHERE chat_id = ? LIMIT 1", [$chatId]);
        $result = $stmt ? $stmt->fetchColumn() : null;
        return $result ?? 'fa';
    }
    public function getUserInfo($chatId): array|false
    {
        $stmt = $this->query("SELECT * FROM users WHERE chat_id = ?", [$chatId]);
        return $stmt ? $stmt->fetch() : false;
    }
    public function getUserByChatIdOrUsername($identifier): array|false
    {
        if (is_numeric($identifier)) {
            $stmt = $this->query("SELECT * FROM users WHERE chat_id = ?", [$identifier]);
        } else {
            $username = ltrim($identifier, '@');
            $stmt = $this->query("SELECT * FROM users WHERE username = ?", [$username]);
        }
        return $stmt ? $stmt->fetch() : false;
    }
    public function getUserFullName($chatId): string
    {
        $stmt = $this->query("SELECT first_name, last_name FROM users WHERE chat_id = ?", [$chatId]);
        $user = $stmt ? $stmt->fetch() : null;
        if (!$user) {
            return '';
        }
        return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    }
    public function getUsersBatch($limit = 20, $offset = 0): array
    {
        $sql = "SELECT id, chat_id, username, first_name, last_name, join_date, status, language, is_admin, entry_token 
                FROM users 
                ORDER BY id ASC 
                LIMIT ? OFFSET ?";
        $stmt = $this->query($sql, [$limit, $offset]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function updateUserStatus($chatId, $status): bool
    {
        $stmt = $this->query("UPDATE users SET status = ? WHERE chat_id = ?", [$status, $chatId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getUserByUserId($userId): array|false
    {
        $stmt = $this->query("SELECT * FROM users WHERE chat_id = ? LIMIT 1", [$userId]);
        return $stmt ? $stmt->fetch() : false;
    }



  

   

   






 

    //    -------------------------------- admins
    public function isAdmin($chatId): bool
    {
        $stmt = $this->query("SELECT is_admin FROM users WHERE chat_id = ?", [$chatId]);
        $user = $stmt ? $stmt->fetch() : null;
        return $user && $user['is_admin'] == 1;
    }
    public function getAdmins(): array
    {
        $stmt = $this->query("SELECT id, chat_id, username FROM users WHERE is_admin = ?", [1]);
        return $stmt ? $stmt->fetchAll() : [];
    }

   

    public function getStatsSummary(): array
    {
        // بازه‌های زمانی
        $today_start = date('Y-m-d 00:00:00');
        $yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $last_7_days_start = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $low_stock_threshold = 5;

        $queries = [
            // آمار کلی
            'total_users' => "SELECT COUNT(id) FROM users",
            'total_products' => "SELECT COUNT(id) FROM products",
            'low_stock_products' => "SELECT COUNT(id) FROM products WHERE stock > 0 AND stock < {$low_stock_threshold}",
            'pending_invoices' => "SELECT COUNT(id) FROM invoices WHERE status = 'pending'",

            // آمار کاربران
            'new_users_today' => "SELECT COUNT(id) FROM users WHERE created_at >= '{$today_start}'",
            'new_users_yesterday' => "SELECT COUNT(id) FROM users WHERE created_at >= '{$yesterday_start}' AND created_at < '{$today_start}'",
            'new_users_last_7_days' => "SELECT COUNT(id) FROM users WHERE created_at >= '{$last_7_days_start}'",
            'active_users_today' => "SELECT COUNT(id) FROM users WHERE updated_at >= '{$today_start}'",
            'active_users_last_7_days' => "SELECT COUNT(id) FROM users WHERE updated_at >= '{$last_7_days_start}'",

            // آمار تعاملات (بازدیدها) - با فرض اینکه هر آپدیت یک تعامل است
            'total_interactions_today' => "SELECT COUNT(id) FROM users WHERE updated_at >= '{$today_start}'",
            'total_interactions_yesterday' => "SELECT COUNT(id) FROM users WHERE updated_at >= '{$yesterday_start}' AND updated_at < '{$today_start}'",

            // آمار مالی
            'todays_revenue' => "SELECT SUM(total_amount) FROM invoices WHERE status = 'paid' AND updated_at >= '{$today_start}'",
        ];

        $stats = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->query($sql);
            $stats[$key] = $stmt ? ($stmt->fetchColumn() ?? 0) : 0;
        }

        $stats['new_users_change_percent'] = $stats['new_users_yesterday'] > 0
            ? round((($stats['new_users_today'] - $stats['new_users_yesterday']) / $stats['new_users_yesterday']) * 100)
            : ($stats['new_users_today'] > 0 ? 100 : 0);

        $stats['interactions_change_percent'] = $stats['total_interactions_yesterday'] > 0
            ? round((($stats['total_interactions_today'] - $stats['total_interactions_yesterday']) / $stats['total_interactions_yesterday']) * 100)
            : ($stats['total_interactions_today'] > 0 ? 100 : 0);

        return array_map(function ($value) {
            return is_numeric($value) ? (float)$value : $value;
        }, $stats);
    }

    public function createAdminToken(int $chatId): ?string
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user || !$user['is_admin']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 دقیقه اعتبار

        $sql = "INSERT INTO admin_tokens (user_id, token, expires_at) VALUES (?, ?, ?)";
        $stmt = $this->query($sql, [$user['id'], $token, $expiresAt]);

        return $stmt ? $token : null;
    }


    public function validateAdminToken(string $token): array|false
    {
        $sql = "
        SELECT u.id, u.chat_id, u.is_admin
        FROM admin_tokens at
        JOIN users u ON at.user_id = u.id
        WHERE at.token = ? AND at.is_used = FALSE AND at.expires_at > NOW()
        LIMIT 1
    ";
        $stmt = $this->query($sql, [$token]);
        $user = $stmt ? $stmt->fetch() : false;

        if ($user) {
            $this->query("UPDATE admin_tokens SET is_used = TRUE WHERE token = ?", [$token]);
            return $user;
        }

        return false;
    }
    //    -------------------------------- cart


    public function getCartItemQuantityById(int $cartItemId): int
    {
        $sql = "SELECT quantity FROM carts WHERE id = ?";
        $stmt = $this->query($sql, [$cartItemId]);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }


    public function setCartItemQuantity(int $chatId, int $productId, ?int $variantId, int $quantity): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        if ($quantity <= 0) {
            $sql = "DELETE FROM carts WHERE user_id = ? AND product_id = ? AND variant_id <=> ?";
            $stmt = $this->query($sql, [$userId, $productId, $variantId]);
            return $stmt !== false;
        }

        $sql = "
        INSERT INTO carts (user_id, product_id, variant_id, quantity) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ";
        $stmt = $this->query($sql, [$userId, $productId, $variantId, $quantity]);
        return $stmt !== false;
    }
    public function clearUserCart(int $chatId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "DELETE FROM carts WHERE user_id = ?";
        $stmt = $this->query($sql, [$userId]);
        return $stmt !== false;
    }
    public function getUserCart(int $chatId): array
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return [];
        $userId = $user['id'];

        $sql = "
        SELECT 
            c.id as cart_item_id, 
            p.id as product_id, 
            p.name as product_name, 
            c.quantity,
            v.id as variant_id,
            v.variant_name,
            COALESCE(v.price, p.price) as price,
            pi.file_id as image_file_id
        FROM carts c
        JOIN users u ON c.user_id = u.id
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_variants v ON c.variant_id = v.id
        LEFT JOIN (
            SELECT product_id, file_id FROM product_images ORDER BY sort_order
        ) as pi ON p.id = pi.product_id
        WHERE u.chat_id = ?
        GROUP BY c.id
    ";
        $stmt = $this->query($sql, [$chatId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function addToCart(int $chatId, int $productId, ?int $variantId = null, int $quantity = 1): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "
        INSERT INTO carts (user_id, product_id, variant_id, quantity) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ";
        $stmt = $this->query($sql, [$userId, $productId, $variantId, $quantity]);
        return $stmt !== false;
    }

    public function updateCartQuantity(int $cartItemId, int $newQuantity): bool
    {
        if ($newQuantity <= 0) {
            return $this->removeFromCart($cartItemId);
        }
        $sql = "UPDATE carts SET quantity = ? WHERE id = ?";
        $stmt = $this->query($sql, [$newQuantity, $cartItemId]);
        return $stmt && $stmt->rowCount() > 0;
    }


    public function removeFromCart(int $cartItemId): bool
    {
        $sql = "DELETE FROM carts WHERE id = ?";
        $stmt = $this->query($sql, [$cartItemId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function removeProductFromCart(int $chatId, int $productId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) {
            return false; 
        }
        $userId = $user['id'];
        $sql = "DELETE FROM carts WHERE user_id = ? AND product_id = ?";
        $stmt = $this->query($sql, [$userId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function getCartItemQuantity(int $chatId, int $productId, ?int $variantId = null): int
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return 0;
        $userId = $user['id'];

        if ($variantId === null) {
            $sql = "SELECT SUM(quantity) FROM carts WHERE user_id = ? AND product_id = ?";
            $params = [$userId, $productId];
        } else {
            $sql = "SELECT quantity FROM carts WHERE user_id = ? AND product_id = ? AND variant_id = ?";
            $params = [$userId, $productId, $variantId];
        }

        $stmt = $this->query($sql, $params);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    public function updateCartQuantityByProduct(int $chatId, int $productId, int $newQuantity, ?int $variantId = null): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        if ($newQuantity <= 0) {
            $sql = "DELETE FROM carts WHERE user_id = ? AND product_id = ?";
            $params = [$userId, $productId];
            if ($variantId !== null) {
                $sql .= " AND variant_id = ?";
                $params[] = $variantId;
            } else {
                $sql .= " AND variant_id IS NULL";
            }
        } else {
            $sql = "UPDATE carts SET quantity = ? WHERE user_id = ? AND product_id = ?";
            $params = [$newQuantity, $userId, $productId];
            if ($variantId !== null) {
                $sql .= " AND variant_id = ?";
                $params[] = $variantId;
            } else {
                $sql .= " AND variant_id IS NULL";
            }
        }

        $stmt = $this->query($sql, $params);
        return $stmt && $stmt->rowCount() > 0;
    }

    //    -------------------------------- invoices



    public function createNewInvoice(int $chatId, array $cartItems, float $totalAmount, array $shippingInfo): int|false
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) {
            error_log("User not found for chat_id: " . $chatId);
            return false;
        }
        $userId = $user['id'];
        $userInfoJson = json_encode($shippingInfo, JSON_UNESCAPED_UNICODE);

        try {
            $this->pdo->beginTransaction();

            $sqlInvoice = "INSERT INTO invoices (user_id, total_amount, status, user_info) VALUES (?, ?, ?, ?)";
            $this->query($sqlInvoice, [$userId, $totalAmount, 'pending', $userInfoJson]);

            $invoiceId = $this->pdo->lastInsertId();

            $sqlItems = "INSERT INTO invoice_items (invoice_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
            $stmtItems = $this->pdo->prepare($sqlItems);

            foreach ($cartItems as $item) {
                $stmtItems->execute([
                    $invoiceId,
                    $item['id'], // product_id
                    $item['quantity'],
                    $item['price']
                ]);
            }
            $this->pdo->commit();
            return (int)$invoiceId;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log("Invoice creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function getInvoiceItems(int $invoiceId): array
    {
        $sql = "
                    SELECT ii.product_id, ii.quantity, ii.price, p.name 
                    FROM invoice_items ii
                    JOIN products p ON ii.product_id = p.id
                    WHERE ii.invoice_id = ?
                ";
        $stmt = $this->query($sql, [$invoiceId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function updateInvoiceReceipt(string $invoiceId, string $receiptFileId, string $status): bool
    {
        $stmt = $this->query(
            "UPDATE invoices SET receipt_file_id = ?, status = ? WHERE id = ?",
            [$receiptFileId, $status, $invoiceId]
        );
        return $stmt && $stmt->rowCount() > 0;
    }

    public function getInvoicesByStatus(string $status, int $page = 1, int $perPage = 5): array
    {
        $baseSql = "FROM invoices";
        $countSql = "SELECT COUNT(*) " . $baseSql;
        $params = [];

        if ($status !== 'all') {
            $baseSql .= " WHERE status = ?";
            $countSql .= " WHERE status = ?";
            $params[] = $status;
        }

        // ۱. دریافت تعداد کل نتایج برای صفحه‌بندی
        $totalStmt = $this->query($countSql, $params);
        $total = $totalStmt ? (int)$totalStmt->fetchColumn() : 0;

        // ۲. دریافت نتایج صفحه‌بندی شده
        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT * " . $baseSql . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $dataParams = array_merge($params, [$perPage, $offset]);

        $dataStmt = $this->query($dataSql, $dataParams);
        $invoices = $dataStmt ? $dataStmt->fetchAll() : [];

        return [
            'invoices' => $invoices,
            'total' => $total,
        ];
    }

    public function getInvoiceById($id)
    {
        $stmt = $this->query("SELECT * FROM invoices WHERE id = ? LIMIT 1", [$id]);
        return $stmt ? $stmt->fetch() : false;
    }

    public function updateInvoiceStatus(int $invoiceId, string $status): bool
    {
        $stmt = $this->query("UPDATE invoices SET status = ? WHERE id = ?", [$status, $invoiceId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getInvoicesByUserId(int $chatId): array
    {
        $stmt = $this->query("SELECT * FROM invoices WHERE user_id = ? ORDER BY created_at DESC", [$chatId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    //    -------------------------------- products

    public function createNewProduct(array $productData): int|false
    {
        // پارامترهای اصلی محصول را آماده می‌کنیم
        $productSql = "INSERT INTO products (category_id, name, description, price, stock) 
                   VALUES (:category_id, :name, :description, :price, :stock)";

        $productParams = [
            ':category_id'   => $productData['category_id'],
            ':name'          => $productData['name'],
            ':description'   => $productData['description'],
            ':price'         => $productData['price'],
            ':stock'         => $productData['stock']
        ];

        try {
            $this->pdo->beginTransaction();

            // 1. محصول اصلی را ثبت می‌کنیم
            $this->query($productSql, $productParams);
            $productId = (int)$this->pdo->lastInsertId();

            // 2. تصاویر محصول را ثبت می‌کنیم (اگر وجود داشت)
            if (!empty($productData['images'])) {
                $imageSql = "INSERT INTO product_images (product_id, file_id, sort_order) VALUES (?, ?, ?)";
                $imageStmt = $this->pdo->prepare($imageSql);
                foreach ($productData['images'] as $index => $fileId) {
                    $imageStmt->execute([$productId, $fileId, $index]);
                }
            }

            if (!empty($productData['variants'])) {
                $variantSql = "INSERT INTO product_variants (product_id, variant_name, price, stock) VALUES (?, ?, ?, ?)";
                $variantStmt = $this->pdo->prepare($variantSql);
                foreach ($productData['variants'] as $variant) {
                    $variantStmt->execute([
                        $productId,
                        $variant['name'],
                        $variant['price'],
                        $variant['stock']
                    ]);
                }
            }

            $this->pdo->commit();
            return $productId;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log("Product creation failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function getActiveDiscountedProducts(): array
    {
        $sql = "SELECT * FROM products 
                WHERE is_active = 1 
                AND discount_price IS NOT NULL 
                AND discount_price > 0 
                ORDER BY updated_at DESC";
        $stmt = $this->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function updateProductDiscount(int $productId, ?float $discountPrice): bool
    {
        $sql = "UPDATE products SET discount_price = ? WHERE id = ?";
        $stmt = $this->query($sql, [$discountPrice, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getStockForCartIdentifier(int $chatId, int $productId, string $identifier): int
    {
        $product = $this->getProductById($productId);
        if (!$product) return 0;

        $variantId = null;
        if (str_starts_with($identifier, 'new_')) {
            $variantId = (int)str_replace('new_', '', $identifier);
        } else {
            $userCart = $this->getUserCart($chatId);
            foreach ($userCart as $item) {
                if ($item['cart_item_id'] == $identifier) {
                    $variantId = $item['variant_id']; // می‌تواند null باشد
                    break;
                }
            }
        }

        if ($variantId === null) { // محصول ساده
            return (int)$product['stock'];
        } else { // محصول دارای ویژگی
            foreach ($product['variants'] as $v) {
                if ($v['id'] == $variantId) {
                    return (int)$v['stock'];
                }
            }
        }
        return 0;
    }
    public function getProductIdByVariantId(int $variantId): ?int
    {
        $stmt = $this->query("SELECT product_id FROM product_variants WHERE id = ? LIMIT 1", [$variantId]);
        $result = $stmt ? $stmt->fetchColumn() : null;
        return $result ? (int)$result : null;
    }
        public function getProductById(int $productId): array|false
    {
        $stmt = $this->query("SELECT * FROM products WHERE id = ? LIMIT 1", [$productId]);
        $product = $stmt ? $stmt->fetch() : false;

        if ($product) {
            $imagesStmt = $this->query("SELECT file_id FROM product_images WHERE product_id = ? ORDER BY sort_order ASC", [$productId]);
            $product['images'] = $imagesStmt ? $imagesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

            $variantsStmt = $this->query("SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY price ASC", [$productId]);
            $product['variants'] = $variantsStmt ? $variantsStmt->fetchAll() : [];
        }

        return $product;
    }

    public function updateChannelMessageId(int $productId, int $messageId): bool
    {
        $sql = "UPDATE products SET channel_message_id = ? WHERE id = ?";
        $stmt = $this->query($sql, [$messageId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getProductVariants(int $productId): array
    {
        $sql = "SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY price ASC";
        $stmt = $this->query($sql, [$productId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function getActiveProductsByCategoryId(int $categoryId): array
    {
        $stmt = $this->query("SELECT * FROM products WHERE category_id = ? AND is_active = 1", [$categoryId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getProductsByCategoryId(int $categoryId): array
    {
        $sql = "
            SELECT p.*, (
                SELECT pi.file_id 
                FROM product_images pi 
                WHERE pi.product_id = p.id 
                ORDER BY pi.sort_order ASC 
                LIMIT 1
            ) AS image_file_id
            FROM products p
            WHERE p.category_id = ?
        ";
        $stmt = $this->query($sql, [$categoryId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function updateProductName(int $productId, string $newName): bool
    {
        $stmt = $this->query("UPDATE products SET name = ? WHERE id = ?", [$newName, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function updateProductDescription(int $productId, string $newDescription): bool
    {
        $stmt = $this->query("UPDATE products SET description = ? WHERE id = ?", [$newDescription, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function updateProductPrice(int $productId, float $newPrice): bool
    {
        $stmt = $this->query("UPDATE products SET price = ? WHERE id = ?", [$newPrice, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function updateProductImage(int $productId, string $fileId): bool
    {
        // برای سادگی، ابتدا تمام عکس‌های قبلی محصول را حذف می‌کنیم
        $this->query("DELETE FROM product_images WHERE product_id = ?", [$productId]);
        // سپس عکس جدید را اضافه می‌کنیم
        $sql = "INSERT INTO product_images (product_id, file_id, sort_order) VALUES (?, ?, 0)";
        $stmt = $this->query($sql, [$productId, $fileId]);
        return (bool)$stmt;
    }

    public function removeProductImage(int $productId): bool
    {
        $stmt = $this->query("DELETE FROM product_images WHERE product_id = ?", [$productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function getProductsByIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = "SELECT * FROM products WHERE id IN ({$placeholders})";
        $stmt = $this->query($sql, $productIds);
        return $stmt ? $stmt->fetchAll() : [];
    }


    public function updateProductStock(int $productId, int $newStock): bool
    {
        $stmt = $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function deleteProductById(int $productId): bool
    {
        $stmt = $this->query("DELETE FROM products WHERE id = ?", [$productId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    //    -------------------------------- settings


    public function getSettingValue(string $key): ?string
    {
        $stmt = $this->query("SELECT value FROM settings WHERE `key` = ? LIMIT 1", [$key]);
        $result = $stmt ? $stmt->fetchColumn() : null;
        return $result;
    }
    public function getAllSettings(): array
    {
        $stmt = $this->query("SELECT `key`, `value` FROM settings");
        if (!$stmt) {
            return [];
        }
        // PDO::FETCH_KEY_PAIR هر ردیف را به صورت key => value برمی‌گرداند
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public function updateSetting(string $key, string $value): bool
    {
        $sql = "INSERT INTO settings (`key`, `value`) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $stmt = $this->query($sql, [$key, $value]);
        return (bool)$stmt;
    }



    //    -------------------------------- categoryes


    public function createNewCategory(string $categoryName, ?int $parentId = null): int|false
    {
        try {
            $stmt = $this->query("INSERT INTO categories (name, parent_id) VALUES (?, ?)", [$categoryName, $parentId]);
            return $stmt ? (int)$this->pdo->lastInsertId() : false;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'SQLSTATE[45000]')) {
                error_log("❌ Trigger Error in createNewCategory: " . $e->getMessage());
                return false;
            }
            throw $e;
        }
    }
    public function updateCategoryName(int $categoryId, string $newName): bool
    {
        $stmt = $this->query("UPDATE categories SET name = ? WHERE id = ?", [$newName, $categoryId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function getCategoryById(int $categoryId): array|false
    {
        $stmt = $this->query("SELECT * FROM categories WHERE id = ? LIMIT 1", [$categoryId]);
        return $stmt ? $stmt->fetch() : false;
    }
    public function deleteCategoryById(int $categoryId): bool|string
    {
        try {
            $stmt = $this->query("DELETE FROM categories WHERE id = ?", [$categoryId]);
            return $stmt && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'has_products';
            }
            error_log("❌ SQL Query Failed in deleteCategoryById: " . $e->getMessage());
            return false;
        }
    }

    public function moveCategory(int $categoryId, string $direction): bool
    {
        // ابتدا والد دسته‌بندی مورد نظر را پیدا می‌کنیم
        $stmt = $this->query("SELECT parent_id FROM categories WHERE id = ? LIMIT 1", [$categoryId]);
        $category = $stmt ? $stmt->fetch() : false;
        if (!$category) {
            return false; // دسته‌بندی وجود ندارد
        }
        $parentId = $category['parent_id'];

        // تمام هم‌سطح‌ها (فرزندان یک والد) را به ترتیب فعلی دریافت می‌کنیم
        $siblingsStmt = $this->query("SELECT id FROM categories WHERE parent_id <=> ? ORDER BY sort_order ASC, id ASC", [$parentId]);
        $siblings = $siblingsStmt ? $siblingsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        // جایگاه فعلی دسته‌بندی را در لیست پیدا می‌کنیم
        $currentIndex = array_search($categoryId, $siblings);
        if ($currentIndex === false) {
            return false; // خطای غیرمنتظره
        }

        // بر اساس جهت، جایگاه جدید را مشخص می‌کنیم
        if ($direction === 'up' && $currentIndex > 0) {
            $newIndex = $currentIndex - 1;
            // جابجایی در آرایه
            $temp = $siblings[$newIndex];
            $siblings[$newIndex] = $siblings[$currentIndex];
            $siblings[$currentIndex] = $temp;
        } elseif ($direction === 'down' && $currentIndex < count($siblings) - 1) {
            $newIndex = $currentIndex + 1;
            $temp = $siblings[$newIndex];
            $siblings[$newIndex] = $siblings[$currentIndex];
            $siblings[$currentIndex] = $temp;
        } else {
            return false;
        }

        try {
            $this->pdo->beginTransaction();
            $sql = "UPDATE categories SET sort_order = ? WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            foreach ($siblings as $index => $id) {
                $stmt->execute([$index, $id]);
            }
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Failed to reorder categories: " . $e->getMessage());
            return false;
        }
    }
    public function getCategorySiblings(int $categoryId): array
    {
        $stmt = $this->query("SELECT parent_id FROM categories WHERE id = ? LIMIT 1", [$categoryId]);
        $category = $stmt ? $stmt->fetch() : false;

        if ($category === false) {
            return [];
        }

        $parentId = $category['parent_id'];
        $sql = "SELECT id, name, sort_order FROM categories WHERE parent_id <=> ? ORDER BY sort_order ASC, id ASC";
        $stmt = $this->query($sql, [$parentId]);

        return $stmt ? $stmt->fetchAll() : [];
    }
    public function getAllCategories(): array
    {
        $stmt = $this->query("SELECT * FROM categories ORDER BY parent_id, sort_order, name");
        $categories = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $nestedCategories = [];
        $categoryMap = [];

        // First, create a map of categories by their ID for easy lookup.
        foreach ($categories as $category) {
            $categoryMap[$category['id']] = array_merge($category, ['children' => []]);
        }

        foreach ($categoryMap as $id => &$category) {
            if ($category['parent_id'] !== null && isset($categoryMap[$category['parent_id']])) {
                $categoryMap[$category['parent_id']]['children'][] = &$category;
            } else {
                $nestedCategories[] = &$category;
            }
        }

        return $nestedCategories;
    }
    public function getSubcategories(int $parentId): array
    {
        $stmt = $this->query("SELECT * FROM categories WHERE parent_id = ? ORDER BY sort_order, name", [$parentId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function getRootCategories(): array
    {
        $stmt = $this->query("SELECT * FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name");
        return $stmt ? $stmt->fetchAll() : [];
    }
    public function getCategoriesWithoutChildren(): array
    {
        $sql = "
            SELECT c.* FROM categories c
            LEFT JOIN categories sub ON c.id = sub.parent_id
            WHERE sub.id IS NULL
            ORDER BY c.name ASC
        ";
        $stmt = $this->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getCategoryDepth(int $categoryId): int
    {
        $depth = 0;
        $currentId = $categoryId;

        while ($currentId !== null) {
            $stmt = $this->query("SELECT parent_id FROM categories WHERE id = ? LIMIT 1", [$currentId]);
            $result = $stmt ? $stmt->fetch() : false;
            if ($result && $result['parent_id'] !== null) {
                $currentId = $result['parent_id'];
                $depth++;
            } else {
                $currentId = null;
            }
        }
        return $depth;
    }
    public function getCategoriesWithNoProducts(): array
    {
        $sql = "
            SELECT c.* FROM categories c
            WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id)
            ORDER BY c.parent_id, c.sort_order, c.name
        ";
        $stmt = $this->query($sql);
        $categories = $stmt ? $stmt->fetchAll() : [];

        $nestedCategories = [];
        $categoryMap = [];

        foreach ($categories as $category) {
            $categoryMap[$category['id']] = array_merge($category, ['children' => []]);
        }

        foreach ($categoryMap as $id => &$category) {
            if ($category['parent_id'] !== null && isset($categoryMap[$category['parent_id']])) {
                $categoryMap[$category['parent_id']]['children'][] = &$category;
            } else {
                $nestedCategories[] = &$category;
            }
        }
        unset($category);

        return $nestedCategories;
    }
    public function getCategoryPath(int $categoryId): string
    {
        $path = [];
        $currentId = $categoryId;

        while ($currentId !== null) {
            $stmt = $this->query("SELECT id, name, parent_id FROM categories WHERE id = ? LIMIT 1", [$currentId]);
            $category = $stmt ? $stmt->fetch() : false;
            if ($category) {
                array_unshift($path, htmlspecialchars($category['name']));
                $currentId = $category['parent_id'];
            } else {
                $currentId = null;
            }
        }
        return implode(' > ', $path);
    }
    public function getCategoryContentSummary(int $categoryId): array
    {
        $product_stmt = $this->query("SELECT COUNT(*) FROM products WHERE category_id = ?", [$categoryId]);
        $product_count = $product_stmt ? (int)$product_stmt->fetchColumn() : 0;

        $subcategory_stmt = $this->query("SELECT COUNT(*) FROM categories WHERE parent_id = ?", [$categoryId]);
        $subcategory_count = $subcategory_stmt ? (int)$subcategory_stmt->fetchColumn() : 0;

        return [
            'products' => $product_count,
            'subcategories' => $subcategory_count,
        ];
    }
    public function updateCategoryStatus(int $categoryId, bool $isActive): bool
    {
        $stmt = $this->query("UPDATE categories SET is_active = ? WHERE id = ?", [$isActive, $categoryId]);
        return $stmt && $stmt->rowCount() > 0;
    }

    public function updateCategoryParent(int $categoryId, ?int $newParentId): bool|string
    {
        $currentId = $newParentId;
        while ($currentId !== null) {
            if ($currentId == $categoryId) {
                return 'circular_dependency'; // Error code for circular dependency
            }
            $stmt = $this->query("SELECT parent_id FROM categories WHERE id = ? LIMIT 1", [$currentId]);
            $parent = $stmt ? $stmt->fetch() : false;
            $currentId = $parent ? $parent['parent_id'] : null;
        }

        try {
            $stmt = $this->query("UPDATE categories SET parent_id = ? WHERE id = ?", [$newParentId, $categoryId]);
            return $stmt && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'SQLSTATE[45000]')) {
                return 'has_products';
            }
            throw $e;
        }
    }

    public function getActiveRootCategories(): array
    {
        $stmt = $this->query("SELECT * FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order, name");
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getActiveSubcategories(int $parentId): array
    {
        $stmt = $this->query("SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, name", [$parentId]);
        return $stmt ? $stmt->fetchAll() : [];
    }
}
