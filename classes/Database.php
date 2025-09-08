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
            exit();
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
            INSERT INTO users (chat_id, username, first_name, last_name, language, last_activity, entry_token) 
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE 
                username = VALUES(username), 
                first_name = VALUES(first_name), 
                last_name = VALUES(last_name), 
                language = VALUES(language), 
                last_activity = NOW()
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
   

    public function getCartItemQuantity(int $chatId, int $productId): int
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return 0;
        $userId = $user['id'];

        $sql = "SELECT quantity FROM carts WHERE user_id = ? AND product_id = ?";
        $stmt = $this->query($sql, [$userId, $productId]);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
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
        $sql = "SELECT id, chat_id, username, first_name, last_name, join_date, last_activity, status, language, is_admin, entry_token 
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

    public function getUserCart(int $chatId): array
    {
        $sql = "
        SELECT p.id, p.name, p.price, p.image_file_id, c.quantity 
        FROM carts c
        JOIN products p ON c.product_id = p.id
        JOIN users u ON c.user_id = u.id
        WHERE u.chat_id = ?
    ";
        $stmt = $this->query($sql, [$chatId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function addToCart(int $chatId, int $productId, int $quantity = 1): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "
        INSERT INTO carts (user_id, product_id, quantity) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ";
        $stmt = $this->query($sql, [$userId, $productId, $quantity]);
        return $stmt !== false;
    }

    public function updateCartQuantity(int $chatId, int $productId, int $newQuantity): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        if ($newQuantity <= 0) {
            return $this->removeFromCart($chatId, $productId);
        }

        $sql = "UPDATE carts SET quantity = ? WHERE user_id = ? AND product_id = ?";
        $stmt = $this->query($sql, [$newQuantity, $userId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
    }


    public function removeFromCart(int $chatId, int $productId): bool
    {
        $user = $this->getUserByChatIdOrUsername($chatId);
        if (!$user) return false;
        $userId = $user['id'];

        $sql = "DELETE FROM carts WHERE user_id = ? AND product_id = ?";
        $stmt = $this->query($sql, [$userId, $productId]);
        return $stmt && $stmt->rowCount() > 0;
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
        $sql = "INSERT INTO products (category_id, name, description, stock, price, image_file_id) 
        VALUES (:category_id, :name, :description, :stock, :price, :image_file_id)";

        $params = [
            ':category_id'   => $productData['category_id'] ?? null,
            ':name'          => $productData['name'] ?? 'بدون نام',
            ':description'   => $productData['description'] ?? '',
            ':stock'         => $productData['stock'] ?? 0, 
            ':price'         => $productData['price'] ?? 0,
            ':image_file_id' => $productData['image_file_id'] ?? null
        ];

        $stmt = $this->query($sql, $params);
        return $stmt ? $this->pdo->lastInsertId() : false;
    }
    public function getActiveProductsByCategoryId(int $categoryId): array
    {
        $stmt = $this->query("SELECT * FROM products WHERE category_id = ? AND is_active = 1", [$categoryId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getProductsByCategoryId(int $categoryId): array
    {
        $stmt = $this->query("SELECT * FROM products WHERE category_id = ?", [$categoryId]);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function getProductById(int $productId): array|false
    {
        $stmt = $this->query("SELECT * FROM products WHERE id = ? LIMIT 1", [$productId]);
        return $stmt ? $stmt->fetch() : false;
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



    //    -------------------------------- categories


    public function createNewCategory(string $categoryName): int|false
    {
        $stmt = $this->query("INSERT INTO categories (name) VALUES (?)", [$categoryName]);
        return $stmt ? $this->pdo->lastInsertId() : false;
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
    public function deleteCategoryById(int $categoryId): bool
    {
        $stmt = $this->query("DELETE FROM categories WHERE id = ?", [$categoryId]);
        return $stmt && $stmt->rowCount() > 0;
    }
    public function getAllCategories(): array
    {
        $stmt = $this->query("SELECT * FROM categories ORDER BY id ASC");
        return $stmt ? $stmt->fetchAll() : [];
    }
}