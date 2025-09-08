<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;
use Bot\jdf;
use Bot\Logger;

class BotHandler
{
    private $chatId;
    private $text;
    private $messageId;
    private $message;
    public $db;
    private $fileHandler;
    private $zarinpalPaymentHandler;
    private $botToken;
    private $botLink;
    private $callbackQueryId;

    public function __construct($chatId, $text, $messageId, $message)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageId = $messageId;
        $this->message = $message;
        $this->db = new Database();
        $this->fileHandler = new FileHandler();
        $this->botToken = AppConfig::get("bot.token");
        $this->botLink = AppConfig::get("bot.bot_link");
    }

    public function deleteMessage($messageId, $delay = 0)
    {
        if (!$messageId) {
            return false;
        }
        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId
        ];
        sleep($delay);
        $response = $this->sendRequest("deleteMessage", $data);
    }
    public function deleteMessages(array $messageIds): bool
    {
        if (empty($messageIds) || count($messageIds) > 100) {
            return false;
        }
        $data = [
            'chat_id' => $this->chatId,
            'message_ids' => $messageIds
        ];
        $response = $this->sendRequest("deleteMessages", $data);
        return $response['ok'] ?? false;
    }

    public function deleteMessageWithDelay(): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id" => $this->chatId,
            "message_id" => $this->messageId
        ]);
    }

    public function handleSuccessfulPayment($update): void
    {
        $userLanguage = $this->db->getUserLanguage($this->chatId);
        if (isset($update['message']['successful_payment'])) {
            $chatId = $update['message']['chat']['id'];
            $payload = $update['message']['successful_payment']['invoice_payload'];
            $successfulPayment = $update['message']['successful_payment'];
        }
    }

    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - Received pre_checkout_query: " . print_r($update, true) . "\n", FILE_APPEND);
            $url = "https://api.telegram.org/bot" . $this->botToken . "/answerPreCheckoutQuery";
            $post_fields = [
                'pre_checkout_query_id' => $query_id,
                'ok' => true,
                'error_message' => ""
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            curl_close($ch);
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - answerPreCheckoutQuery Response: " . print_r(json_decode($response, true), true) . "\n", FILE_APPEND);
        }
    }

    public function handleCallbackQuery($callbackQuery): void
    {
        $callbackData = $callbackQuery["data"] ?? null;
        $this->callbackQueryId = $callbackQuery["id"] ?? null;
        $chatId = $this->chatId ?? null;
        $messageId =  $this->messageId ?? null;
        $currentKeyboard = $callbackQuery["message"]["reply_markup"]["inline_keyboard"] ?? [];

        $user = $this->message['from'] ?? $this->callbackQuery['from'] ?? null;

        if ($user !== null) {
            $this->db->saveUser($user);
        } else {
            error_log("❌ Cannot save user: 'from' is missing in both message and callbackQuery.");
        }
        if (!$callbackData || !$chatId || !$this->callbackQueryId || !$messageId) {
            error_log("Callback query missing required data.");
            return;
        }

        try {
            if ($callbackData === 'main_menu') {
                $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($MessageIds)) {
                    $this->deleteMessages($MessageIds);
                    $this->fileHandler->clearMessageIds($this->chatId);
                }
                $this->MainMenu($messageId);
                return;
            } elseif ($callbackData === 'my_orders') {
                $this->showMyOrdersList(1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'my_orders_page_')) {
                $page = (int) str_replace('my_orders_page_', '', $callbackData);
                $this->showMyOrdersList($page, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'show_order_details_')) {
                $invoiceId = str_replace('show_order_details_', '', $callbackData);
                $this->showSingleOrderDetails($invoiceId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'show_order_summary_')) { // ▼▼▼ بلوک جدید ▼▼▼
                $invoiceId = str_replace('show_order_summary_', '', $callbackData);
                $this->showOrderSummaryCard($invoiceId, $messageId);
                return;
            } elseif ($callbackData === 'contact_support') {
                $this->showSupportInfo($messageId);
                return;
            } elseif ($callbackData === 'contact_support') {
                $this->showSupportInfo($messageId);
                return;
            } elseif ($callbackData === 'main_menu2') {
                $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($MessageIds)) {
                    $this->deleteMessages($MessageIds);
                    $this->fileHandler->clearMessageIds($this->chatId);
                }
                $this->MainMenu();
                return;
            } elseif ($callbackData === 'nope') {
                return;
            } elseif ($callbackData === 'admin_bot_settings') {
                $this->showBotSettingsMenu($messageId);
                return;
            } elseif ($callbackData === 'show_store_rules') {
                $this->showStoreRules($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'edit_setting_')) {
                $key = str_replace('edit_setting_', '', $callbackData);

                $fieldMap = [
                    'store_name' => 'نام فروشگاه',
                    'main_menu_text' => 'متن منوی اصلی',
                    'delivery_price' => 'هزینه ارسال (به تومان)',
                    'tax_percent' => 'درصد مالیات (فقط عدد)',
                    'discount_fixed' => 'مبلغ تخفیف ثابت (به تومان)',
                    'card_number' => 'شماره کارت (بدون فاصله)',
                    'card_holder_name' => 'نام و نام خانوادگی صاحب حساب',
                    'support_id' => 'آیدی پشتیبانی تلگرام (با @)',
                    'store_rules' => 'قوانین فروشگاه (متن کامل)',
                    'channel_id' => 'آیدی کانال فروشگاه (با @)',
                ];

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                if (!isset($fieldMap[$key])) {
                    $this->Alert("خطا: تنظیمات نامشخص است.");
                    return;
                }

                $stateData = json_encode(['message_id' => $messageId]);
                $this->fileHandler->addData($this->chatId, [
                    'state' => "editing_setting_{$key}",
                    'state_data' => $stateData
                ]);

                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$key]}\" را ارسال کنید.";

                $res =  $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $promptText,
                    'parse_mode' => 'HTML'
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);

                return;
            } elseif ($callbackData === 'activate_inline_search') {
                $this->activateInlineSearch($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_approve_')) {
                $invoiceId = (int) str_replace('admin_approve_', '', $callbackData);
                $invoice = $this->db->getInvoiceById($invoiceId);

                if (!$invoice || $invoice['status'] === 'paid') {
                    $this->Alert("این فاکتور قبلاً تایید شده یا یافت نشد.");
                    return;
                }

                $invoiceItems = $this->db->getInvoiceItems($invoiceId);
                if (!empty($invoiceItems)) {
                    foreach ($invoiceItems as $item) {
                        $productData = $this->db->getProductById($item['product_id']);
                        if ($productData) {
                            $newStock = $productData['stock'] - $item['quantity'];
                            $newStock = max(0, $newStock); // جلوگیری از منفی شدن موجودی
                            $this->db->updateProductStock($item['product_id'], $newStock);
                        }
                    }
                }

                $this->db->updateInvoiceStatus($invoiceId, 'paid');

                $userId = $invoice['user_id'] ?? null;
                if ($userId) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $userId,
                        'text' => "✅ سفارش شما با شماره فاکتور `{$invoiceId}` تایید شد و به زودی برای شما ارسال خواهد شد. سپاس از خرید شما!",
                        'parse_mode' => 'Markdown'
                    ]);
                }

                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ✅ این فاکتور توسط شما تایید شد. ---",
                    'parse_mode' => 'HTML'
                ]);

                return;
            } elseif (str_starts_with($callbackData, 'admin_reject_')) {

                $invoiceId = (int) str_replace('admin_reject_', '', $callbackData);
                $this->db->updateInvoiceStatus($invoiceId, 'canceled');
                $invoice = $this->db->getInvoiceById($invoiceId);
                $userId = $invoice['user_id'] ?? null;
                $supportId = $this->db->getSettingValue('support_id') ?? 'پشتیبانی';

                if ($userId) {
                    $this->sendRequest("sendMessage", [
                        'chat_id' => $userId,
                        'text' => "❌ متاسفانه پرداخت شما برای فاکتور `{$invoiceId}` رد شد. لطفاً برای پیگیری با پشتیبانی ({$supportId}) تماس بگیرید.",
                        'parse_mode' => 'Markdown'
                    ]);
                }

                $originalText = $callbackQuery['message']['text'];
                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $originalText . "\n\n--- ❌ این فاکتور توسط شما رد شد. ---",
                    'parse_mode' => 'HTML'
                ]);

                return;
            } elseif ($callbackData === 'show_favorites') {
                $this->showFavoritesList(1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'fav_list_page_')) {
                $page = (int) str_replace('fav_list_page_', '', $callbackData);
                $this->showFavoritesList($page, $messageId);
                return;
            } elseif ($callbackData === 'edit_cart') {
                $this->showCartInEditMode($messageId);
                return;
            } elseif ($callbackData === 'show_cart') {
                $this->showCart($messageId);
                return;
            } elseif ($callbackData === 'clear_cart') {
                $this->db->clearUserCart($this->chatId);
                $this->Alert("🗑 سبد خرید شما با موفقیت خالی شد.");
                $this->showCart($messageId); 
                return;
            } elseif ($callbackData === 'complete_shipping_info' || $callbackData === 'edit_shipping_info') {

                $this->fileHandler->addData($this->chatId, [
                    'state' => "entering_shipping_name",
                    'state_data' => '[]'
                ]);

                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "لطفاً نام و نام خانوادگی کامل گیرنده را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'checkout') {
                $this->initiateCardPayment($messageId); // جایگزینی با تابع جدید
                return;
            } elseif (str_starts_with($callbackData, 'upload_receipt_')) {
                $invoiceId = str_replace('upload_receipt_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, 'awaiting_receipt_' . $invoiceId);
                $this->Alert("لطفاً تصویر رسید خود را ارسال کنید...", true);
                return;
            } elseif (strpos($callbackData, 'admin_edit_product_') === 0) {
                sscanf($callbackData, "admin_edit_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                if ($productId && $categoryId && $page) {
                    $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
                }
                return;
            } elseif (str_starts_with($callbackData, 'confirm_product_edit_')) {
                sscanf($callbackData, "confirm_product_edit_%d_cat_%d_page_%d", $productId, $categoryId, $page);

                if (empty($productId) || empty($categoryId) || empty($page)) {
                    $this->Alert("خطا: اطلاعات ویرایش محصول ناقص است.");
                    return;
                }

                $product = $this->db->getProductById($productId);
                if (empty($product)) {
                    $this->Alert("خطا: محصول یافت نشد.");
                    return;
                }

                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $productText = $this->generateProductCardText($product);
                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $originalKeyboard
                    ]);
                }
                $this->Alert("✅ محصول با موفقیت ویرایش شد.", false);
                return;
            } elseif (strpos($callbackData, 'edit_field_') === 0) {
                sscanf($callbackData, "edit_field_%[^_]_%d_%d_%d", $field, $productId, $categoryId, $page);
                if ($field === 'imagefileid') {
                    $field = 'image_file_id';
                }

                $fieldMap = [
                    'name' => 'نام',
                    'description' => 'توضیحات',
                    'count' => 'تعداد',
                    'price' => 'قیمت',
                    'image_file_id' => 'عکس'
                ];

                if (!isset($fieldMap[$field])) {
                    $this->Alert("خطا: فیلد نامشخص است.");
                    return;
                }

                $stateData = json_encode([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'page' => $page,
                    'message_id' => $messageId
                ]);

                $this->fileHandler->addData($this->chatId, [
                    'state' => "editing_product_{$field}",
                    'state_data' => $stateData
                ]);


                $promptText = "لطفاً مقدار جدید برای \"{$fieldMap[$field]}\" را ارسال کنید.";
                if ($field === 'image_file_id') {
                    $promptText .= " (یا /remove برای حذف عکس)";
                }

                $this->Alert($promptText, true);

                return;
            } else if (strpos($callbackData, 'list_products_cat_') === 0) {
                sscanf($callbackData, "list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    $this->showProductListByCategory($categoryId, $page, $messageId);
                }
                return;
            } elseif (strpos($callbackData, 'product_creation_back_to_') === 0) {
                $targetState = str_replace('product_creation_back_to_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, 'adding_product_' . $targetState);

                $text = "";
                $reply_markup = [];

                switch ('adding_product_' . $targetState) {
                    case 'adding_product_name':
                        $text = "لطفاً نام محصول را مجدداً وارد کنید:";
                        $reply_markup = [
                            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]
                        ];
                        break;
                    case 'adding_product_description':
                        $text = "لطفاً توضیحات محصول را مجدداً وارد کنید:";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_count':
                        $text = "لطفاً تعداد موجودی محصول را مجدداً وارد کنید (فقط عدد انگلیسی):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                    case 'adding_product_price':
                        $text = "لطفاً قیمت محصول را مجدداً وارد کنید (فقط عدد انگلیسی و به تومان):";
                        $reply_markup = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                    ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                                ]
                            ]
                        ];
                        break;
                }

                $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'reply_markup' => $reply_markup
                ]);
                return;
            } elseif ($callbackData === 'product_confirm_save') {
                $stateDataJson = $this->fileHandler->getStateData($this->chatId);

                if ($stateDataJson) {
                    $this->createNewProduct($stateDataJson);
                }
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $this->fileHandler->saveState($this->chatId, null);

                $this->Alert("✅ محصول با موفقیت ذخیره شد!");
                $this->deleteMessage($messageId);
                $this->showProductManagementMenu(null);

                return;
            } elseif ($callbackData === 'product_confirm_cancel') {
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $this->Alert("❌ عملیات افزودن محصول لغو شد.");
                $this->deleteMessage($messageId);
                $this->showProductManagementMenu(null);
                return;
            } elseif ($callbackData === 'admin_panel_entry') {
                $this->showAdminMainMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_manage_invoices') {
                $this->showInvoiceManagementMenu($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_list_invoices_')) {
                $parts = explode('_', $callbackData);

                $page = (int) array_pop($parts);
                array_pop($parts);
                $status = implode('_', array_slice($parts, 3));
                if ($status && $page) {

                    if (isset($callbackQuery['message']['photo'])) {
                        $this->deleteMessage($messageId);
                        $this->showInvoiceListByStatus($status, $page, null);
                    } else {
                        $this->showInvoiceListByStatus($status, $page, $messageId);
                    }
                }
                return;
            } elseif (str_starts_with($callbackData, 'admin_view_invoice:')) {
                $parts = explode(':', $callbackData);

                $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($MessageIds)) {
                    $this->deleteMessages($MessageIds);
                    $this->fileHandler->clearMessageIds($this->chatId);
                }

                if (count($parts) === 4) {
                    $invoiceId = $parts[1];
                    $fromStatus = $parts[2];
                    $fromPage = (int) $parts[3];
                    $this->showAdminInvoiceDetails($invoiceId, $fromStatus, $fromPage, $messageId);
                }
                return;
            } elseif ($callbackData === 'show_about_us') {
                $this->showAboutUs();
                return;
            } elseif ($callbackData === 'admin_manage_categories') {
                $this->showCategoryManagementMenu($messageId);
                return;
            } elseif ($callbackData === 'admin_category_list') {
                $this->showCategoryList($messageId);
                return;
            } elseif (str_starts_with($callbackData, 'cart_remove_')) {
                $productId = (int) str_replace('cart_remove_', '', $callbackData);
                $isRemoved = $this->db->removeFromCart($this->chatId, $productId);
                if ($isRemoved) {
                    $this->deleteMessage($messageId);
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                } else {
                    $this->Alert("خطا: محصول مورد نظر در سبد خرید شما یافت نشد.");
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_increase_')) {
                $productId = (int) str_replace('edit_cart_increase_', '', $callbackData);
                $currentQuantity = $this->db->getCartItemQuantity($this->chatId, $productId);

                if ($currentQuantity > 0) {
                    $this->db->updateCartQuantity($this->chatId, $productId, $currentQuantity + 1);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_decrease_')) {
                $productId = (int) str_replace('edit_cart_decrease_', '', $callbackData);
                $currentQuantity = $this->db->getCartItemQuantity($this->chatId, $productId);

                if ($currentQuantity > 0) {
                    $this->db->updateCartQuantity($this->chatId, $productId, $currentQuantity - 1);
                    $this->refreshCartItemCard($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_remove_')) {
                $productId = (int) str_replace('edit_cart_remove_', '', $callbackData);
                $isRemoved = $this->db->removeFromCart($this->chatId, $productId);

                if ($isRemoved) {
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا: محصول در سبد خرید یافت نشد.");
                }
                return;
            } elseif (str_starts_with($callbackData, 'cart_increase_')) {
                $productId = (int) str_replace('cart_increase_', '', $callbackData);
                $isAdded = $this->db->addToCart($this->chatId, $productId, 1);

                if ($isAdded) {
                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("✅ یک عدد اضافه شد", false); 
                } else {
                    $this->Alert("خطا در افزایش تعداد محصول.");
                }

                return;
            } elseif (str_starts_with($callbackData, 'cart_decrease_')) {
                $productId = (int) str_replace('cart_decrease_', '', $callbackData);
                $currentQuantity = $this->db->getCartItemQuantity($this->chatId, $productId);
                if ($currentQuantity > 0) {
                    $this->db->updateCartQuantity($this->chatId, $productId, $currentQuantity - 1);

                    $this->refreshProductCard($productId, $messageId);
                    $this->Alert("از سبد خرید کم شد", false);
                }
                return;
            }elseif (str_starts_with($callbackData, 'category_')) {
                $categoryId = (int) str_replace('category_', '', $callbackData);
                $this->showUserProductList($categoryId, 1, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'user_list_products_cat_')) {
                sscanf($callbackData, "user_list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    $this->showUserProductList($categoryId, $page, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'toggle_favorite_')) {
                $productId = (int) str_replace('toggle_favorite_', '', $callbackData);
                $product = $this->db->getProductById($productId);
                if (!$product) {
                    $this->Alert("❌ محصول یافت نشد.");
                    return;
                }

                $message = "";
                if ($this->db->isProductInFavorites($this->chatId, $productId)) {
                    $this->db->removeFavorite($this->chatId, $productId);
                    $message = "از علاقه‌مندی‌ها حذف شد.";
                } else {
                    $this->db->addFavorite($this->chatId, $productId);
                    $message = "به علاقه‌مندی‌ها اضافه شد.";
                }

                $this->refreshProductCard($productId, $messageId);
                $this->Alert("❤️ " . $message, false);

                return;
            } elseif (str_starts_with($callbackData, 'add_to_cart_')) {
                $productId = (int) str_replace('add_to_cart_', '', $callbackData);

                $product = $this->db->getProductById($productId);

                if (!$product || ($product['stock'] ?? 0) <= 0) {
                    $this->Alert("❌ متاسفانه موجودی این محصول به اتمام رسیده است.");
                    return;
                }

                $isAdded = $this->db->addToCart($this->chatId, $productId, 1);

                if ($isAdded) {
                    $this->Alert("✅ به سبد خرید اضافه شد", false);
                    $this->refreshProductCard($productId, $messageId);
                } else {
                    $this->Alert("خطا در افزودن محصول به سبد خرید.");
                }

                return;
            } elseif (strpos($callbackData, 'admin_edit_category_') === 0) {
                $categoryId = (int) str_replace('admin_edit_category_', '', $callbackData);

                $category = $this->db->getCategoryById($categoryId);

                if ($category) {
                    $this->fileHandler->saveState($this->chatId, "editing_category_name_{$categoryId}");

                    $res = $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "لطفاً نام جدید دسته‌بندی را وارد کنید: {$category['name']}",
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [["text" => "🔙 بازگشت", "callback_data" => "admin_manage_categories"]]
                            ]
                        ]
                    ]);
                    $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                } else {
                    $this->Alert("دسته‌بندی یافت نشد.");
                }
            } elseif (strpos($callbackData, 'admin_delete_category_') === 0) {
                $categoryId = (int) str_replace('admin_delete_category_', '', $callbackData);

                $category = $this->db->getCategoryById($categoryId);
                if (!$category) {
                    $this->Alert("دسته‌بندی یافت نشد.");
                    return;
                }

                $isDeleted = $this->db->deleteCategoryById($categoryId);
                if ($isDeleted) {
                    $this->Alert("دسته‌بندی با موفقیت حذف شد.");
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا در حذف دسته‌بندی. لطفاً دوباره تلاش کنید.");
                }
            } elseif (strpos($callbackData, 'product_cat_select_') === 0) {
                $categoryId = (int) str_replace('product_cat_select_', '', $callbackData);

                $this->fileHandler->addData($this->chatId, [
                    'state' =>  'adding_product_name',
                    'state_data' => json_encode(['category_id' => $categoryId])
                ]);

                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ دسته‌بندی انتخاب شد.\n\nحالا لطفاً نام محصول را وارد کنید:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]
                    ]
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                return;
            } elseif ($callbackData === 'admin_manage_products') {
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $this->showProductManagementMenu($messageId);
            } elseif ($callbackData === 'admin_add_product') {
                $this->promptForProductCategory($messageId);
            } elseif ($callbackData === 'admin_product_list') {
                $this->promptUserForCategorySelection($messageId);
            } elseif (strpos($callbackData, 'admin_delete_product_') === 0) {

                sscanf($callbackData, "admin_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);
                $product = $this->db->getProductById($productId);
                if (!$product) {
                    $this->Alert("خطا: محصول یافت نشد!");
                    return;
                }

                $confirmationText = "❓ آیا از حذف محصول \"{$product['name']}\" مطمئن هستید؟";
                $confirmationKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ بله، حذف کن', 'callback_data' => 'confirm_delete_product_' . $productId],
                            ['text' => '❌ خیر، انصراف', 'callback_data' => 'cancel_delete_product_' . $productId . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $confirmationText,
                        'reply_markup' => $confirmationKeyboard
                    ]);
                }
                return;
            } elseif (strpos($callbackData, 'confirm_delete_product_') === 0) {
                $productId = (int) str_replace('confirm_delete_product_', '', $callbackData);
                $isDeleted = $this->db->deleteProductById($productId);
                if ($isDeleted) {
                    $this->deleteMessage($messageId);
                    $this->Alert("✅ محصول با موفقیت حذف شد.");
                } else {
                    $this->Alert("❌ خطایی در حذف محصول رخ داد. ممکن است قبلاً حذف شده باشد.");
                }
                return;
            } elseif (strpos($callbackData, 'cancel_delete_product_') === 0) {
                sscanf($callbackData, "cancel_delete_product_%d_cat_%d_page_%d", $productId, $categoryId, $page);

                $product = $this->db->getProductById($productId);

                if (!$product || !$categoryId || !$page) {
                    $this->Alert("خطا در بازگردانی محصول.");
                    return;
                }

                $productText = $this->generateProductCardText($product);

                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                if (!empty($product['image_file_id'])) {
                    $this->sendRequest("editMessageCaption", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'caption' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($originalKeyboard)
                    ]);
                } else {
                    $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => $productText,
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($originalKeyboard)
                    ]);
                }

                return;
            } elseif ($callbackData === 'admin_reports') {
                $this->Alert("این بخش هنوز آماده نیست.");
            } elseif ($callbackData === 'admin_add_category') {
                $this->fileHandler->saveState($this->chatId, 'adding_category_name');

                $res = $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام دسته‌بندی جدید را وارد کنید:",
                    "reply_markup" =>
                    [
                        "inline_keyboard" => [
                            [["text" => "🔙 بازگشت", "callback_data" => "admin_panel_entry"]]
                        ]
                    ]
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
            } else {
                $this->sendRequest("answerCallbackQuery", [
                    "callback_query_id" => $this->callbackQueryId,
                    "text" => "در حال پردازش درخواست شما..."
                ]);
            }
        } catch (\Throwable $th) {
            Logger::log('error', 'BotHandler::handleCallbackQuery', 'message: ' . $th->getMessage(), ['callbackQuery' => $callbackQuery]);
            return;
        }
    }

    public function handleRequest(): void
    {
        if (isset($this->message["from"])) {
            $this->db->saveUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field is missing.");
            return;
        }

        $state = $this->fileHandler->getState($this->chatId) ?? null;

        try {
           
            // مدیریت دستور /start
            if (str_starts_with($this->text, "/start")) {
                $this->deleteMessage($this->messageId);
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $parts = explode(' ', $this->text);
                if (isset($parts[1]) && str_starts_with($parts[1], 'product_')) {
                    $productId = (int) str_replace('product_', '', $parts[1]);
                    $this->showSingleProduct($productId);
                } else {
                    $this->MainMenu();
                }
                return;
            }

            // مدیریت سایر دستورات
            if ($this->text === "/cart") {
                $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($previousMessageIds)) $this->deleteMessages($previousMessageIds);
                $this->showCart();
                return;
            }

            if ($this->text === "/favorites") {
                $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($previousMessageIds)) $this->deleteMessages($previousMessageIds);
                $this->showFavoritesList();
                return;
            }

            // --- مدیریت وضعیت‌ها (States) ---

            // وضعیت: در انتظار رسید پرداخت
            if (str_starts_with($state, 'awaiting_receipt_')) {
                $this->deleteMessage($this->messageId);
                if (!isset($this->message['photo'])) {
                    $this->Alert("خطا: لطفاً فقط تصویر رسید را ارسال کنید.");
                    return;
                }

                $invoiceId = str_replace('awaiting_receipt_', '', $state);
                $receiptFileId = end($this->message['photo'])['file_id'];

                $this->db->updateInvoiceReceipt($invoiceId, $receiptFileId, 'pending');
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $this->Alert("✅ رسید شما با موفقیت دریافت شد. پس از بررسی، نتیجه به شما اطلاع داده خواهد شد.");
                $this->notifyAdminOfNewReceipt($invoiceId, $receiptFileId);
                $this->MainMenu();
                return;
            }

            // وضعیت: در حال ویرایش نام دسته‌بندی
            if (str_starts_with($state, 'editing_category_name_')) {
                $categoryName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }

                $categoryId = (int) str_replace('editing_category_name_', '', $state);
                if ($this->db->updateCategoryName($categoryId, $categoryName)) {
                    $this->fileHandler->addData($this->chatId, [
                        'state' => null,
                        'state_data' => null
                    ]);
                    $messageId = $this->fileHandler->getMessageId($this->chatId);
                    $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "دسته‌بندی با موفقیت ویرایش شد: {$categoryName}",
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [
                                    ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                                    ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                                ]
                            ]
                        ]
                    ]);
                } else {
                    $this->Alert("خطا در ویرایش دسته‌بندی.");
                }
                return;
            }

            // وضعیت: در حال افزودن دسته‌بندی جدید
            if ($state === "adding_category_name") {
                $categoryName = trim($this->text);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته‌بندی نمی‌تواند خالی باشد.");
                    return;
                }

                if ($this->db->createNewCategory($categoryName)) {
                    $this->deleteMessage($this->messageId);
                    $this->fileHandler->addData($this->chatId, [
                        'state' => null,
                        'state_data' => null
                    ]);
                    $messageId = $this->fileHandler->getMessageId($this->chatId);
                    $this->sendRequest('editMessageText', [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => "✅ دسته‌بندی جدید با موفقیت ایجاد شد."
                    ]);
                    sleep(2);
                    $this->showCategoryManagementMenu($messageId ?? null);
                } else {
                    $this->Alert("خطا در ایجاد دسته‌بندی.");
                }
                return;
            }

            // وضعیت: در حال ویرایش تنظیمات
            if (str_starts_with($state, 'editing_setting_')) {
                $key = str_replace('editing_setting_', '', $state);
                $value = trim($this->text);
                $this->deleteMessage($this->messageId);

                $numericFields = ['delivery_price', 'tax_percent', 'discount_fixed'];
                if (in_array($key, $numericFields) && !is_numeric($value)) {
                    $this->Alert("مقدار وارد شده باید یک عدد معتبر باشد.");
                    return;
                }

                $this->db->updateSetting($key, $value);
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $messageId = $this->fileHandler->getMessageId($this->chatId);
                $this->showBotSettingsMenu($messageId ?? null);
                return;
            }

            // مدیریت سایر وضعیت‌ها مانند ساخت محصول و اطلاعات ارسال
            if (in_array($state, ['adding_product_name', 'adding_product_description', 'adding_product_count', 'adding_product_price', 'adding_product_photo'])) {
                $this->handleProductCreationSteps();
                return;
            }
            if (in_array($state, ['entering_shipping_name', 'entering_shipping_phone', 'entering_shipping_address'])) {
                $this->handleShippingInfoSteps();
                return;
            }
        } catch (\Throwable $th) {
            Logger::log('error', 'BotHandler::handleRequest', 'message: ' . $th->getMessage(), ['chat_id' => $this->chatId, 'text' => $this->text]);
        }
    }

    private function handleShippingInfoSteps(): void
    {
        $state = $this->fileHandler->getState($this->chatId) ?? null;
        $stateDataJson = $this->fileHandler->getStateData($this->chatId);
        $stateData = json_decode($stateDataJson ?? '{}', true);

        $messageId = $this->fileHandler->getMessageId($this->chatId);

        switch ($state) {
            case 'entering_shipping_name':
                $name = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($name)) {
                    $this->Alert("⚠️ نام و نام خانوادگی نمی‌تواند خالی باشد.");
                    return;
                }
                $stateData['name'] = $name;

                $this->fileHandler->addData($this->chatId, [
                    'state' => 'entering_shipping_phone',
                    'state_data' => json_encode($stateData)
                ]);
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ نام ثبت شد: " . htmlspecialchars($name) . "\n\nحالا لطفاً شماره تلفن همراه خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_phone':
                $phone = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($phone) || strlen($phone) < 10) {
                    $this->Alert("⚠️ لطفاً یک شماره تلفن معتبر وارد کنید.");
                    return;
                }
                $stateData['phone'] = $phone;

                $this->fileHandler->addData($this->chatId, [
                    'state' => 'entering_shipping_address',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ شماره تلفن ثبت شد: {$phone}\n\nدر نهایت, لطفاً آدرس دقیق پستی خود را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'show_cart']]]]
                ]);
                break;

            case 'entering_shipping_address':
                $address = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($address)) {
                    $this->Alert("⚠️ آدرس نمی‌تواند خالی باشد.");
                    return;
                }

                $shippingData = [
                    'name' => $stateData['name'],
                    'phone' => $stateData['phone'],
                    'address' => $address,
                ];
                $this->db->saveUserAddress($this->chatId, $shippingData);

                $this->fileHandler->saveState($this->chatId, null);

                if ($messageId) $this->deleteMessage($messageId); 
                $this->Alert("✅ اطلاعات شما با موفقیت ذخیره شد.");
                $this->showCart();
                break;
        }
    }


    private function handleProductCreationSteps(): void
    {
        $state = $this->fileHandler->getState($this->chatId) ?? null;
        $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);

        $messageId = $this->fileHandler->getMessageId($this->chatId);

        switch ($state) {
            case 'adding_product_name':
                $productName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($productName)) {
                    $this->Alert("⚠️ نام محصول نمی‌تواند خالی باشد.");
                    return;
                }
                $stateData['name'] = $productName;
                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_product_description',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ نام محصول ثبت شد: " . htmlspecialchars($productName) . "\n\nحالا لطفاً توضیحات محصول را وارد کنید:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_name'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_description':
                $stateData['description'] = trim($this->text);
                $this->deleteMessage($this->messageId);
                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_product_count',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ توضیحات ثبت شد.\n\nحالا لطفاً تعداد موجودی محصول را وارد کنید (فقط عدد انگلیسی):",
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_description'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_count':
                $count = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($count) || $count < 0) {
                    $this->Alert("⚠️ لطفاً یک تعداد معتبر وارد کنید.");
                    return;
                }
                $stateData['stock'] = (int) $count;
                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_product_price',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ تعداد ثبت شد: {$count} عدد\n\nحالا لطفاً قیمت محصول را وارد کنید (به تومان):",
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_count'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_price':
                $price = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (!is_numeric($price) || $price < 0) {
                    $this->Alert("⚠️ لطفاً یک قیمت معتبر وارد کنید.");
                    return;
                }
                $stateData['price'] = (int) $price;
                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_product_photo',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ قیمت ثبت شد: " . number_format($price) . " تومان\n\nحالا لطفاً عکس محصول را ارسال کنید :",
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '↪️ مرحله قبل', 'callback_data' => 'product_creation_back_to_price'],
                                ['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']
                            ]
                        ]
                    ]
                ]);
                break;

            case 'adding_product_photo':
                $this->deleteMessage($this->messageId);

                if (isset($this->message['photo'])) {
                    $stateData['image_file_id'] = end($this->message['photo'])['file_id'];
                } elseif ($this->text !== '/skip') {
                    $this->Alert("⚠️ لطفاً یک عکس ارسال کنید.");
                    return;
                } else {
                    $stateData['image_file_id'] = null;
                }
                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_product_confirmation',
                    'state_data' => json_encode($stateData)
                ]);
                $this->deleteMessage($messageId);
                $this->showConfirmationPreview();
                break;
        }
    }


    private function showConfirmationPreview(): void
    {

        $stateDataJson = $this->fileHandler->getStateData($this->chatId);
        $stateData = json_decode($stateDataJson ?? '{}', true);

        $previewText = " لطفاً اطلاعات زیر را بررسی و تایید کنید:\n\n";
        $previewText .= "📦 نام محصول: " . htmlspecialchars($stateData['name'] ?? 'ثبت نشده') . "\n";
        $previewText .= "📝 توضیحات: " . htmlspecialchars($stateData['description'] ?? 'ثبت نشده') . "\n";
        $previewText .= "🔢 موجودی: " . ($stateData['stock'] ?? '۰') . " عدد\n";
        $previewText .= "💰 قیمت: " . number_format($stateData['price'] ?? 0) . " تومان\n\n";
        $previewText .= "در صورت صحت اطلاعات، دکمه \"تایید و ذخیره\" را بزنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و ذخیره', 'callback_data' => 'product_confirm_save'],
                    ['text' => '❌ لغو عملیات', 'callback_data' => 'product_confirm_cancel']
                ]
            ]
        ];

        $res = null;
        if (!empty($stateData['image_file_id'])) {
            $res = $this->sendRequest('sendPhoto', [
                'chat_id' => $this->chatId,
                'photo' => $stateData['image_file_id'],
                'caption' => $previewText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $res = $this->sendRequest('sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $previewText,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }

        if (isset($res['result']['message_id'])) {
            $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id']);
        }
    }


    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            Logger::log(
                'error',
                "Telegram API Request Failed",
                "Method: {$method}",
                [
                    'request_data' => $data,
                    'response'     => $response,
                    'http_code'    => $httpCode,
                    'curl_error'   => $curlError,
                ],
                true
            );
            return false;
        }

        return json_decode($response, true);
    }



    private function createNewProduct(string $stateDataJson): int|false
    {
        $stateData = json_decode($stateDataJson, true);
        if (empty($stateData)) {
            return false;
        }
        return $this->db->createNewProduct($stateData);
    }
    public function Alert($message, $alert = true): void
    {
        if ($this->callbackQueryId) {
            $data = [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $message,
                'show_alert' => $alert
            ];
            $this->sendRequest("answerCallbackQuery", $data);
        } else {
            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $message,
            ]);
            $this->deleteMessage($res['result']['message_id'] ?? null, 3);
        }
    }
    private function generateProductCardText(array $product): string
    {

        $rtl_on = "\u{202B}";
        $rtl_off = "\u{202C}";

        $name = $product['name'];
        $desc = $product['description'] ?? 'توضیحی ثبت نشده';
        $price = number_format($product['price']);

        $text = $rtl_on;
        $text .= "🛍️ <b>{$name}</b>\n\n";
        $text .= "{$desc}\n\n";

        if (isset($product['quantity'])) {

            $quantity = (int) $product['quantity'];
            $text .= "🔢 <b>تعداد در سبد:</b> {$quantity} عدد\n";
        } else {
            $count = (int) ($product['stock'] ?? 0);
            $text .= "📦 <b>موجودی:</b> {$count} عدد\n";
        }
        $text .= "💵 <b>قیمت:</b> {$price} تومان";
        $text .= $rtl_off;

        return $text;
    }

    public function promptUserForCategorySelection($messageId = null): void
    {
        $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($MessageIds)) {
            $this->deleteMessages($MessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        $allCategories = $this->db->getAllCategories();

        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای برای نمایش محصولات وجود ندارد!");
            $this->showProductManagementMenu($messageId);
            return;
        }

        $categoryButtons = [];
        $row = [];
        foreach ($allCategories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'list_products_cat_' . $category['id'] . '_page_1'];
            if (count($row) >= 2) {
                $categoryButtons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $categoryButtons[] = $row;
        }

        $categoryButtons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];
        $text = "لطفاً برای مشاهده محصولات، یک دسته‌بندی را انتخاب کنید:";

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    public function showProductManagementMenu($messageId = null): void
    {
        $text = "بخش مدیریت محصولات. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن محصول جدید', 'callback_data' => 'admin_add_product']],
                [['text' => '📜 لیست محصولات', 'callback_data' => 'admin_product_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => $keyboard
            ]);
        }
    }
    public function MainMenu($messageId = null): void
    {
        $user = $this->db->getUserInfo($this->chatId);

        $settings = $this->db->getAllSettings();
        $channelId = $settings['channel_id'] ?? null;

        $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($MessageIds)) {
            $this->deleteMessages($MessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        $hour = (int) jdf::jdate('H', '', '', '', 'en');
        $defaultWelcome = match (true) {
            $hour < 12 => "☀️ صبح بخیر! آماده‌ای برای دیدن پیشنهادهای خاص امروز؟",
            $hour < 18 => "🌼 عصر بخیر! یه چیزی خاص برای امروز داریم 😉",
            default => "🌙 شب بخیر! شاید وقتشه یه هدیه‌ خاص برای خودت یا عزیزات پیدا کنی...",
        };

        if (!empty($settings['main_menu_text'])) {
            $menuText = $settings['main_menu_text'] . "\n\n" . "<blockquote>{$defaultWelcome}</blockquote>";
        } else {
            $menuText = $defaultWelcome;
        }

        $allCategories = $this->db->getAllCategories();
        $categoryButtons = [];

        if (!empty($settings['daily_offer'])) {
            $categoryButtons[] = [['text' => '🔥 پیشنهاد ویژه امروز', 'callback_data' => 'daily_offer']];
        }

        if (!empty($allCategories)) {
            $activeCategories = [];
            foreach ($allCategories as $category) {
                if (isset($category['parent_id']) && $category['parent_id'] == 0 && !empty($category['is_active'])) {
                    $activeCategories[] = $category;
                }
            }
            usort($activeCategories, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

            $row = [];
            foreach ($activeCategories as $category) {
                $row[] = ['text' => $category['name'], 'callback_data' => 'category_' . $category['id']];
                if (count($row) == 2) {
                    $categoryButtons[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $categoryButtons[] = $row;
            }
        }

        $staticButtons = [
            [['text' => '❤️ علاقه‌مندی‌ها', 'callback_data' => 'show_favorites'], ['text' => '🛒 سبد خرید', 'callback_data' => 'show_cart']],
            [['text' => '📜 قوانین فروشگاه', 'callback_data' => 'show_store_rules'], ['text' => '🛍️ سفارشات من', 'callback_data' => 'my_orders']],
            [['text' => '🔍 جستجوی محصول', 'callback_data' => 'activate_inline_search']],
            [['text' => 'ℹ️ درباره ما', 'callback_data' => 'show_about_us'], ['text' => '📞 پشتیبانی', 'callback_data' => 'contact_support']]
        ];

        $categoryButtons = array_merge($categoryButtons, $staticButtons);

        if (!empty($channelId)) {
            $channelUsername = str_replace('@', '', $channelId);
            $categoryButtons[] = [['text' => '📢 عضویت در کانال فروشگاه', 'url' => "https://t.me/{$channelUsername}"]];
        }

        if ($user && !empty($user['is_admin'])) {
            $categoryButtons[] = [['text' => '⚙️ مدیریت فروشگاه', 'callback_data' => 'admin_panel_entry']];
        }

        $keyboard = ['inline_keyboard' => $categoryButtons];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $menuText,
            'reply_markup' => json_encode($keyboard),
            'parse_mode' => 'HTML',
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    public function showMyOrdersList($page = 1, $messageId = null): void
    {
        $allInvoices = $this->db->getInvoicesByUserId($this->chatId);

        if (empty($allInvoices)) {
            $this->Alert("شما تاکنون هیچ سفارشی ثبت نکرده‌اید.");
            return;
        }


        $perPage = 5;
        $totalPages = ceil(count($allInvoices) / $perPage);
        $offset = ($page - 1) * $perPage;
        $invoicesOnPage = array_slice($allInvoices, $offset, $perPage);
        $newMessageIds = [];
        $text = "لیست سفارشات شما (صفحه {$page} از {$totalPages}):";

        if ($messageId) {
            $res = $this->sendRequest("editMessageText", ['chat_id' => $this->chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => ['inline_keyboard' => []]]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        } else {
            $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
            if (!empty($MessageIds)) {
                $this->deleteMessages($MessageIds);
                $this->fileHandler->clearMessageIds($this->chatId);
            }
        }

        foreach ($invoicesOnPage as $invoice) {
            $invoiceText = $this->generateInvoiceCardText($invoice);
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔍 نمایش جزئیات کامل', 'callback_data' => 'show_order_details_' . $invoice['id']]]
                ]
            ];

            if ($invoice['status'] === 'pending') {
                $keyboard['inline_keyboard'][] = [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $invoice['id']]];
            }

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $invoiceText,
                "parse_mode" => "HTML",
                "reply_markup" => $keyboard
            ]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "my_orders_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "my_orders_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $res = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => "--- صفحه {$page} از {$totalPages} ---",
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($res['result']['message_id'])) {
            $newMessageIds[] = $res['result']['message_id'];
        }


        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }
    private function generateInvoiceCardText(array $invoice): string
    {
        $invoiceId = $invoice['id'];
        $date = jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));
        $totalAmount = number_format($invoice['total_amount']);
        $status = $this->translateInvoiceStatus($invoice['status']);

        $text = "📄 <b>سفارش شماره:</b> <code>{$invoiceId}</code>\n";
        $text .= "📅 <b>تاریخ ثبت:</b> {$date}\n";
        $text .= "💰 <b>مبلغ کل:</b> {$totalAmount} تومان\n";
        $text .= "📊 <b>وضعیت:</b> {$status}";

        return $text;
    }
    private function translateInvoiceStatus(string $status): string
    {
        return match ($status) {
            'pending' => '⏳ در انتظار پرداخت',
            'paid' => '✅ تایید شده',
            'canceled' => '❌ لغو شده',
            'failed' => '❗️پرداخت ناموفق',
            default => 'نامشخص',
        };
    }

    public function promptForProductCategory($messageId = null): void
    {
        $allCategories = $this->db->getAllCategories();

        if (empty($allCategories)) {
            $this->Alert("ابتدا باید حداقل یک دسته‌بندی ایجاد کنید!");
            $this->showProductManagementMenu($messageId);
            return;
        }

        $categoryButtons = [];
        foreach ($allCategories as $category) {
            $categoryButtons[] = [['text' => $category['name'], 'callback_data' => 'product_cat_select_' . $category['id']]];
        }
        $categoryButtons[] = [['text' => '❌ انصراف و بازگشت', 'callback_data' => 'admin_manage_products']];

        $keyboard = ['inline_keyboard' => $categoryButtons];
        $text = "لطفاً دسته‌بندی محصول جدید را انتخاب کنید:";

        $this->fileHandler->saveState($this->chatId, 'adding_product_category');

        $this->fileHandler->addData($this->chatId, ['state_data' => json_encode([])]);

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }


    public function showSingleOrderDetails(string $invoiceId, int $messageId): void
    {
        $invoice = $this->db->getInvoiceById((int)$invoiceId);

        $isAdmin = $this->db->isAdmin($this->chatId);
        if (!$invoice || (!$isAdmin && $invoice['user_id'] != $this->chatId)) {
            $this->Alert("خطا: این سفارش یافت نشد یا شما به آن دسترسی ندارید.");
            return;
        }

        $invoiceItems = $this->db->getInvoiceItems((int)$invoiceId);

        $settings = $this->db->getAllSettings();
        $storeName = $settings['store_name'] ?? 'فروشگاه ما';

        $userInfo = json_decode($invoice['user_info'], true) ?? [];
        $date = jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));
        $status = $this->translateInvoiceStatus($invoice['status']);

        $text = "🧾 <b>{$storeName}</b>\n\n";
        $text .= "🆔 <b>شماره فاکتور:</b> <code>{$invoiceId}</code>\n";
        $text .= "📆 <b>تاریخ ثبت:</b> {$date}\n";
        $text .= "📊 <b>وضعیت فعلی:</b> {$status}\n\n";

        if (!empty($userInfo)) {
            $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
            $text .= "👤 <b>نام:</b> " . htmlspecialchars($userInfo['name'] ?? '') . "\n";
            $text .= "📞 <b>تلفن:</b> <code>" . htmlspecialchars($userInfo['phone'] ?? '') . "</code>\n";
            $text .= "📍 <b>آدرس:</b> " . htmlspecialchars($userInfo['address'] ?? '') . "\n\n";
        }

        $text .= "📋 <b>لیست اقلام خریداری شده:</b>\n";
        foreach ($invoiceItems as $item) {
            $text .= "🔸 <b>" . htmlspecialchars($item['name']) . "</b>\n";
            $text .= "➤ تعداد: {$item['quantity']} | قیمت واحد: " . number_format($item['price']) . " تومان\n";
        }
        $text .= "\n💰 <b>مبلغ نهایی پرداخت شده:</b> <b>" . number_format($invoice['total_amount']) . " تومان</b>";

        $backButtonData = $isAdmin ? "admin_list_invoices_{$invoice['status']}_page_1" : 'my_orders';
        $backButtonText = $isAdmin ? '⬅️ بازگشت به لیست فاکتورها' : '⬅️ بازگشت به سفارشات من';

        $keyboard = [['text' => $backButtonText, 'callback_data' => $backButtonData]];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            "message_id" => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => [$keyboard]])
        ]);
    }
    public function showStoreRules($messageId = null): void
    {
        $settings = $this->db->getAllSettings();
        $rulesText = $settings['store_rules'] ?? 'متاسفانه هنوز قانونی برای فروشگاه تنظیم نشده است.';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => "<b>📜 قوانین و مقررات فروشگاه</b>\n\n" . $rulesText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    public function showSupportInfo($messageId = null): void
    {
        $settings = $this->db->getAllSettings();
        $supportId = $settings['support_id'] ?? null;

        if (empty($supportId)) {
            $this->Alert("اطلاعات پشتیبانی در حال حاضر تنظیم نشده است.");
            return;
        }

        $username = str_replace('@', '', $supportId);
        $supportUrl = "https://t.me/{$username}";

        $text = "📞 برای ارتباط با واحد پشتیبانی می‌توانید مستقیماً از طریق آیدی زیر اقدام کنید .\n\n";
        $text .= "👤 آیدی پشتیبانی: {$supportId}";

        $keyboard = [
            'inline_keyboard' => [
                // [['text' => '🚀 شروع گفتگو با پشتیبانی', 'url' => $supportUrl]],
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    public function showOrderSummaryCard(string $invoiceId, int $messageId): void
    {
        $invoice = $this->db->getInvoiceById((int)$invoiceId);

        if (!$invoice || $invoice['user_id'] != $this->chatId) {
            $this->Alert("خطا: سفارش یافت نشد.");
            return;
        }

        $invoiceText = $this->generateInvoiceCardText($invoice);
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔍 نمایش جزئیات کامل', 'callback_data' => 'show_order_details_' . $invoice['id']]]
            ]
        ];

        if ($invoice['status'] === 'pending_payment') {
            $keyboard['inline_keyboard'][] = [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $invoice['id']]];
        }


        $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => $invoiceText,
            "parse_mode" => "HTML",
            "reply_markup" => $keyboard
        ]);
    }
    public function showFavoritesList($page = 1, $messageId = null): void
    {
        $favoriteProducts = $this->db->getUserFavorites($this->chatId);
        $cartItems = $this->db->getUserCart($this->chatId);
        // تبدیل سبد خرید به یک آرایه ساده‌تر برای جستجوی سریع
        $cartProductIds = array_column($cartItems, 'quantity', 'id');

        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        if (empty($favoriteProducts)) {
            $this->Alert("❤️ لیست علاقه‌مندی‌های شما خالی است.");
            $this->MainMenu($messageId); 
            return;
        }

        $perPage = 5;
        $totalPages = ceil(count($favoriteProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($favoriteProducts, $offset, $perPage);
        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $keyboardRows[] = [['text' => '❤️ حذف از علاقه‌مندی', 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cartProductIds[$productId])) {
                $quantity = $cartProductIds[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];
            $res = null;
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }


        $navText = "--- علاقه‌مندی‌ها (صفحه {$page} از {$totalPages}) ---";
        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "fav_list_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "fav_list_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }

    public function showCart($messageId = null): void
    {
        // ۱. خواندن سبد خرید با متد جدید
        $cartItems = $this->db->getUserCart($this->chatId);

        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        if (empty($cartItems)) {
            $this->Alert("🛒 سبد خرید شما خالی است.");
            $this->MainMenu($messageId);
            return;
        }

        // ۲. خواندن اطلاعات ارسال با متد جدید
        $shippingInfo = $this->db->getUserShippingInfo($this->chatId);
        $shippingInfoComplete = !empty($shippingInfo);

        $settings = $this->db->getAllSettings();
        $storeName = $settings['store_name'] ?? 'فروشگاه من';
        $deliveryCost = (int)($settings['delivery_price'] ?? 0);
        $taxPercent = (int)($settings['tax_percent'] ?? 0);
        $discountFixed = (int)($settings['discount_fixed'] ?? 0);

        $date = jdf::jdate('Y/m/d');

        $text = "🧾 <b>فاکتور خرید از {$storeName}</b>\n";
        $text .= "📆 تاریخ: {$date}\n\n";

        if ($shippingInfoComplete) {
            $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
            $text .= "👤 نام: " . htmlspecialchars($shippingInfo['name']) . "\n";
            $text .= "📞 تلفن: " . htmlspecialchars($shippingInfo['phone']) . "\n";
            $text .= "📍 آدرس: " . htmlspecialchars($shippingInfo['address']) . "\n\n";
        }

        $text .= "<b>📋 لیست اقلام:</b>\n";
        $totalPrice = 0;

        // ۳. حلقه روی آیتم‌های خوانده شده از دیتابیس
        foreach ($cartItems as $item) {
            $unitPrice = $item['price'];
            $quantity = $item['quantity'];
            $itemPrice = $unitPrice * $quantity;
            $totalPrice += $itemPrice;

            $text .= "🔸 " . htmlspecialchars($item['name']) . "\n";
            $text .= "  ➤ تعداد: {$quantity} | قیمت واحد: " . number_format($unitPrice) . " تومان\n";
            $text .= "  💵 مجموع: " . number_format($itemPrice) . " تومان\n\n";
        }

        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost - $discountFixed;

        $text .= "📦 هزینه ارسال: " . number_format($deliveryCost) . " تومان\n";
        if ($discountFixed > 0) { // نمایش تخفیف فقط در صورت وجود
            $text .= "💸 تخفیف: " . number_format($discountFixed) . " تومان\n";
        }
        $text .= "📊 مالیات ({$taxPercent}%): " . number_format($taxAmount) . " تومان\n";
        $text .= "💰 <b>مبلغ نهایی قابل پرداخت:</b> <b>" . number_format($grandTotal) . "</b> تومان";

        // ۴. دکمه‌ها (منطق بدون تغییر)
        $keyboardRows = [];
        if ($shippingInfoComplete) {
            $keyboardRows[] = [['text' => '💳 پرداخت نهایی', 'callback_data' => 'checkout']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
            $keyboardRows[] = [['text' => '📝 ویرایش اطلاعات ارسال', 'callback_data' => 'edit_shipping_info']];
        } else {
            $keyboardRows[] = [['text' => '📝 تکمیل اطلاعات ارسال', 'callback_data' => 'complete_shipping_info']];
            $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
        }
        $keyboardRows[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
        $keyboard = ['inline_keyboard' => $keyboardRows];

        // ... بقیه کد برای ارسال پیام (بدون تغییر)
        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                "message_id" => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }
    public function showAdminMainMenu($messageId = null): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories']],
                [['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']],
                [['text' => '🧾 مدیریت فاکتورها', 'callback_data' => 'admin_manage_invoices']],
                [['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']],
                [['text' => '📊 آمار و گزارشات', 'callback_data' => 'admin_reports']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']]
            ]
        ];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => json_encode($keyboard)
            ]);
            return;
        } else {
            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "پنل مدیریت ربات:",
                "reply_markup" => $keyboard
            ]);
        }
    }

    public function showProductListByCategory($categoryId, $page = 1, $messageId = null): void
    {
        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        $allProducts = $this->db->getProductsByCategoryId($categoryId);

        if (empty($allProducts)) {
            $this->Alert("هیچ محصولی در این دسته‌بندی یافت نشد.");
            return;
        }
        $newMessageIds = [];

        if ($messageId) {
            $res =  $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "⏳ بارگذاری محصولات  ...",
                "reply_markup" => ['inline_keyboard' => []]
            ]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $perPage = 5;
        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);


        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                    ]
                ]
            ];

            $res = null;
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- صفحه {$page} از {$totalPages} ---";
        $navButtons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'admin_product_list']];

        $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        
        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }
    public function initiateCardPayment($messageId): void
    {
        $cartItems = $this->db->getUserCart($this->chatId);

        if (empty($cartItems)) {
            $this->Alert("سبد خرید شما خالی است!");
            return;
        }
        $shippingInfo = $this->db->getUserShippingInfo($this->chatId);
        if (!$shippingInfo) {
            $this->Alert("لطفاً ابتدا اطلاعات ارسال خود را تکمیل کنید.");
            $this->showCart($messageId);
            return;
        }

        $settings = $this->db->getAllSettings();
        $cardNumber = $settings['card_number'] ?? null;
        $cardHolderName = $settings['card_holder_name'] ?? null;

        if (empty($cardNumber) || empty($cardHolderName)) {
            $this->Alert("متاسفانه اطلاعات کارت فروشگاه تنظیم نشده است. لطفاً به مدیریت اطلاع دهید.");
            return;
        }

        $deliveryCost = (float)($settings['delivery_price'] ?? 0);
        $taxPercent = (float)($settings['tax_percent'] ?? 0);
        $totalPrice = 0;

        foreach ($cartItems as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        $taxAmount = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost;

        $newInvoiceId = $this->db->createNewInvoice($this->chatId, $cartItems, $grandTotal, $shippingInfo);

        if (!$newInvoiceId) {
            $this->Alert("خطایی در ثبت سفارش رخ داد. لطفاً دوباره تلاش کنید.");
            return;
        }

        $this->db->clearUserCart($this->chatId);

        $text = "🧾 <b>رسید ثبت سفارش</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━\n";
        $text .= "🛒 وضعیت سفارش: <b>ثبت شده</b>\n";
        $text .= "💰 مبلغ قابل پرداخت: <b>" . number_format($grandTotal) . " تومان</b>\n";
        $text .= "🕒 زمان ثبت: " . jdf::jdate("Y/m/d - H:i") . "\n";
        $text .= "━━━━━━━━━━━━━━━━━\n\n";
        $text .= "📌 لطفاً مبلغ فوق را به کارت زیر واریز نمایید و سپس از طریق دکمه‌ی زیر، تصویر رسید پرداخت را برای ما ارسال کنید:\n\n";
        $text .= "💳 <b>شماره کارت:</b>\n<code>{$cardNumber}</code>\n";
        $text .= "👤 <b>نام صاحب حساب:</b>\n<b>{$cardHolderName}</b>\n\n";
        $text .= "📦 سفارش شما پس از تأیید پرداخت پردازش و ارسال خواهد شد.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📸 ارسال رسید پرداخت', 'callback_data' => 'upload_receipt_' . $newInvoiceId]],
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function showProductEditMenu(int $productId, int $messageId, int $categoryId, int $page): void
    {
        $product = $this->db->getProductById($productId);
        if (!$product) {
            $this->Alert("خطا: محصول یافت نشد!");
            $this->deleteMessage($messageId);
            return;
        }

        $text = "شما در حال ویرایش محصول \"{$product['name']}\"هستید.\n\n";
        $text .= "کدام بخش را می‌خواهید ویرایش کنید؟";

        $keyboard = [
            'inline_keyboard' => [

                [
                    ['text' => '✏️ ویرایش نام', 'callback_data' => "edit_field_name_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش توضیحات', 'callback_data' => "edit_field_description_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '✏️ ویرایش تعداد', 'callback_data' => "edit_field_stock_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش قیمت', 'callback_data' => "edit_field_price_{$productId}_{$categoryId}_{$page}"]
                ],
                [['text' => '🖼️ ویرایش عکس', 'callback_data' => "edit_field_imagefileid_{$productId}_{$categoryId}_{$page}"]],
                [['text' => '✅ تایید و ذخیره', 'callback_data' => "confirm_product_edit_{$productId}_cat_{$categoryId}_page_{$page}"]],

            ]
        ];

        $method = !empty($product['image_file_id']) ? "editMessageCaption" : "editMessageText";
        $textOrCaptionKey = !empty($product['image_file_id']) ? "caption" : "text";

        $this->sendRequest($method, [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            $textOrCaptionKey => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard
        ]);
    }

    public function showCartInEditMode($messageId): void
    {
        if ($messageId) {
            $this->deleteMessage($messageId);
        }
        // ۱. خواندن سبد خرید با متد جدید
        $cartItems = $this->db->getUserCart($this->chatId);

        if (empty($cartItems)) {
            $this->Alert("سبد خرید شما خالی است.");
            $this->MainMenu();
            return;
        }

        $newMessageIds = [];

        // ۲. حلقه روی آیتم‌های خوانده شده از دیتابیس
        foreach ($cartItems as $item) {
            $productId = $item['id'];
            $quantity = $item['quantity'];

            // تابع generateProductCardText به 'quantity' نیاز دارد، پس آن را اضافه می‌کنیم
            $item['quantity'] = $quantity;
            $itemText = $this->generateProductCardText($item);

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                        ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                        ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                    ],
                    [
                        ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                    ]
                ]
            ];

            $res = null;
            if (!empty($item['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $item['image_file_id'],
                    "caption" => $itemText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $keyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $itemText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $keyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $endEditText = "تغییرات مورد نظر را اعمال کرده و در پایان، دکمه زیر را بزنید:";
        $endEditKeyboard = [['text' => '✅ مشاهده فاکتور نهایی', 'callback_data' => 'show_cart']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $endEditText,
            'reply_markup' => ['inline_keyboard' => [$endEditKeyboard]]
        ]);

        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }
    public function activateInlineSearch($messageId = null): void
    {
        $text = "🔍 برای جستجوی محصولات در این چت، روی دکمه زیر کلیک کرده و سپس عبارت مورد نظر خود را تایپ کنید:";
        $buttonText = "شروع جستجو در این چت 🔍";

        if ($messageId == null) {
            $prefilledSearchText = "عبارت جستجو خود را وارد کنید";

            $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => json_encode([
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => $prefilledSearchText
                            ]
                        ]
                    ]
                ])
            ]);
        } else {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                'message_id' => $messageId,
                "text" => $text,
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            [
                                "text" => $buttonText,
                                "switch_inline_query_current_chat" => ""
                            ]
                        ],
                        [
                            [
                                "text" => "🔙 بازگشت",
                                "callback_data" => "main_menu"
                            ]
                        ]
                    ]
                ]
            ]);
        }
    }

    public function showInvoiceManagementMenu($messageId = null): void
    {
        $text = "🧾 بخش مدیریت فاکتورها.\n\nلطفاً وضعیت فاکتورهایی که می‌خواهید مشاهده کنید را انتخاب نمایید:";
        $keyboard = [
        'inline_keyboard' => [
            [['text' => '⏳ در انتظار پرداخت', 'callback_data' => 'admin_list_invoices_pending_page_1']], 
            [['text' => '✅ تایید شده', 'callback_data' => 'admin_list_invoices_paid_page_1'], ['text' => '❌ لغو شده', 'callback_data' => 'admin_list_invoices_canceled_page_1']], 
            [['text' => '📜 نمایش همه فاکتورها', 'callback_data' => 'admin_list_invoices_all_page_1']],
            [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
        ]

        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
    }
    public function showAdminInvoiceDetails(string $invoiceId, string $fromStatus, int $fromPage, int $messageId): void
    {
        $invoice = $this->db->getInvoiceById((int)$invoiceId);

        if (!$invoice) {
            $this->Alert("خطا: فاکتور یافت نشد.");
            return;
        }

        $text = $this->notifyAdminOfNewReceipt($invoiceId, null, false);

        $keyboard = [];
        if ($invoice['status'] === 'pending') {
            $keyboard[] = [
                ['text' => '✅ تایید فاکتور', 'callback_data' => 'admin_approve_' . $invoiceId],
                ['text' => '❌ رد فاکتور', 'callback_data' => 'admin_reject_' . $invoiceId]
            ];
        }
        $keyboard[] = [['text' => '⬅️ بازگشت به لیست', 'callback_data' => "admin_list_invoices:{$fromStatus}:page:{$fromPage}"]];

        $this->deleteMessage($messageId);

        $requestData = [
            'chat_id' => $this->chatId,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ];

        if (!empty($invoice['receipt_file_id'])) {
            $requestData['photo'] = $invoice['receipt_file_id'];
            $requestData['caption'] = $text;
            $this->sendRequest("sendPhoto", $requestData);
        } else {
            $requestData['text'] = $text;
            $this->sendRequest("sendMessage", $requestData);
        }
    }

    public function notifyAdminOfNewReceipt(string $invoiceId, ?string $receiptFileId, bool $send = true): ?string
    {
        $settings = $this->db->getAllSettings();
        $adminId = $settings['support_id'] ?? null;
        $invoice = $this->db->getInvoiceById((int)$invoiceId);

        if (!$invoice) {
            Logger::log('error', 'notifyAdminOfNewReceipt failed', 'Invoice not found.', ['invoice_id' => $invoiceId]);
            return null;
        }

        $userInfo = json_decode($invoice['user_info'], true) ?? [];
        $products = json_decode($invoice['products'], true) ?? [];
        $totalAmount = number_format($invoice['total_amount']);
        $createdAt = jdf::jdate('Y/m/d - H:i', strtotime($invoice['created_at']));

        $text = "🔔 رسید پرداخت جدید دریافت شد 🔔\n\n";
        $text .= "📄 شماره فاکتور: `{$invoiceId}`\n";
        $text .= "📅 تاریخ ثبت: {$createdAt}\n\n";
        $text .= "👤 مشخصات خریدار:\n";
        $text .= "- نام: " . htmlspecialchars($userInfo['name'] ?? '') . "\n";
        $text .= "- تلفن: `" . htmlspecialchars($userInfo['phone'] ?? '') . "`\n";
        $text .= "- آدرس: " . htmlspecialchars($userInfo['address'] ?? '') . "\n\n";
        $text .= "🛍 محصولات خریداری شده:\n";
        foreach ($products as $product) {
            $productPrice = number_format($product['price']);
            $text .= "- " . htmlspecialchars($product['name']) . " (تعداد: {$product['quantity']}, قیمت واحد: {$productPrice} تومان)\n";
        }
        $text .= "\n";
        $text .= "💰 مبلغ کل پرداخت شده: {$totalAmount} تومان\n\n";
        $text .= "لطفاً رسید را بررسی و وضعیت فاکتور را مشخص نمایید.";

        if (!$send) {
            return $text;
        }

        if (empty($adminId)) {
            Logger::log('error', 'notifyAdminOfNewReceipt failed', 'Admin ID is not set in settings.');
            return null;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید فاکتور', 'callback_data' => 'admin_approve_' . $invoiceId],
                    ['text' => '❌ رد فاکتور', 'callback_data' => 'admin_reject_' . $invoiceId]
                ]
            ]
        ];

        $this->sendRequest("sendPhoto", [
            'chat_id' => $adminId,
            'photo' => $receiptFileId,
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);

        return null;
    }

    public function showCategoryList($messageId = null): void
    {
        $allCategories = $this->db->getAllCategories();

        if (empty($allCategories)) {
            $this->Alert("هیچ دسته‌بندی‌ای وجود ندارد.");
            $this->showCategoryManagementMenu($messageId);
            return;
        }

        $newMessageIds = [];

        if ($messageId) {
            $res = $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "⏳ لطفاً صبر کنید...",
                "reply_markup" => ['inline_keyboard' => []]
            ]);
            $newMessageIds[] = $res['result']['message_id'] ?? null;
        } else {
            $this->Alert("در حال ارسال لیست دسته‌بندی‌ها...", false);
        }
        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
        }


        foreach ($allCategories as $category) {
            $categoryId = $category['id'];
            $categoryName = htmlspecialchars($category['name']);

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_category_' . $categoryId],
                        ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
                    ]
                ]
            ];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => "دسته: <b>{$categoryName}</b>",
                "parse_mode" => "HTML",
                "reply_markup" => $keyboard
            ]);

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "--- پایان لیست ---",
            "reply_markup" => [
                'inline_keyboard' => [
                    [['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_categories']]
                ]
            ]
        ]);

        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }

    public function showInvoiceListByStatus(string $status, int $page = 1, $messageId = null): void
    {
        $perPage = 5;
        $result = $this->db->getInvoicesByStatus($status, $page, $perPage);
        $invoicesOnPage = $result['invoices'];
        $totalInvoices = $result['total'];

        $statusText = $this->translateInvoiceStatus($status);

        if ($totalInvoices === 0) {
            $this->Alert("هیچ فاکتوری با وضعیت '{$statusText}' یافت نشد.");
            $this->showInvoiceManagementMenu($messageId);
            return;
        }

        $totalPages = ceil($totalInvoices / $perPage);
        $text = "لیست فاکتورهای <b>{$statusText}</b> (صفحه {$page} از {$totalPages}):";

        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
        }

        $res = $this->sendRequest("sendMessage", ['chat_id' => $this->chatId, 'text' => $text, 'parse_mode' => 'HTML']);
        $newMessageIds = [$res['result']['message_id'] ?? null];

        foreach ($invoicesOnPage as $invoice) {
            $userInfo = json_decode($invoice['user_info'], true) ?? [];

            $cardText = "📄 <b>فاکتور:</b> <code>{$invoice['id']}</code>\n";
            $cardText .= "👤 <b>کاربر:</b> " . htmlspecialchars($userInfo['name'] ?? '') . " (<code>{$invoice['user_id']}</code>)\n";
            $cardText .= "💰 <b>مبلغ:</b> " . number_format($invoice['total_amount']) . " تومان\n";
            $cardText .= "📅 <b>تاریخ:</b> " . jdf::jdate('Y/m/d H:i', strtotime($invoice['created_at']));

            $keyboard = [['text' => '👁 مشاهده جزئیات', 'callback_data' => "admin_view_invoice:{$invoice['id']}:{$status}:{$page}"]];

            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $cardText,
                "parse_mode" => "HTML",
                "reply_markup" => ['inline_keyboard' => [$keyboard]]
            ]);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "▶️ قبل", 'callback_data' => "admin_list_invoices_{$status}_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "بعد ◀️", 'callback_data' => "admin_list_invoices_{$status}_page_" . ($page + 1)];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی فاکتورها', 'callback_data' => 'admin_manage_invoices']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => "--- صفحه {$page} ---",
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }

        $this->fileHandler->addData($this->chatId, ['message_ids' => array_filter($newMessageIds)]);
    }
    public function showCategoryManagementMenu($messageId = null): void
    {
        $MessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($MessageIds)) {
            $this->deleteMessages($MessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }
        $text = "بخش مدیریت دسته‌بندی‌ها. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'admin_add_category']],
                [['text' => '📜 لیست دسته‌بندی‌ها', 'callback_data' => 'admin_category_list']],
                [['text' => '⬅️ بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']]
            ]
        ];

        if ($messageId) {
            $res = $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        } else {
            $res = $this->sendRequest("sendMessage", [
                "chat_id" => $this->chatId,
                "text" => $text,
                "reply_markup" => json_encode($keyboard)
            ]);
        }
        if (isset($res['result']['message_id'])) {
            $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
        }
    }
    private function refreshCartItemCard(int $productId, int $messageId): void
    {
        $product = $this->db->getProductById($productId);
        $quantity = $this->db->getCartItemQuantity($this->chatId, $productId);

        if (!$product) {
            $this->deleteMessage($messageId);
            $this->Alert("خطا: محصول یافت نشد.", false);
            return;
        }

        if ($quantity <= 0) {
            $this->deleteMessage($messageId);
            $this->Alert("محصول از سبد شما حذف شد.", false);
            return;
        }

        $product['quantity'] = $quantity;
        $newText = $this->generateProductCardText($product);

        $newKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕', 'callback_data' => "edit_cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$productId}"]
                ],
                [
                    ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$productId}"]
                ]
            ]
        ];


        if (!empty($product['image_file_id'])) {

            $this->sendRequest('editMessageCaption', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'caption' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $newText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        }
    }
    private function refreshProductCard(int $productId, ?int $messageId): void
    {
        $quantityInCart = $this->db->getCartItemQuantity($this->chatId, $productId);
        $isFavorite = $this->db->isProductInFavorites($this->chatId, $productId);

        $keyboardRows = [];
        $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
        $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

        if ($quantityInCart > 0) {
            $keyboardRows[] = [
                ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                ['text' => "{$quantityInCart} عدد", 'callback_data' => 'nope'],
                ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
            ];
        } else {
            $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
        }

        if ($messageId == null) {
            $keyboardRows[] = [['text' => 'منوی اصلی', 'callback_data' => 'main_menu2']];
        }

        $newKeyboard = ['inline_keyboard' => $keyboardRows];

        if ($messageId) {

            $this->sendRequest('editMessageReplyMarkup', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $product = $this->db->getProductById($productId);
            $productText = $this->generateProductCardText($product);
            if (!empty($product['image_file_id'])) {
                $this->sendRequest("sendPhoto", ["chat_id" => $this->chatId, "photo" => $product['image_file_id'], "caption" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            } else {
                $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            }
        }
    }
    public function showAboutUs(): void
    {

        $text = "🤖 *درباره توسعه‌دهنده ربات*\n\n";
        $text .= "این ربات یک *نمونه‌کار حرفه‌ای* در زمینه طراحی و توسعه ربات‌های فروشگاهی در تلگرام است که توسط *امیر سلیمانی* طراحی و برنامه‌نویسی شده است.\n\n";
        $text .= "✨ *ویژگی‌های برجسته ربات:*\n";
        $text .= "🔹 پنل مدیریت کامل از داخل تلگرام (افزودن، ویرایش، حذف محصول)\n";
        $text .= "🗂️ مدیریت هوشمند دسته‌بندی محصولات\n";
        $text .= "🛒 سیستم سبد خرید و لیست علاقه‌مندی‌ها\n";
        $text .= "🔍 جستجوی پیشرفته با سرعت بالا (Inline Mode)\n";
        $text .= "💳 اتصال امن به درگاه پرداخت\n\n";
        $text .= "💼 *آیا برای کسب‌وکار خود به یک ربات تلگرامی نیاز دارید؟*\n";
        $text .= "ما آماده‌ایم تا ایده‌های شما را به یک ربات کاربردی و حرفه‌ای تبدیل کنیم.\n\n";
        $text .= "📞 *راه ارتباط با توسعه‌دهنده:* [@Amir_soleimani_79](https://t.me/Amir_soleimani_79)";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به فروشگاه', 'callback_data' => 'main_menu']]
            ]
        ];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false,
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    public function showBotSettingsMenu($messageId = null): void
    {
        $settings = $this->db->getAllSettings();

        $storeName = $settings['store_name'] ?? 'تعیین نشده ❌';
        $mainMenuText = $settings['main_menu_text'] ?? 'تعیین نشده ❌';

        $deliveryPrice = number_format($settings['delivery_price'] ?? 0) . ' تومان';
        $taxPercent = ($settings['tax_percent'] ?? 0) . '٪';
        $discountFixed = number_format($settings['discount_fixed'] ?? 0) . ' تومان';

        $cardNumber = $settings['card_number'] ?? 'وارد نشده ❌';
        $cardHolderName = $settings['card_holder_name'] ?? 'وارد نشده ❌';
        $supportId = $settings['support_id'] ?? 'وارد نشده ❌';

        $storeRules = !empty($settings['store_rules']) ? $settings['store_rules'] : '❌ تنظیم نشده';
        $channelId = $settings['channel_id'] ?? 'وارد نشده';


        $text = "⚙️ <b>مدیریت تنظیمات ربات فروشگاه</b>\n\n";
        $text .= "🛒 <b>نام فروشگاه: </b> {$storeName}\n";
        $text .= "🧾 <b>متن منوی اصلی:</b>\n {$mainMenuText}\n\n";

        $text .= "🚚 <b>هزینه ارسال: </b> {$deliveryPrice}\n";
        $text .= "📊 <b>مالیات: </b> {$taxPercent}\n";
        $text .= "🎁 <b>تخفیف ثابت: </b>{$discountFixed}\n\n";

        $text .= "💳 <b>شماره کارت: </b> {$cardNumber}\n";
        $text .= "👤 <b>صاحب حساب: </b> {$cardHolderName}\n";
        $text .= "📢 آیدی کانال: <b>{$channelId}</b>\n";
        $text .= "📞 <b>آیدی پشتیبانی: </b> {$supportId}\n";
        $text .= "📜 <b>قوانین فروشگاه: \n</b> {$storeRules}\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ نام فروشگاه', 'callback_data' => 'edit_setting_store_name'],
                    ['text' => '✏️ متن منو', 'callback_data' => 'edit_setting_main_menu_text']
                ],
                [
                    ['text' => '✏️ هزینه ارسال', 'callback_data' => 'edit_setting_delivery_price'],
                    ['text' => '✏️ درصد مالیات', 'callback_data' => 'edit_setting_tax_percent']
                ],
                [
                    ['text' => '✏️ تخفیف ثابت', 'callback_data' => 'edit_setting_discount_fixed']
                ],
                [
                    ['text' => '✏️ شماره کارت', 'callback_data' => 'edit_setting_card_number'],
                    ['text' => '✏️ نام صاحب حساب', 'callback_data' => 'edit_setting_card_holder_name']
                ],
                [
                    ['text' => '✏️ آیدی پشتیبانی', 'callback_data' => 'edit_setting_support_id'],
                    ['text' => '✏️ آیدی کانال', 'callback_data' => 'edit_setting_channel_id']
                ],
                [
                    ['text' => '✏️ قوانین فروشگاه', 'callback_data' => 'edit_setting_store_rules']
                ],
                [
                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel_entry']
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        $res = $messageId
            ? $this->sendRequest("editMessageText", array_merge($data, ['message_id' => $messageId]))
            : $this->sendRequest("sendMessage", $data);

        if (isset($res['result']['message_id'])) {
            $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
        }
    }

    public function showUserProductList($categoryId, $page = 1, $messageId = null): void
    {
        $cartItems = $this->db->getUserCart($this->chatId);
        $favoriteProductIds = array_column($this->db->getUserFavorites($this->chatId), 'id');
        $cartProductIds = array_column($cartItems, 'quantity', 'id');

        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }
        $allProducts = $this->db->getActiveProductsByCategoryId($categoryId);

        if (empty($allProducts)) {
            $this->Alert("متاسفانه محصولی در این دسته‌بندی یافت نشد.");
            return;
        }
        if ($messageId) {
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "⏳ بارگذاری محصولات  ...",
                "reply_markup" => ['inline_keyboard' => []]
            ]);
        }

        $perPage = 5;
        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $newMessageIds = [];

        foreach ($productsOnPage as $product) {
            $productText = $this->generateProductCardText($product);
            $productId = $product['id'];
            $keyboardRows = [];

            $isFavorite = in_array($productId, $favoriteProductIds);
            $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
            $keyboardRows[] = [['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId]];

            if (isset($cartProductIds[$productId])) {
                $quantity = $cartProductIds[$productId];
                $keyboardRows[] = [
                    ['text' => '➕', 'callback_data' => "cart_increase_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "cart_decrease_{$productId}"]
                ];
            } else {
                $keyboardRows[] = [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => 'add_to_cart_' . $productId]];
            }

            $productKeyboard = ['inline_keyboard' => $keyboardRows];

            $res = null;
            if (!empty($product['image_file_id'])) {
                $res = $this->sendRequest("sendPhoto", [
                    "chat_id" => $this->chatId,
                    "photo" => $product['image_file_id'],
                    "caption" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $productText,
                    "parse_mode" => "HTML",
                    "reply_markup" => $productKeyboard
                ]);
            }

            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $navText = "--- صفحه {$page} از {$totalPages} ---";
        $navButtons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navButtons[] = ['text' => "▶️ صفحه قبل", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navButtons[] = ['text' => "صفحه بعد ◀️", 'callback_data' => "user_list_products_cat_{$categoryId}_page_{$nextPage}"];
        }

        $navKeyboard = [];
        if (!empty($navButtons)) {
            $navKeyboard[] = $navButtons;
        }
        $navKeyboard[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $navMessageRes = $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $navText,
            'reply_markup' => ['inline_keyboard' => $navKeyboard]
        ]);
        if (isset($navMessageRes['result']['message_id'])) {
            $newMessageIds[] = $navMessageRes['result']['message_id'];
        }
        $this->fileHandler->addData($this->chatId, ['message_ids' => $newMessageIds]);
    }
    public function showSingleProduct(int $productId): void
    {
        $product = $this->db->getProductById($productId);
        if (!$product) {
            $this->Alert("متاسفانه محصول مورد نظر یافت نشد یا حذف شده است.");
            $this->MainMenu();
            return;
        }
        $this->refreshProductCard($productId, null);
    }
}
