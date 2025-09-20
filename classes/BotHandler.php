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
            } elseif (str_starts_with($callbackData, 'list_discounted_products_page_')) {
                $page = (int) str_replace('list_discounted_products_page_', '', $callbackData);
                $this->showDiscountedProductList($page, $messageId);
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

                // Clear any editing state
                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);

                $productText = $this->generateProductCardText($product);

                // This is the standard admin view keyboard for a product
                $originalKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✏️ ویرایش', 'callback_data' => 'admin_edit_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page],
                            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page]
                        ]
                    ]
                ];

                // Use the smart function to prevent caption/text errors
                $this->editTextOrCaption($this->chatId, $messageId, $productText, $originalKeyboard);

                $this->Alert("✅ ویرایش‌ها ذخیره شد.", false);
                return;
             }
                elseif (strpos($callbackData, 'edit_field_discount_') === 0) {
                sscanf($callbackData, "edit_field_discount_%d_%d_%d", $productId, $categoryId, $page);

                $stateData = json_encode([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'page' => $page,
                    'message_id' => $messageId
                ]);
                $this->fileHandler->addData($this->chatId, [
                    'state' => "editing_product_discount",
                    'state_data' => $stateData
                ]);

                $promptText = "لطفاً قیمت جدید محصول با تخفیف را به تومان وارد کنید.\n\nبرای حذف تخفیف، عدد `0` را ارسال کنید.";
                $this->Alert($promptText, true);
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
            } elseif ($callbackData === 'product_photos_done') {
                $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);


                if (empty($stateData['images'])) {
                    $this->Alert("❌ لطفاً حداقل یک عکس برای محصول خود ارسال کنید.", true);
                    return;
                }

                $this->fileHandler->saveState($this->chatId, 'asking_for_variants');
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ عکس‌ها ثبت شدند. آیا این محصول ویژگی‌های متفاوتی مانند سایز یا رنگ دارد که قیمت یا موجودی متفاوتی داشته باشند؟",
                    'reply_markup' => ['inline_keyboard' => [
                        [['text' => '✅ بله، ویژگی دارد', 'callback_data' => 'add_variant']],
                        [['text' => ' خیر، ویژگی ندارد', 'callback_data' => 'product_variants_done']]
                    ]]
                ]);
                return;
            } elseif ($callbackData === 'add_variant') {
                $this->fileHandler->saveState($this->chatId, 'adding_variant_name');
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "لطفاً نام اولین ویژگی را وارد کنید (مثلاً: سایز L یا رنگ قرمز):",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'product_variants_done']]]]
                ]);
                return;
            } elseif ($callbackData === 'add_another_variant') {
                $this->fileHandler->saveState($this->chatId, 'adding_variant_name');
                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "لطفاً نام ویژگی بعدی را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'product_variants_done']]]]
                ]);
                return;
            } elseif (str_starts_with($callbackData, 'variant_use_price_')) {
                $priceToSet = (float) str_replace('variant_use_price_', '', $callbackData);

                $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
                $variantIndex = $stateData['variant_index'] ?? 0;
                $stateData['variants'][$variantIndex]['price'] = $priceToSet;

                $this->fileHandler->addData($this->chatId, [
                    'state' => 'adding_variant_stock',
                    'state_data' => json_encode($stateData)
                ]);

                // --- ساخت دکمه‌های هوشمند برای موجودی ---
                $stockKeyboard = [];
                $baseStock = $stateData['stock'] ?? 0;
                $stockKeyboard[] = [['text' => '📦 همان موجودی اصلی (' . $baseStock . ')', 'callback_data' => 'variant_use_stock_' . $baseStock]];

                $previousVariants = array_slice($stateData['variants'], 0, $variantIndex);
                $existingStocks = array_unique(array_column($previousVariants, 'stock'));
                foreach ($existingStocks as $stock) {
                    if ($stock != $baseStock) {
                        $stockKeyboard[] = [['text' => '📦 استفاده از موجودی ' . $stock, 'callback_data' => 'variant_use_stock_' . $stock]];
                    }
                }
                $stockKeyboard[] = [['text' => '❌ لغو افزودن ویژگی', 'callback_data' => 'product_variants_done']];
                // --- پایان ساخت دکمه‌ها ---

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ قیمت ثبت شد. حالا موجودی این ویژگی را وارد کنید:",
                    'reply_markup' => ['inline_keyboard' => $stockKeyboard]
                ]);
                return;
            } elseif (str_starts_with($callbackData, 'variant_use_stock_')) {
                $stockToSet = (int) str_replace('variant_use_stock_', '', $callbackData);

                $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
                $variantIndex = $stateData['variant_index'] ?? 0;
                $stateData['variants'][$variantIndex]['stock'] = $stockToSet;
                $stateData['variant_index'] = $variantIndex + 1;

                $this->fileHandler->addData($this->chatId, [
                    'state' => 'asking_another_variant',
                    'state_data' => json_encode($stateData)
                ]);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ ویژگی با موفقیت افزوده شد. آیا می‌خواهید ویژگی دیگری اضافه کنید؟",
                    'reply_markup' => ['inline_keyboard' => [
                        [['text' => '✅ بله، افزودن ویژگی جدید', 'callback_data' => 'add_another_variant']],
                        [['text' => ' خیر، ادامه و پیش‌نمایش', 'callback_data' => 'product_variants_done']]
                    ]]
                ]);
                return;
            } elseif ($callbackData === 'product_variants_done') {
                $this->fileHandler->saveState($this->chatId, 'adding_product_confirmation');
                $this->deleteMessage($messageId);
                $this->showConfirmationPreview();
                return;
            } elseif ($callbackData === 'product_confirm_save') {
                $stateDataJson = $this->fileHandler->getStateData($this->chatId);
                $stateData = json_decode($stateDataJson, true);
                if ($stateData) {
                    $newProductId = $this->db->createNewProduct($stateData);
                    if ($newProductId) {
                        $this->Alert("✅ محصول با موفقیت ذخیره شد! در حال انتشار در کانال...", false);
                        $this->publishProductToChannel($newProductId);
                    } else {
                        $this->Alert("❌ خطایی در ذخیره محصول در دیتابیس رخ داد.", true);
                    }
                }

                $this->fileHandler->addData($this->chatId, ['state' => null, 'state_data' => null]);
                $this->fileHandler->saveState($this->chatId, null);

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
                $this->showCategoryList(null, $messageId);
                return;
            } elseif ($callbackData === 'admin_category_list_root') {
                $this->showCategoryList(null, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_list_subcategories_')) {
                $parentId = (int) str_replace('admin_list_subcategories_', '', $callbackData);
                $this->showCategoryList($parentId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_cat_actions_')) {
                $categoryId = (int) str_replace('admin_cat_actions_', '', $callbackData);
                $this->reconstructCategoryMessage($categoryId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'admin_view_category_products_')) {
                $categoryId = (int) str_replace('admin_view_category_products_', '', $callbackData);
                $this->showCategoryProductsForAdmin($categoryId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'cart_remove_')) {
                $productId = (int) str_replace('cart_remove_', '', $callbackData);
                $isRemoved = $this->db->removeProductFromCart($this->chatId, $productId);

                if ($isRemoved) {
                    $this->deleteMessage($messageId);
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                } else {
                    $this->Alert("خطا: محصول مورد نظر در سبد خرید شما یافت نشد.");
                }
                return;
                // این دو بلوک را در handleCallbackQuery در فایل BotHandler.php جایگزین کنید

            } elseif (str_starts_with($callbackData, 'cart_increase_')) {
                $productId = (int) str_replace('cart_increase_', '', $callbackData);
                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $fromCategoryId = $returnContext['category_id'] ?? null;
                $fromPage = $returnContext['page'] ?? null;

                // ۱. دریافت تعداد فعلی
                $currentQuantity = $this->db->getCartItemQuantity($this->chatId, $productId, null);
                // ۲. فراخوانی متد جدید و یکپارچه برای تنظیم تعداد جدید
                $this->db->setCartItemQuantity($this->chatId, $productId, null, $currentQuantity + 1);

                $this->Alert("✅ یک عدد اضافه شد", false);
                $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_remove_')) {
                $cartItemId = (int) str_replace('edit_cart_remove_', '', $callbackData);
                $isRemoved = $this->db->removeFromCart($cartItemId);

                if ($isRemoved) {
                    $this->Alert("محصول از سبد خرید شما حذف شد.", false);
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا: محصول در سبد خرید یافت نشد.");
                }
                return;
            } elseif (str_starts_with($callbackData, 'cart_decrease_')) {
                $productId = (int) str_replace('cart_decrease_', '', $callbackData);
                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $fromCategoryId = $returnContext['category_id'] ?? null;
                $fromPage = $returnContext['page'] ?? null;

                $currentQuantity = $this->db->getCartItemQuantity($this->chatId, $productId);
                if ($currentQuantity > 0) {
                    $this->db->updateCartQuantityByProduct($this->chatId, $productId, $currentQuantity - 1);
                    $this->Alert("از سبد خرید کم شد", false);
                    $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'category_')) {
                $parts = explode('_', $callbackData);
                $categoryId = (int) end($parts);
                $page = 1;

                $subcategories = $this->db->getSubcategories($categoryId);

                if (empty($subcategories)) {
                    $this->showUserProductList($categoryId, $page, $messageId);
                } else {
                    $this->showSubcategoryMenu($categoryId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'user_list_products_cat_')) {
                sscanf($callbackData, "user_list_products_cat_%d_page_%d", $categoryId, $page);
                if ($categoryId && $page) {
                    if (isset($callbackQuery['message']['photo'])) {
                        $this->deleteMessage($messageId);
                        $this->showUserProductList($categoryId, $page, null);
                    } else {
                        $this->showUserProductList($categoryId, $page, $messageId);
                    }
                }
                return;
            } elseif (str_starts_with($callbackData, 'user_view_product_')) {
                sscanf($callbackData, "user_view_product_%d_cat_%d_page_%d", $productId, $fromCategoryId, $fromPage);
                if ($productId) {
                    $this->deleteMessage($messageId);
                    $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, null);
                }
                return;
            } elseif (str_starts_with($callbackData, 'toggle_favorite_')) {
                $productId = (int) str_replace('toggle_favorite_', '', $callbackData);

                // *** ۱. خواندن اطلاعات زمینه از فایل ***
                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $fromCategoryId = $returnContext['category_id'] ?? null;
                $fromPage = $returnContext['page'] ?? null;

                $message = "";
                if ($this->db->isProductInFavorites($this->chatId, $productId)) {
                    $this->db->removeFavorite($this->chatId, $productId);
                    $message = "از علاقه مندی ها حذف شد.";
                } else {
                    $this->db->addFavorite($this->chatId, $productId);
                    $message = "به علاقه مندی ها اضافه شد.";
                }

                $this->Alert("❤️ " . $message, false);
                $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'view_product_images_')) {
                $productId = (int) str_replace('view_product_images_', '', $callbackData);
                $this->sendRequest("answerCallbackQuery", ["callback_query_id" => $this->callbackQueryId, "text" => "در حال پردازش ..."]);
                $this->deleteMessage($messageId);
                $this->showProductImages($productId);
                return;
            } elseif (str_starts_with($callbackData, 'view_product_')) {
                $productId = (int) str_replace('view_product_', '', $callbackData);
                $galleryMessageIds = $this->fileHandler->getMessageIds($this->chatId);
                if (!empty($galleryMessageIds)) {
                    $this->deleteMessages($galleryMessageIds);
                    $this->fileHandler->clearMessageIds($this->chatId);
                } else {
                    $this->deleteMessage($messageId);
                }

                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $fromCategoryId = $returnContext['category_id'] ?? null;
                $fromPage = $returnContext['page'] ?? null;
                $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, null);
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_remove_item_')) {
                $cartItemId = (int) str_replace('edit_cart_remove_item_', '', $callbackData);
                $isRemoved = $this->db->removeFromCart($cartItemId);

                if ($isRemoved) {
                    $this->Alert("✅ آیتم از سبد خرید شما حذف شد.", false);
                    $this->deleteMessage($messageId);
                } else {
                    $this->Alert("خطا: آیتم در سبد خرید یافت نشد.");
                }
                return;
            } elseif (str_starts_with($callbackData, 'open_quantity_manager_')) {
                $productId = (int) str_replace('open_quantity_manager_', '', $callbackData);
                if ($productId) {
                    $mainCartItems = $this->db->getUserCart($this->chatId);
                    $tempCart = [];
                    foreach ($mainCartItems as $item) {
                        if ($item['product_id'] == $productId) {
                            $variantId = $item['variant_id'] ?? 0;
                            $tempCart[$variantId] = $item['quantity'];
                        }
                    }
                    $this->fileHandler->addData($this->chatId, [
                        'state_data' => json_encode(['temp_quantity_cart' => $tempCart])
                    ]);
                    $this->promptQuantityManager($productId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'quantity_adjust_')) {
                // این بلوک هر دو دکمه + و - را برای هر دو نوع محصول مدیریت می‌کند
                $productId = null;
                $variantId = null; // می‌تواند 0 برای محصول ساده باشد
                $change = str_starts_with($callbackData, 'quantity_adjust_inc_') ? 1 : -1;
                sscanf($callbackData, "quantity_adjust_%*3s_%d_%d", $variantId, $productId);

                if ($productId === null || $variantId === null) return;

                $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
                $tempCart = $stateData['temp_quantity_cart'] ?? [];
                $currentQuantity = $tempCart[$variantId] ?? 0;

                if ($change > 0) {
                    $product = $this->db->getProductById($productId);
                    if (!$product) {
                        $this->Alert("خطا: محصول یافت نشد.", true);
                        return;
                    }

                    $stock = 0;
                    if ($variantId == 0) {
                        $stock = (int)$product['stock'];
                    } else {
                        foreach ($product['variants'] as $variant) {
                            if ($variant['id'] == $variantId) {
                                $stock = (int)$variant['stock'];
                                break;
                            }
                        }
                    }

                    if ($currentQuantity >= $stock) {
                        $this->Alert("⚠️ شما به حداکثر موجودی این کالا رسیده‌اید.", false);
                        return;
                    }
                }

                $newQuantity = max(0, $currentQuantity + $change);

                $tempCart[$variantId] = $newQuantity;
                $stateData['temp_quantity_cart'] = array_filter($tempCart); // حذف آیتم‌های با تعداد صفر
                $this->fileHandler->addData($this->chatId, ['state_data' => json_encode($stateData)]);

                $this->promptQuantityManager($productId, $messageId); // رفرش منو
                return;
            } elseif (str_starts_with($callbackData, 'quantity_confirm_')) {
                // این بلوک دکمه تایید نهایی را برای هر دو نوع محصول مدیریت می‌کند
                $productId = (int) str_replace('quantity_confirm_', '', $callbackData);
                if (!$productId) return;

                $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
                $tempCart = $stateData['temp_quantity_cart'] ?? [];

                $this->db->removeProductFromCart($this->chatId, $productId);

                $itemsAddedCount = 0;
                foreach ($tempCart as $variantId => $quantity) {
                    if ($quantity > 0) {
                        $actualVariantId = ($variantId == 0) ? null : $variantId;
                        $this->db->addToCart($this->chatId, $productId, $actualVariantId, $quantity);
                        $itemsAddedCount += $quantity;
                    }
                }

                $this->Alert("✅ سبد خرید با موفقیت به‌روزرسانی شد.", false);

                unset($stateData['temp_quantity_cart']);
                $this->fileHandler->addData($this->chatId, ['state_data' => json_encode($stateData)]);

                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $this->showUserSingleProductCard($productId, $returnContext['category_id'] ?? null, $returnContext['page'] ?? null, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'edit_cart_item_')) {
                
                // 2. پارس کردن اطلاعات از callback_data
                $parts = explode(':', $callbackData);
                if (count($parts) !== 3) {
                    Logger::log('error', 'Edit Cart Callback Error', 'Callback data format is incorrect.', ['parts' => $parts]);
                    return;
                }
                $actionPart = $parts[0];
                $identifier = $parts[1];
                $productId = (int)$parts[2];
                $change = str_contains($actionPart, '_inc') ? 1 : -1;

                // 3. خواندن وضعیت موقت سبد خرید
                $tempEditCart = $this->fileHandler->getData($this->chatId, 'edit_cart_state') ?? [];
              
                $currentQuantity = $tempEditCart[$identifier] ?? 0;
                $newQuantity = max(0, $currentQuantity + $change);

                // 4. بررسی ساده و بهینه موجودی انبار
                if ($change > 0) {
                    $stock = $this->db->getStockForCartIdentifier($this->chatId, $productId, $identifier);
                    
                    if ($currentQuantity >= $stock) {
                        $this->Alert("⚠️ شما به حداکثر موجودی این کالا رسیده‌اید.", true);
                        return;
                    }
                }

                $tempEditCart[$identifier] = $newQuantity;
                $this->fileHandler->addData($this->chatId, ['edit_cart_state' => $tempEditCart]);
            
               
                $this->sendEditableCartCard($productId, $messageId);
                return;
            } elseif ($callbackData === 'edit_cart_confirm_all') {
                $tempEditCart = $this->fileHandler->getData($this->chatId, 'edit_cart_state') ?? [];

                foreach ($tempEditCart as $identifier => $quantity) {
                    if (str_starts_with($identifier, 'new_')) {
                        if ($quantity > 0) {
                            $variantId = (int)str_replace('new_', '', $identifier);
                            $productId = $this->db->getProductIdByVariantId($variantId);

                            if ($productId) {
                                $this->db->addToCart($this->chatId, $productId, $variantId, $quantity);
                            } else {
                                Logger::log('error', 'Confirm Edit Cart Error', 'Could not find product_id for new variant_id.', ['variant_id' => $variantId]);
                            }
                        }
                    } else {
                        $cartItemId = (int)$identifier;
                        $this->db->updateCartQuantity($cartItemId, $quantity);
                    }
                }

                $this->fileHandler->addData($this->chatId, ['edit_cart_state' => null]);
                $this->Alert("✅ سبد خرید با موفقیت به‌روزرسانی شد.", false);
                $this->showCart($this->messageId);
                return;
            } elseif ($callbackData === 'edit_cart_cancel_all') {
                $this->fileHandler->addData($this->chatId, ['edit_cart_state' => null]);
                $this->showCart($this->messageId);
                return;
            } elseif (str_starts_with($callbackData, 'quantity_manager_back_')) {
                // این بلوک دکمه بازگشت را مدیریت می‌کند
                $productId = (int) str_replace('quantity_manager_back_', '', $callbackData);
                if (!$productId) return;

                $this->fileHandler->addData($this->chatId, ['state_data' => null]); // پاک کردن سبد موقت
                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $this->deleteMessage($messageId);
                $this->showUserSingleProductCard($productId, $returnContext['category_id'] ?? null, $returnContext['page'] ?? null, null);
                return;
            } elseif (str_starts_with($callbackData, 'view_product_back_from_variant_')) {
                $productId = (int) str_replace('view_product_back_from_variant_', '', $callbackData);
                if (!$productId) return;
                $returnContext = $this->fileHandler->getData($this->chatId, 'product_view_context');
                $fromCategoryId = $returnContext['category_id'] ?? null;
                $fromPage = $returnContext['page'] ?? null;
                $this->showUserSingleProductCard($productId, $fromCategoryId, $fromPage, $messageId);
                return;
            } elseif (strpos($callbackData, 'cancel_edit_category_') === 0) {
                $categoryId = (int) str_replace('cancel_edit_category_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, null);
                $this->reconstructCategoryMessage($categoryId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'toggle_cat_status_')) {
                $categoryId = (int) str_replace('toggle_cat_status_', '', $callbackData);
                $category = $this->db->getCategoryById($categoryId);
                if ($category) {
                    $newStatus = !(bool)$category['is_active'];
                    $this->db->updateCategoryStatus($categoryId, $newStatus);
                    $this->Alert($newStatus ? "✅ دسته بندی فعال شد." : "✅ دسته بندی غیرفعال شد.", false);

                    $this->reconstructCategoryMessage($categoryId, $messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'move_category_up_')) {
                $categoryId = (int) str_replace('move_category_up_', '', $callbackData);
                if ($this->db->moveCategory($categoryId, 'up')) {
                    $this->Alert("✅ جابجا شد.", false);
                    $category = $this->db->getCategoryById($categoryId);
                    $this->showCategoryList($category['parent_id'], $messageId);
                } else {
                    $this->Alert("❌ امکان جابجایی بیشتر وجود ندارد.", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'move_category_down_')) {
                $categoryId = (int) str_replace('move_category_down_', '', $callbackData);
                if ($this->db->moveCategory($categoryId, 'down')) {
                    $this->Alert("✅ جابجا شد.", false);
                    $category = $this->db->getCategoryById($categoryId);
                    $this->showCategoryList($category['parent_id'], $messageId);
                } else {
                    $this->Alert("❌ امکان جابجایی بیشتر وجود ندارد.", false);
                }
                return;
            } elseif (str_starts_with($callbackData, 'move_category_')) {
                $categoryId = (int) str_replace('move_category_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, 'moving_category_' . $categoryId);
                $this->promptForNewParentCategory(null, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'select_new_parent_')) {
                $state = $this->fileHandler->getState($this->chatId);
                if (!str_starts_with($state, 'moving_category_')) return;

                $movingCategoryId = (int)str_replace('moving_category_', '', $state);

                if (str_starts_with($callbackData, 'select_new_parent_nav_')) {
                    $parentId = (int) str_replace('select_new_parent_nav_', '', $callbackData);
                    $this->promptForNewParentCategory($parentId, $messageId);
                } elseif (str_starts_with($callbackData, 'select_new_parent_confirm_')) {
                    $newParentIdInt = (int) str_replace('select_new_parent_confirm_', '', $callbackData);
                    $newParentId = ($newParentIdInt === 0) ? null : $newParentIdInt;

                    if ($movingCategoryId === $newParentId) {
                        $this->Alert("❌ خطا: یک دسته بندی نمی تواند والد خودش باشد.");
                        return;
                    }

                    $result = $this->db->updateCategoryParent($movingCategoryId, $newParentId);

                    if ($result === true) {
                        $this->Alert("✅ دسته بندی با موفقیت جابجا شد.", false);
                        $this->fileHandler->saveState($this->chatId, '');

                        $this->reconstructCategoryMessage($movingCategoryId, $messageId);
                    } elseif ($result === 'circular_dependency') {
                        $this->Alert("❌ خطا: نمی توانید یک دسته بندی را به زیرشاخه های خودش منتقل کنید.");
                    } elseif ($result === 'has_products') {
                        $this->Alert("❌ خطا: والد جدید نمی تواند محصول داشته باشد.");
                    } else {
                        $this->Alert("❌ خطایی در جابجایی رخ داد.");
                    }
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
                        "text" => "لطفاً نام جدید دسته بندی را وارد کنید:\n {$category['name']}",
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [["text" => "❌ انصراف", "callback_data" => "cancel_edit_category_" . $categoryId]]
                            ]
                        ]
                    ]);
                    $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                } else {
                    $this->Alert("دسته بندی یافت نشد.");
                }
            } elseif (strpos($callbackData, 'admin_delete_category_') === 0) {
                $categoryId = (int) str_replace('admin_delete_category_', '', $callbackData);
                $this->promptForDeleteConfirmation($categoryId, $messageId);
                return;
            } elseif (strpos($callbackData, 'confirm_delete_category_') === 0) {
                $categoryId = (int) str_replace('confirm_delete_category_', '', $callbackData);

                $result = $this->db->deleteCategoryById($categoryId);

                if ($result === true) {
                    $this->Alert("✅ دسته‌بندی با موفقیت حذف شد.");
                    $this->showCategoryManagementMenu($messageId);
                } elseif ($result === 'has_products') {
                    $this->Alert("❌ این دسته‌بندی به دلیل داشتن محصول قابل حذف نیست.");
                    $this->showCategoryManagementMenu($messageId);
                } else {
                    $this->Alert("❌ خطایی در هنگام حذف رخ داد.");
                    $this->showCategoryManagementMenu($messageId);
                }
                return;
            } elseif (str_starts_with($callbackData, 'prod_cat_nav_')) {
                $categoryId = (int) str_replace('prod_cat_nav_', '', $callbackData);
                $subcategories = $this->db->getSubcategories($categoryId);

                if (empty($subcategories)) {
                    $this->fileHandler->addData($this->chatId, [
                        'state' =>  'adding_product_name',
                        'state_data' => json_encode(['category_id' => $categoryId])
                    ]);

                    $res = $this->sendRequest("editMessageText", [
                        'chat_id' => $this->chatId,
                        'message_id' => $messageId,
                        'text' => "✅ دسته بندی نهایی انتخاب شد.\n\nحالا لطفاً نام محصول را وارد کنید:",
                        'parse_mode' => 'HTML',
                        'reply_markup' => [
                            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]
                        ]
                    ]);
                    $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
                } else {
                    $this->showCategorySelectionForProduct($categoryId, $messageId);
                }
                return;
            } elseif (strpos($callbackData, 'product_cat_select_') === 0) {
                $categoryId = (int) str_replace('product_cat_select_', '', $callbackData);

                $this->fileHandler->addData($this->chatId, [
                    'state' =>  'adding_product_name',
                    'state_data' => json_encode(['category_id' => $categoryId])
                ]);

                $res = $this->sendRequest("editMessageText", [
                    'chat_id' => $this->chatId,
                    'message_id' => $messageId,
                    'text' => "✅ دسته بندی انتخاب شد.\n\nحالا لطفاً نام محصول را وارد کنید:",
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
            } elseif (strpos($callbackData, 'admin_browse_category_') === 0) {
                $categoryId = (int) str_replace('admin_browse_category_', '', $callbackData);
                $this->promptUserForCategorySelection($categoryId, $messageId);
                return;
            } elseif ($callbackData === 'admin_add_product') {
                $this->showCategorySelectionForProduct(null, $messageId);
            } elseif ($callbackData === 'admin_product_list') {
                $this->promptUserForCategorySelection(null, $messageId); // Start from root
                return;
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
                    "text" => "لطفاً نام دسته بندی جدید را وارد کنید:",
                    "reply_markup" =>
                    [
                        "inline_keyboard" => [
                            [["text" => "🔙 بازگشت", "callback_data" => "admin_panel_entry"]]
                        ]
                    ]
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id'] ?? null);
            } elseif ($callbackData === 'admin_add_category_main') {
                $this->fileHandler->saveState($this->chatId, 'adding_category_name_null'); // null for parent_id
                $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام دسته بندی اصلی جدید را وارد کنید:",
                    "reply_markup" => ["inline_keyboard" => [[["text" => "🔙 بازگشت", "callback_data" => "admin_manage_categories"]]]]
                ]);
                return;
            } elseif ($callbackData === 'admin_add_subcategory_select_parent') {
                $this->promptForParentCategory(null, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'select_parent_nav_')) {
                $parentId = (int) str_replace('select_parent_nav_', '', $callbackData);
                $this->promptForParentCategory($parentId, $messageId);
                return;
            } elseif (str_starts_with($callbackData, 'select_parent_confirm_')) {
                $parentId = (int) str_replace('select_parent_confirm_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, 'adding_category_name_' . $parentId);
                $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام زیردسته جدید را وارد کنید:",
                    "reply_markup" => ["inline_keyboard" => [[["text" => "🔙 بازگشت", "callback_data" => "select_parent_nav_" . $parentId]]]]
                ]);
                return;
            } elseif (str_starts_with($callbackData, 'select_parent_category_')) {
                $parentId = (int) str_replace('select_parent_category_', '', $callbackData);
                $this->fileHandler->saveState($this->chatId, 'adding_category_name_' . $parentId);
                $res =  $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => "لطفاً نام زیردسته جدید را وارد کنید:",
                    "reply_markup" => [
                        "inline_keyboard" => [[["text" => "🔙 بازگشت", "callback_data" => "admin_add_subcategory_select_parent"]]]
                    ]
                ]);
                $this->fileHandler->saveMessageId($this->chatId, $res['result']['message_id']) ?? '';
                return;
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

                $this->fileHandler->addData($this->chatId, [
                    'state' => null,
                    'state_data' => null
                ]);
                $parts = explode(' ', $this->text);
                if (isset($parts[1]) && str_starts_with($parts[1], 'product_')) {
                    $productId = (int) str_replace('product_', '', $parts[1]);
                    $this->showUserSingleProductCard($productId, null, null, null);
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

            // --- مدیریت وضعیت ها (States) ---

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
            if (str_starts_with($state, 'editing_product_')) {
                $this->handleProductEditingSteps();
                return;
            }
            // وضعیت: در حال افزودن دسته بندی جدید
            if (str_starts_with($state, "adding_category_name_")) {
                $categoryName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته بندی نمی تواند خالی باشد.");
                    return;
                }

                $parentIdStr = str_replace('adding_category_name_', '', $state);
                $parentId = ($parentIdStr === 'null') ? null : (int)$parentIdStr;
                if ($this->db->createNewCategory($categoryName, $parentId)) {
                    $messageId = $this->fileHandler->getMessageId($this->chatId);
                    $this->fileHandler->saveState($this->chatId, '');
                    $this->Alert("✅ دسته بندی جدید با موفقیت ایجاد شد.", true, $messageId);
                    $this->showCategoryManagementMenu($messageId);
                } else {
                    $this->Alert("خطا در ایجاد دسته بندی.");
                }
                return;
            }
            // وضعیت: در حال ویرایش نام دسته بندی
            if (str_starts_with($state, 'editing_category_name_')) {
                $categoryName = trim($this->text);
                $this->deleteMessage($this->messageId);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته بندی نمی تواند خالی باشد.");
                    return;
                }

                $categoryId = (int) str_replace('editing_category_name_', '', $state);
                if ($this->db->updateCategoryName($categoryId, $categoryName)) {
                    $this->fileHandler->addData($this->chatId, [
                        'state' => null,
                        'state_data' => null
                    ]);
                    $messageId = $this->fileHandler->getMessageId($this->chatId);

                    $this->reconstructCategoryMessage($categoryId, $messageId);
                } else {
                    $this->Alert("خطا در ویرایش دسته بندی.");
                }
                return;
            }
            if ($state === 'editing_product_discount') {
                $discountPrice = trim($this->text);
                $this->deleteMessage($this->messageId);
                $stateData = json_decode($this->fileHandler->getStateData($this->chatId), true);
                $productId = $stateData['product_id'];
                $product = $this->db->getProductById($productId);

                if (!is_numeric($discountPrice) || $discountPrice < 0) {
                    $this->Alert("⚠️ لطفاً یک عدد معتبر برای قیمت وارد کنید.");
                    return;
                }

                if ($discountPrice > 0 && $discountPrice >= $product['price']) {
                    $this->Alert("⚠️ قیمت تخفیف خورده باید کمتر از قیمت اصلی محصول باشد.");
                    return;
                }

                $priceToSet = ($discountPrice == 0) ? null : (float)$discountPrice;
                $this->db->updateProductDiscount($productId, $priceToSet);

                $this->fileHandler->addData($this->chatId, ['state' => null, 'state_data' => null]);

                $this->Alert($priceToSet ? "✅ تخفیف با موفقیت ثبت شد." : "✅ تخفیف محصول حذف شد.", false);
                $this->showProductEditMenu($productId, $stateData['message_id'], $stateData['category_id'], $stateData['page']);
                return;
            }
            // وضعیت: در حال افزودن دسته بندی جدید
            if ($state === "adding_category_name") {
                $categoryName = trim($this->text);
                if (empty($categoryName)) {
                    $this->Alert("نام دسته بندی نمی تواند خالی باشد.");
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
                        'text' => "✅ دسته بندی جدید با موفقیت ایجاد شد."
                    ]);
                    sleep(2);
                    $this->showCategoryManagementMenu($messageId ?? null);
                } else {
                    $this->Alert("خطا در ایجاد دسته بندی.");
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

            if (in_array($state, ['adding_product_name', 'adding_product_description', 'adding_product_price', 'adding_product_stock', 'adding_product_photos']) || str_starts_with($state, 'adding_variant_')) {
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
                    $this->Alert("⚠️ نام و نام خانوادگی نمی تواند خالی باشد.");
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
                    $this->Alert("⚠️ آدرس نمی تواند خالی باشد.");
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

        // مرحله ۱: دریافت نام محصول
        if ($state === 'adding_product_name') {
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
                'text' => "✅ نام ثبت شد. حالا توضیحات محصول را وارد کنید:",
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]]
            ]);
            return;
        }

        // مرحله ۲: دریافت توضیحات
        if ($state === 'adding_product_description') {
            $stateData['description'] = trim($this->text);
            $this->deleteMessage($this->messageId);
            $this->fileHandler->addData($this->chatId, [
                'state' => 'adding_product_price',
                'state_data' => json_encode($stateData)
            ]);
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ توضیحات ثبت شد. حالا قیمت پایه محصول را به تومان وارد کنید (فقط عدد انگلیسی):",
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]]
            ]);
            return;
        }

        // مرحله ۳: دریافت قیمت
        if ($state === 'adding_product_price') {
            $price = trim($this->text);
            $this->deleteMessage($this->messageId);
            if (!is_numeric($price) || $price < 0) {
                $this->Alert("⚠️ لطفاً یک قیمت معتبر وارد کنید.");
                return;
            }
            $stateData['price'] = (float)$price;
            $this->fileHandler->addData($this->chatId, [
                'state' => 'adding_product_stock',
                'state_data' => json_encode($stateData)
            ]);
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ قیمت ثبت شد. حالا موجودی انبار را وارد کنید (عدد انگلیسی):",
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']]]]
            ]);
            return;
        }

        // مرحله ۴: دریافت موجودی
        if ($state === 'adding_product_stock') {
            $stock = trim($this->text);
            $this->deleteMessage($this->messageId);
            if (!is_numeric($stock) || (int)$stock < 0) {
                $this->Alert("⚠️ لطفاً یک عدد معتبر برای موجودی وارد کنید.");
                return;
            }
            $stateData['stock'] = (int)$stock;
            $this->fileHandler->addData($this->chatId, [
                'state' => 'adding_product_photos',
                'state_data' => json_encode($stateData)
            ]);
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ موجودی ثبت شد. حالا می‌توانید یک یا چند عکس برای محصول ارسال کنید.\n\nپس از ارسال تمام عکس‌ها، دکمه 'اتمام ارسال عکس' را بزنید.",
                'reply_markup' => ['inline_keyboard' => [[['text' => '✅ اتمام ارسال عکس', 'callback_data' => 'product_photos_done']]]]
            ]);
            return;
        }
        // مرحله ۵: دریافت عکس، ذخیره محلی فایل با نام مرتبط و ذخیره file_id
        if ($state === 'adding_product_photos') {
            $this->deleteMessage($this->messageId);

            if (isset($this->message['photo'])) {
                $fileId = end($this->message['photo'])['file_id'];
                $fileInfoResponse = $this->sendRequest('getFile', ['file_id' => $fileId]);

                if (isset($fileInfoResponse['ok']) && $fileInfoResponse['ok'] === true) {
                    $filePathOnTelegram = $fileInfoResponse['result']['file_path'];
                    $fileUrl = "https://api.telegram.org/file/bot" . $this->botToken . "/" . $filePathOnTelegram;

                    $imageContent = file_get_contents($fileUrl);

                    if ($imageContent !== false) {
                        $uploadDir = __DIR__ . '/../public/uploads/products/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $fileExtension = pathinfo($filePathOnTelegram, PATHINFO_EXTENSION);
                        $safeFileName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $fileId);
                        $newFileName = $safeFileName . '.' . $fileExtension;
                        file_put_contents($uploadDir . $newFileName, $imageContent);
                        $stateData['images'][] = $fileId;
                        $this->fileHandler->addData($this->chatId, ['state_data' => json_encode($stateData)]);

                        $this->Alert('✅ عکس دریافت و ذخیره شد. می‌توانید عکس بعدی را ارسال کنید.', false);
                    } else {
                        $this->Alert('❌ مشکلی در دانلود عکس از سرور تلگرام به وجود آمد.');
                    }
                } else {
                    $this->Alert('❌ مشکلی در دریافت اطلاعات عکس از تلگرام به وجود آمد.');
                }
            } else {
                $this->Alert('لطفاً فقط عکس ارسال کنید.');
            }
            return;
        }
        if (str_starts_with($state, 'adding_variant_')) {
            $this->handleVariantCreationSteps($state, $stateData);
            return;
        }
    }


    private function handleVariantCreationSteps($state, $stateData)
    {
        $messageId = $this->fileHandler->getMessageId($this->chatId);
        $variantIndex = $stateData['variant_index'] ?? 0;

        // مرحله ۶.۱: دریافت نام واریانت
        if ($state === 'adding_variant_name') {
            $variantName = trim($this->text);
            $this->deleteMessage($this->messageId);
            if (empty($variantName)) {
                $this->Alert("⚠️ نام ویژگی نمی‌تواند خالی باشد.");
                return;
            }
            $stateData['variants'][$variantIndex]['name'] = $variantName;
            $this->fileHandler->addData($this->chatId, [
                'state' => 'adding_variant_price',
                'state_data' => json_encode($stateData)
            ]);

            $priceKeyboard = [];
            $basePrice = $stateData['price'] ?? 0;
            $priceKeyboard[] = [['text' => '💰 همان قیمت اصلی (' . number_format($basePrice) . ')', 'callback_data' => 'variant_use_price_' . $basePrice]];

            $previousVariants = array_slice($stateData['variants'], 0, $variantIndex);
            $existingPrices = array_unique(array_column($previousVariants, 'price'));
            foreach ($existingPrices as $price) {
                if ($price != $basePrice) {
                    $priceKeyboard[] = [['text' => '💰 استفاده از قیمت ' . number_format($price), 'callback_data' => 'variant_use_price_' . $price]];
                }
            }
            $priceKeyboard[] = [['text' => '❌ لغو افزودن ویژگی', 'callback_data' => 'product_variants_done']];


            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ نام ویژگی: " . htmlspecialchars($variantName) . "\n\nحالا قیمت این ویژگی را وارد کنید:",
                'reply_markup' => ['inline_keyboard' => $priceKeyboard]
            ]);
            return;
        }

        // مرحله ۶.۲: دریافت قیمت واریانت
        if ($state === 'adding_variant_price') {
            $price = trim($this->text);
            $this->deleteMessage($this->messageId);
            if (!is_numeric($price) || $price < 0) {
                $this->Alert("⚠️ قیمت نامعتبر است.");
                return;
            }
            $stateData['variants'][$variantIndex]['price'] = (float)$price;
            $this->fileHandler->addData($this->chatId, [
                'state' => 'adding_variant_stock',
                'state_data' => json_encode($stateData)
            ]);

            $stockKeyboard = [];
            $baseStock = $stateData['stock'] ?? 0;
            $stockKeyboard[] = [['text' => '📦 همان موجودی اصلی (' . $baseStock . ')', 'callback_data' => 'variant_use_stock_' . $baseStock]];

            $previousVariants = array_slice($stateData['variants'], 0, $variantIndex);
            $existingStocks = array_unique(array_column($previousVariants, 'stock'));
            foreach ($existingStocks as $stock) {
                if ($stock != $baseStock) {
                    $stockKeyboard[] = [['text' => '📦 استفاده از موجودی ' . $stock, 'callback_data' => 'variant_use_stock_' . $stock]];
                }
            }
            $stockKeyboard[] = [['text' => '❌ لغو افزودن ویژگی', 'callback_data' => 'product_variants_done']];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ قیمت ثبت شد. حالا موجودی این ویژگی را وارد کنید:",
                'reply_markup' => ['inline_keyboard' => $stockKeyboard]
            ]);
            return;
        }

        // مرحله ۶.۳: دریافت موجودی واریانت
        if ($state === 'adding_variant_stock') {
            $stock = trim($this->text);
            $this->deleteMessage($this->messageId);
            if (!is_numeric($stock) || (int)$stock < 0) {
                $this->Alert("⚠️ موجودی نامعتبر است.");
                return;
            }
            $stateData['variants'][$variantIndex]['stock'] = (int)$stock;
            $stateData['variant_index'] = $variantIndex + 1;
            $this->fileHandler->addData($this->chatId, [
                'state' => 'asking_another_variant',
                'state_data' => json_encode($stateData)
            ]);
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => "✅ ویژگی با موفقیت افزوده شد. آیا می‌خواهید ویژگی دیگری اضافه کنید؟",
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '✅ بله، افزودن ویژگی جدید', 'callback_data' => 'add_another_variant']],
                    [['text' => ' خیر، ادامه و پیش‌نمایش', 'callback_data' => 'product_variants_done']]
                ]]
            ]);
        }
    }
    private function showConfirmationPreview(): void
    {
        $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);

        $previewText = "✨ <b>پیش‌نمایش و تایید نهایی</b> ✨\n";
        $previewText .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖\u{200F}\n";
        $previewText .= "🏷 <b>نام محصول:</b>\n" . htmlspecialchars($stateData['name'] ?? '') . "\n";
        $previewText .= "📝 <b>توضیحات:</b>\n<blockquote>" . htmlspecialchars($stateData['description'] ?? '') . "</blockquote>\n";

        if (empty($stateData['variants'])) {
            $previewText .= "💰 <b>قیمت:</b> " . number_format($stateData['price'] ?? 0) . " تومان\n";
            $previewText .= "📦 <b>موجودی:</b> " . ($stateData['stock'] ?? 0) . " عدد\n";
        }
        $previewText .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖\u{200F}\n";;

        if (!empty($stateData['variants'])) {
            $previewText .= "📋 <b>ویژگی‌های محصول:</b>\n";
            foreach ($stateData['variants'] as $variant) {
                $price = number_format($variant['price']);
                $stock = $variant['stock'];
                $name = htmlspecialchars($variant['name']);
                $previewText .= "\n- ▫️ <b>{$name}</b>\n";
                $previewText .= "  ▫️ قیمت: {$price} تومان\n";
                $previewText .= "  ▫️ موجودی: {$stock} عدد";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ تایید و ذخیره نهایی', 'callback_data' => 'product_confirm_save']],
                [['text' => '❌ لغو عملیات', 'callback_data' => 'product_confirm_cancel']]
            ]
        ];

        if (!empty($stateData['images'])) {
            $allImages = $stateData['images'];
            $imageCount = count($allImages);

            if ($imageCount > 1) {
                $mediaGroup = [];
                $imagesToSendInGroup = array_slice($allImages, 0, -1);
                foreach ($imagesToSendInGroup as $fileId) {
                    $mediaGroup[] = ['type' => 'photo', 'media' => $fileId];
                }
                $this->sendRequest('sendMediaGroup', ['chat_id' => $this->chatId, 'media' => json_encode($mediaGroup)]);
            }

            $lastImageFileId = end($allImages);
            $this->sendRequest('sendPhoto', [
                'chat_id'      => $this->chatId,
                'photo'        => $lastImageFileId,
                'caption'      => $previewText,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest('sendMessage', [
                'chat_id'      => $this->chatId,
                'text'         => $previewText . "\n\n❓ در صورت تایید اطلاعات، دکمه زیر را بزنید:",
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard
            ]);
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

        // Logger::log(
        //     'info',
        //     "Telegram API Request Successful",
        //     "Method: {$method}",
        //     [
        //         'request_data' => $data,
        //         'response'     => $response,
        //         'http_code'    => $httpCode,
        //     ],
        //     false 
        // );

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
    public function Alert($message, $alert = true, $messageId = null): void
    {
        if ($this->callbackQueryId) {
            $data = [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $message,
                'show_alert' => $alert
            ];
            $this->sendRequest("answerCallbackQuery", $data);
        } else {
            if ($messageId) {
                $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    'message_id'  => $messageId,
                    "text" => $message,
                ]);
                sleep(2);
            } else {
                $res = $this->sendRequest("sendMessage", [
                    "chat_id" => $this->chatId,
                    "text" => $message,
                ]);
                $this->deleteMessage($res['result']['message_id'] ?? null, delay: 2);
            }
        }
    }
    private function generateProductCardText(array $product): string
    {
        $name = htmlspecialchars($product['name']);
        $desc = !empty($product['description']) ? htmlspecialchars($product['description']) : '<i>توضیحات ثبت نشده است.</i>';

        $text = "🛍 <b>{$name}</b>\n";
        $text .= "----------------------------------------------------------------------\u{200F}\n";
        $text .= "{$desc}\n\n";

        // --- NEW DISCOUNT LOGIC ---
        if (!empty($product['discount_price']) && (float)$product['discount_price'] < (float)$product['price']) {
            $originalPrice = (float)$product['price'];
            $discountPrice = (float)$product['discount_price'];
            $discountPercent = round((($originalPrice - $discountPrice) / $originalPrice) * 100);

            $text .= "💵 <del>" . number_format($originalPrice) . " تومان</del>\n";
            $text .= "🔥 <b>" . number_format($discountPrice) . " تومان</b> (٪" . $discountPercent . " تخفیف!)\n";
        } else {
            $text .= "💵 <b>قیمت:</b> " . number_format($product['price']) . " تومان\n";
        }
        // --- END OF DISCOUNT LOGIC ---

        $stock = (int)($product['stock'] ?? 0);
        if ($stock > 10) {
            $text .= "📦 <b>وضعیت:</b> ✅ موجود\n";
        } elseif ($stock > 0) {
            $text .= "📦 <b>وضعیت:</b> ⚠️ تعداد محدود ({$stock} عدد)\n";
        } else {
            $text .= "📦 <b>وضعیت:</b> ❌ ناموجود\n";
        }

        if (isset($product['quantity'])) {
            $quantity = (int)$product['quantity'];
            $text .= "----------------------------------------------------------------------\u{200F}\n";
            $text .= "🛒 <b>تعداد در سبد شما:</b> {$quantity} عدد\n";
        }

        return $text;
    }
    public function showDiscountedProductList($page = 1, $messageId = null): void
    {
        $allProducts = $this->db->getActiveDiscountedProducts();

        if (empty($allProducts)) {
            $this->Alert("🔥 در حال حاضر محصول تخفیف‌داری وجود ندارد.");
            if ($messageId) $this->MainMenu($messageId);
            return;
        }

        $perPage = 8;
        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $text = "🔥 <b>محصولات دارای تخفیف ویژه</b>\n";
        $text .= "صفحه {$page} از {$totalPages}\n";

        $buttons = [];
        $row = [];
        foreach ($productsOnPage as $product) {
            $callbackData = 'user_view_product_' . $product['id'] . '_cat_discount_page_' . $page;
            $row[] = ['text' => htmlspecialchars($product['name']), 'callback_data' => $callbackData];
            if (count($row) >= 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "◀️ قبل", 'callback_data' => "list_discounted_products_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "بعد ▶️", 'callback_data' => "list_discounted_products_page_" . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
        $keyboard = ['inline_keyboard' => $buttons];

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }
    }
    public function promptUserForCategorySelection($parentId = null, $messageId = null): void
    {
        $categories = ($parentId === null)
            ? $this->db->getRootCategories()
            : $this->db->getSubcategories($parentId);

        $parentCategory = $parentId ? $this->db->getCategoryById($parentId) : null;
        $pathText = $parentId ? $this->db->getCategoryPath($parentId) : 'دسته‌بندی‌های اصلی';

        $text = "📂 <b>لیست محصولات</b>\n";
        $text .= "📍 مسیر فعلی: <b>" . htmlspecialchars($pathText) . "</b>\n\n";
        $text .= "برای مشاهده محصولات، وارد دسته‌بندی نهایی شوید:";

        $buttons = [];
        $row = [];
        foreach ($categories as $category) {
            // Callback for browsing deeper into categories
            $row[] = ['text' => '📁 ' . htmlspecialchars($category['name']), 'callback_data' => 'admin_browse_category_' . $category['id']];
            if (count($row) >= 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        // Check if the current category (the parent of the list) has products
        if ($parentId !== null) {
            $productsInCurrentCat = $this->db->getProductsByCategoryId($parentId);
            // If there are no subcategories but there are products, show the view button
            if (empty($categories) && !empty($productsInCurrentCat)) {
                $buttons[] = [['text' => '📦 مشاهده/ویرایش محصولات این دسته', 'callback_data' => 'list_products_cat_' . $parentId . '_page_1']];
            }
        }

        // Back button logic
        if ($parentCategory) {
            $backCallback = $parentCategory['parent_id'] !== null
                ? 'admin_browse_category_' . $parentCategory['parent_id']
                : 'admin_product_list'; // Go back to root selection
            $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => $backCallback]];
        } else {
            $buttons[] = [['text' => '⬅️ بازگشت به مدیریت محصولات', 'callback_data' => 'admin_manage_products']];
        }

        $keyboard = ['inline_keyboard' => $buttons];
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
            $hour < 12 => "☀️ صبح بخیر! آماده ای برای دیدن پیشنهادهای خاص امروز؟",
            $hour < 18 => "🌼 عصر بخیر! یه چیزی خاص برای امروز داریم 😉",
            default => "🌙 شب بخیر! شاید وقتشه یه هدیه  خاص برای خودت یا عزیزات پیدا کنی...",
        };

        if (!empty($settings['main_menu_text'])) {
            $menuText = $settings['main_menu_text'] . "\n\n" . "<blockquote>{$defaultWelcome}</blockquote>";
        } else {
            $menuText = $defaultWelcome;
        }

        $allCategories = $this->db->getActiveRootCategories();
        $categoryButtons = [];

        if (!empty($settings['daily_offer'])) {
            $categoryButtons[] = [['text' => '🔥 پیشنهاد ویژه امروز', 'callback_data' => 'daily_offer']];
        }
        $categoryButtons[] = [['text' => '🔥 محصولات تخفیف‌دار', 'callback_data' => 'list_discounted_products_page_1']];


        if (!empty($allCategories)) {
            $row = [];
            foreach ($allCategories as $category) {
                if ($category['is_active']) {
                    $row[] = ['text' => $category['name'], 'callback_data' => 'category_' . $category['id']];
                    if (count($row) == 2) {
                        $categoryButtons[] = $row;
                        $row = [];
                    }
                }
            }
            if (!empty($row)) {
                $categoryButtons[] = $row;
            }
        }

        $staticButtons = [
            [['text' => '❤️ علاقه مندی ها', 'callback_data' => 'show_favorites'], ['text' => '🛒 سبد خرید', 'callback_data' => 'show_cart']],
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
            $this->Alert("شما تاکنون هیچ سفارشی ثبت نکرده اید.");
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
    public function showCategorySelectionForProduct(?int $parentId, $messageId = null): void
    {
        $categories = $parentId === null
            ? $this->db->getRootCategories()
            : $this->db->getSubcategories($parentId);

        if ($parentId === null && empty($categories)) {
            $this->Alert("❌ برای افزودن محصول، ابتدا باید حداقل یک دسته‌بندی ایجاد کنید.");
            $this->showProductManagementMenu($messageId);
            return;
        }

        $parentCategory = $parentId ? $this->db->getCategoryById($parentId) : null;
        $text = $parentCategory
            ? "زیرشاخه ای از \"<b>" . htmlspecialchars($parentCategory['name']) . "</b>\" را انتخاب کنید:"
            : "لطفاً دسته بندی اصلی محصول را انتخاب کنید:";

        $buttons = [];
        $row = [];
        foreach ($categories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'prod_cat_nav_' . $category['id']];
            if (count($row) >= 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        if ($parentCategory) {
            $backCallback = $parentCategory['parent_id']
                ? 'prod_cat_nav_' . $parentCategory['parent_id']
                : 'admin_manage_products';
            $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => $backCallback]];
        } else {
            $buttons[] = [['text' => '❌ انصراف', 'callback_data' => 'admin_manage_products']];
        }

        $keyboard = ['inline_keyboard' => $buttons];

        $data = [
            'chat_id'      => $this->chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
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

        $text = "📞 برای ارتباط با واحد پشتیبانی می توانید مستقیماً از طریق آیدی زیر اقدام کنید .\n\n";
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

        if ($invoice['status'] === 'pending') {
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
        // تبدیل سبد خرید به یک آرایه ساده تر برای جستجوی سریع
        $cartProductIds = array_column($cartItems, 'quantity', 'id');

        $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
        if (!empty($previousMessageIds)) {
            $this->deleteMessages($previousMessageIds);
            $this->fileHandler->clearMessageIds($this->chatId);
        }

        if (empty($favoriteProducts)) {
            $this->Alert("❤️ لیست علاقه مندی های شما خالی است.");
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

            $keyboardRows[] = [['text' => '❤️ حذف از علاقه مندی', 'callback_data' => 'toggle_favorite_' . $productId]];

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


        $navText = "--- علاقه مندی ها (صفحه {$page} از {$totalPages}) ---";
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
    public function promptForNewParentCategory(?int $parentId, int $messageId): void
    {
        $state = $this->fileHandler->getState($this->chatId) ?? '';
        // ابتدا بررسی می کنیم که آیا واقعاً در وضعیت جابجایی دسته بندی هستیم یا خیر
        if (!str_starts_with($state, 'moving_category_')) {
            $this->showCategoryManagementMenu($messageId); // در غیر این صورت به منوی اصلی باز می گردیم
            return;
        }

        $movingCategoryId = (int)str_replace('moving_category_', '', $state);
        $movingCategory = $this->db->getCategoryById($movingCategoryId);

        // اگر دسته بندی مورد نظر برای جابجایی وجود نداشت، عملیات را متوقف می کنیم
        if (!$movingCategory) {
            $this->Alert("خطا: دسته بندی مورد نظر برای جابجایی یافت نشد.");
            $this->showCategoryManagementMenu($messageId);
            return;
        }

        // دریافت دسته بندی های سطح فعلی
        $categories = ($parentId === null)
            ? $this->db->getRootCategories()  // اگر در ریشه هستیم
            : $this->db->getSubcategories($parentId); // اگر در یک زیرشاخه هستیم

        $parentCategory = $parentId ? $this->db->getCategoryById($parentId) : null;
        $pathText = $parentId ? $this->db->getCategoryPath($parentId) : 'ریشه اصلی';

        // ساخت متن پیام برای کاربر
        $text = "🔄 <b>جابجایی دسته بندی:</b> \"" . htmlspecialchars($movingCategory['name']) . "\"\n\n";
        $text .= "📍 مسیر فعلی: <b>" . htmlspecialchars($pathText) . "</b>\n\n";
        $text .= "لطفاً مقصد جدید را انتخاب کنید:";

        $buttons = [];

        // دکمه "انتخاب این دسته" فقط زمانی نمایش داده می شود که در یک زیرشاخه باشیم
        if ($parentId !== null && $parentId != $movingCategoryId) {
            $buttons[] = [['text' => '✅ انتخاب "' . htmlspecialchars($parentCategory['name']) . '" به عنوان والد', 'callback_data' => 'select_new_parent_confirm_' . $parentId]];
        }

        // دکمه "انتقال به ریشه" همیشه نمایش داده می شود
        $buttons[] = [['text' => '🔝 انتقال به سطح اصلی (بدون والد)', 'callback_data' => 'select_new_parent_confirm_0']];

        // نمایش لیست زیرشاخه ها برای پیمایش
        foreach ($categories as $category) {
            // خود دسته بندی در حال جابجایی را در لیست مقاصد نمایش نمی دهیم
            if ($category['id'] != $movingCategoryId) {
                $buttons[] = [['text' => '📁 ' . htmlspecialchars($category['name']), 'callback_data' => 'select_new_parent_nav_' . $category['id']]];
            }
        }

        // دکمه های ناوبری (بازگشت و لغو)
        $navigationRow = [];
        if ($parentCategory) {
            // دکمه بازگشت به سطح بالاتر
            $backCallback = $parentCategory['parent_id'] !== null
                ? 'select_new_parent_nav_' . $parentCategory['parent_id']
                : 'admin_category_list'; // اگر والد در ریشه است، به لیست اصلی برمی گردیم
            $navigationRow[] = ['text' => '⬆️ بازگشت به سطح بالاتر', 'callback_data' => $backCallback];
        }
        $navigationRow[] = ['text' => '❌ لغو عملیات', 'callback_data' =>  "cancel_edit_category_" . $movingCategoryId];
        $buttons[] = $navigationRow;

        // ارسال پیام با دکمه های جدید
        $keyboard = ['inline_keyboard' => $buttons];
        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    // public function showCart($messageId = null): void
    // {
    //     $cartItems = $this->db->getUserCart($this -> chatId);

    //     $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
    //     if (!empty($previousMessageIds)) {
    //         $this->deleteMessages($previousMessageIds);
    //         $this->fileHandler->clearMessageIds($this->chatId);
    //     }

    //     if (empty($cartItems)) {
    //         $this->Alert("🛒 سبد خرید شما خالی است.");
    //         $this->MainMenu($messageId);
    //         return;
    //     }

    //     $shippingInfo = $this->db->getUserShippingInfo($this->chatId);
    //     $shippingInfoComplete = !empty($shippingInfo);

    //     $settings = $this->db->getAllSettings();
    //     $storeName = $settings['store_name'] ?? 'فروشگاه من';
    //     $deliveryCost = (int)($settings['delivery_price'] ?? 0);
    //     $taxPercent = (int)($settings['tax_percent'] ?? 0);
    //     $discountFixed = (int)($settings['discount_fixed'] ?? 0);

    //     $date = jdf::jdate('Y/m/d');

    //     $text = "🧾 <b>فاکتور خرید از {$storeName}</b>\n";
    //     $text .= "📆 تاریخ: {$date}\n\n";

    //     if ($shippingInfoComplete) {
    //         $text .= "🚚 <b>مشخصات گیرنده:</b>\n";
    //         $text .= "👤 نام: " . htmlspecialchars($shippingInfo['name']) . "\n";
    //         $text .= "📞 تلفن: " . htmlspecialchars($shippingInfo['phone']) . "\n";
    //         $text .= "📍 آدرس: " . htmlspecialchars($shippingInfo['address']) . "\n\n";
    //     }

    //     $text .= "<b>📋 لیست اقلام:</b>\n";
    //     $totalPrice = 0;

    //     // --- *** شروع اصلاحات کلیدی *** ---
    //     foreach ($cartItems as $item) {
    //         $unitPrice = $item['price'];
    //         $quantity = $item['quantity'];
    //         $itemPrice = $unitPrice * $quantity;
    //         $totalPrice += $itemPrice;

    //         // نام اصلی محصول را می‌گیریم
    //         $itemName = htmlspecialchars($item['product_name']);

    //         // اگر ویژگی داشت، نام ویژگی را به آن اضافه می‌کنیم
    //         if (!empty($item['variant_name'])) {
    //             $itemName .= " - (<b>" . htmlspecialchars($item['variant_name']) . "</b>)";
    //         }

    //         $text .= "🔸 " . $itemName . "\n";
    //         $text .= "  ➤ تعداد: {$quantity} | قیمت واحد: " . number_format($unitPrice) . " تومان\n";
    //         $text .= "  💵 مجموع: " . number_format($itemPrice) . " تومان\n\n";
    //     }
    //     // --- *** پایان اصلاحات کلیدی *** ---

    //     $taxAmount = round($totalPrice * $taxPercent / 100);
    //     $grandTotal = $totalPrice + $taxAmount + $deliveryCost - $discountFixed;

    //     $text .= "📦 هزینه ارسال: " . number_format($deliveryCost) . " تومان\n";
    //     if ($discountFixed > 0) {
    //         $text .= "💸 تخفیف: " . number_format($discountFixed) . " تومان\n";
    //     }
    //     $text .= "📊 مالیات ({$taxPercent}%): " . number_format($taxAmount) . " تومان\n";
    //     $text .= "💰 <b>مبلغ نهایی قابل پرداخت:</b> <b>" . number_format($grandTotal) . "</b> تومان";

    //     // ... (بخش ساخت دکمه‌ها بدون تغییر باقی می‌ماند) ...
    //     $keyboardRows = [];
    //     if ($shippingInfoComplete) {
    //         $keyboardRows[] = [['text' => '💳 پرداخت نهایی', 'callback_data' => 'checkout']];
    //         $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
    //         $keyboardRows[] = [['text' => '📝 ویرایش اطلاعات ارسال', 'callback_data' => 'edit_shipping_info']];
    //     } else {
    //         $keyboardRows[] = [['text' => '📝 تکمیل اطلاعات ارسال', 'callback_data' => 'complete_shipping_info']];
    //         $keyboardRows[] = [['text' => '🗑 خالی کردن سبد', 'callback_data' => 'clear_cart'], ['text' => '✏️ ویرایش سبد خرید', 'callback_data' => 'edit_cart']];
    //     }
    //     $keyboardRows[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
    //     $keyboard = ['inline_keyboard' => $keyboardRows];

    //     if ($messageId) {
    //         $this->sendRequest("editMessageText", [
    //             'chat_id' => $this->chatId,
    //             "message_id" => $messageId,
    //             'text' => $text,
    //             'parse_mode' => 'HTML',
    //             'reply_markup' => $keyboard
    //         ]);
    //     } else {
    //         $this->sendRequest("sendMessage", [
    //             'chat_id' => $this->chatId,
    //             'text' => $text,
    //             'parse_mode' => 'HTML',
    //             'reply_markup' => $keyboard
    //         ]);
    //     }
    // }
    public function showCart($messageId = null): void
    {
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

        $shippingInfo = $this->db->getUserShippingInfo($this->chatId);
        $shippingInfoComplete = !empty($shippingInfo);

        $settings = $this->db->getAllSettings();
        $storeName = $settings['store_name'] ?? 'فروشگاه من';
        $deliveryCost = (int)($settings['delivery_price'] ?? 0);
        $taxPercent = (int)($settings['tax_percent'] ?? 0);
        $discountFixed = (int)($settings['discount_fixed'] ?? 0);

        $date = jdf::jdate('Y/m/d');

        // شروع ساخت متن فاکتور با طراحی جذاب‌تر
        $text  = "🛒 <b>سبد خرید شما</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}\n";
        $text .= "🏬 {$storeName}\n";
        $text .= "📅 تاریخ: {$date}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}\n\n";

        if ($shippingInfoComplete) {
            $text .= "🚚 <b>اطلاعات گیرنده</b>\n";
            $text .= "👤 نام: <b>" . htmlspecialchars($shippingInfo['name']) . "</b>\n";
            $text .= "📞 تلفن: <b>" . htmlspecialchars($shippingInfo['phone']) . "</b>\n";
            $text .= "📍 آدرس: <b>" . htmlspecialchars($shippingInfo['address']) . "</b>\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}\n\n";
        }

        $text .= "📦 <b>جزئیات سفارش:</b>\n";
        $totalPrice = 0;
        foreach ($cartItems as $index => $item) {
            $unitPrice = $item['price'];
            $quantity  = $item['quantity'];
            $itemPrice = $unitPrice * $quantity;
            $totalPrice += $itemPrice;

            $itemName = htmlspecialchars($item['product_name']);
            if (!empty($item['variant_name'])) {
                $itemName .= " <i>(" . htmlspecialchars($item['variant_name']) . ")</i>";
            }

            $numEmoji = ($index + 1) . "️⃣"; // شماره‌گذاری با ایموجی
            $text .= "{$numEmoji} 🛍 <b>{$itemName}</b>\n";
            $text .= "   ✦ تعداد: {$quantity}\n";
            $text .= "   ✦ قیمت واحد: " . number_format($unitPrice) . " تومان\n";
            $text .= "   ✦ جمع: " . number_format($itemPrice) . " تومان\n\n";
        }

        $taxAmount  = round($totalPrice * $taxPercent / 100);
        $grandTotal = $totalPrice + $taxAmount + $deliveryCost - $discountFixed;

        $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}\n";
        $text .= "💵 <b>خلاصه پرداخت</b>\n";
        $text .= "🛍 جمع کل کالاها: " . number_format($totalPrice) . " تومان\n";
        $text .= "📦 هزینه ارسال: " . number_format($deliveryCost) . " تومان\n";
        if ($discountFixed > 0) {
            $text .= "💸 تخفیف: " . number_format($discountFixed) . " تومان\n";
        }
        if ($taxPercent > 0) {
        $text .= "📊 مالیات ({$taxPercent}%): " . number_format($taxAmount) . " تومان\n";
        }
        $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}\n\n";

        $text .= "✨💰 <b>مبلغ نهایی: " . number_format($grandTotal) . " تومان</b> ✨\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\u{200F}";


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

        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }
    }
    // public function showAdminMainMenu($messageId = null): void
    // {
    //     $adminToken = $this->db->createAdminToken($this->chatId);
    //     $webAppUrl = '';
    //     if ($adminToken) {
    //         $link = AppConfig::get("bot.bot_web");
    //         $botId = AppConfig::getCurrentBotId(); // دریافت شناسه ربات فعلی
    //         $baseWebAppUrl = $link . '/admin/index.php';
    //         $webAppUrl = $baseWebAppUrl . '?bot_id=' . $botId . '&token=' . $adminToken;
    //     }
    //     $keyboard = [
    //         'inline_keyboard' => [
    //             [
    //                 ['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories'],
    //                 ['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']
    //             ],
    //             [
    //                 ['text' => '🧾 مدیریت فاکتورها', 'callback_data' => 'admin_manage_invoices'],
    //                 // ['text' => '📊 آمار و گزارشات', 'callback_data' => 'admin_reports']
    //                 ['text' => '📊 آمار و گزارشات', 'web_app' => ['url' => $webAppUrl]]

    //             ],
    //             [
    //                 ['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']
    //             ],
    //             [
    //                 ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']
    //             ]
    //         ]
    //     ];

    //     // --- شروع تغییرات برای نمایش آمار ---
    //     $stats = $this->db->getStatsSummary();
    //     $jdate = jdf::jdate('l، j F Y');

    //     $text  = "🤖 <b>پنل مدیریت ربات</b>\n";
    //     $text .= "📅 " . $jdate . "\n";
    //     $text .= "----------------------------------------------------------------------\u{200F}\n";
    //     $text .= "📊 <b>آمار کلی:</b>\n";
    //     $text .= "👤 کاربران کل: " . number_format($stats['total_users']) . " (<b>" . number_format($stats['new_users_today']) . "</b> کاربر جدید امروز)\n";
    //     $text .= "🛍 محصولات: " . number_format($stats['total_products']) . " (<b>" . number_format($stats['low_stock_products']) . "</b> محصول رو به اتمام)\n\n";

    //     $text .= "📈 <b>وضعیت امروز:</b>\n";
    //     $text .= "💰 درآمد (تایید شده): <b>" . number_format($stats['todays_revenue']) . "</b> تومان\n";
    //     $text .= "⏳ سفارشات در انتظار بررسی: <b>" . number_format($stats['pending_invoices']) . "</b> مورد\n";
    //     $text .= "----------------------------------------------------------------------\u{200F}\n";
    //     $text .= "لطفاً از گزینه‌های زیر یکی را انتخاب کنید:";
    //     // --- پایان تغییرات ---

    //     $data = [
    //         "chat_id" => $this->chatId,
    //         "text" => $text,
    //         "parse_mode" => "HTML",
    //         "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
    //     ];

    //     if ($messageId) {
    //         $data["message_id"] = $messageId;
    //         $this->sendRequest("editMessageText", $data);
    //     } else {
    //         $this->sendRequest("sendMessage", $data);
    //     }
    // }

    public function showAdminMainMenu($messageId = null)
    {
        $adminToken = $this->db->createAdminToken($this->chatId);
        $webAppUrl = ''; // مقدار پیش‌فرض

        if ($adminToken) {
            // ۱. خواندن آدرس پایه از متغیرهای محیطی (master.env)
            $baseUrl = $_ENV['APP_URL'] ?? '';

            // ۲. دریافت شناسه متنی ربات (مثلا 'amir')
            $botIdString = AppConfig::getCurrentBotIdString();

            // ۳. ساخت URL کامل و مطلق برای وب اپ
            // چون ریشه وب سرور پوشه public است، آدرس از /admin/ شروع می‌شود
            $webAppUrl = rtrim($baseUrl, '/') . '/admin/index.php?bot_id=' . $botIdString . '&token=' . $adminToken;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛍 مدیریت دسته‌بندی‌ها', 'callback_data' => 'admin_manage_categories'],
                    ['text' => '📝 مدیریت محصولات', 'callback_data' => 'admin_manage_products']
                ],
                [
                    ['text' => '🧾 مدیریت فاکتورها', 'callback_data' => 'admin_manage_invoices'],
                    // دکمه وب اپ فقط در صورتی نمایش داده می‌شود که URL ساخته شده باشد
                    ['text' => '📊 آمار و گزارشات', 'web_app' => ['url' => $webAppUrl]]
                ],
                [
                    ['text' => '⚙️ تنظیمات ربات', 'callback_data' => 'admin_bot_settings']
                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        // --- (بخش نمایش آمار بدون تغییر باقی می‌ماند) ---
        $stats = $this->db->getStatsSummary();
        $jdate = jdf::jdate('l، j F Y');

        $text  = "🤖 <b>پنل مدیریت ربات</b>\n";
        $text .= "📅 " . $jdate . "\n";
        $text .= "----------------------------------------------------------------------\u{200F}\n";
        $text .= "📊 <b>آمار کلی:</b>\n";
        $text .= "👤 کاربران کل: " . number_format($stats['total_users']) . " (<b>" . number_format($stats['new_users_today']) . "</b> کاربر جدید امروز)\n";
        $text .= "🛍 محصولات: " . number_format($stats['total_products']) . " (<b>" . number_format($stats['low_stock_products']) . "</b> محصول رو به اتمام)\n\n";
        $text .= "📈 <b>وضعیت امروز:</b>\n";
        $text .= "💰 درآمد (تایید شده): <b>" . number_format($stats['todays_revenue']) . "</b> تومان\n";
        $text .= "⏳ سفارشات در انتظار بررسی: <b>" . number_format($stats['pending_invoices']) . "</b> مورد\n";
        $text .= "----------------------------------------------------------------------\u{200F}\n";
        $text .= "لطفاً از گزینه‌های زیر یکی را انتخاب کنید:";

        $data = [
            "chat_id" => $this->chatId,
            "text" => $text,
            "parse_mode" => "HTML",
            "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
        ];

        if ($messageId) {
            $data["message_id"] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
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
            $this->Alert("هیچ محصولی در این دسته بندی یافت نشد.");
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
        $navKeyboard[] = [['text' => '⬅️ بازگشت به دسته بندی ها', 'callback_data' => 'admin_product_list']];

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
        $text .= "📌 لطفاً مبلغ فوق را به کارت زیر واریز نمایید و سپس از طریق دکمه ی زیر، تصویر رسید پرداخت را برای ما ارسال کنید:\n\n";
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

    public function promptForParentCategory(?int $parentId = null, int $messageId = null): void
    {
        $allSuitableCategories = $this->db->getCategoriesWithNoProducts();

        $currentLevelCategories = [];
        if ($parentId === null) {
            $currentLevelCategories = $allSuitableCategories;
        } else {
            $findChildren = function ($categories, $pId) use (&$findChildren) {
                foreach ($categories as $category) {
                    if ($category['id'] == $pId) {
                        return $category['children'];
                    }
                    if (!empty($category['children'])) {
                        $found = $findChildren($category['children'], $pId);
                        if ($found !== null) return $found;
                    }
                }
                return null;
            };
            $currentLevelCategories = $findChildren($allSuitableCategories, $parentId);
        }

        $parentCategory = $parentId ? $this->db->getCategoryById($parentId) : null;
        $pathText = $parentId ? $this->db->getCategoryPath($parentId) : 'دسته بندی های اصلی';
        $text = "📍 <b>مسیر فعلی:</b> " . $pathText . "\n\nلطفاً یک دسته بندی را به عنوان والد انتخاب کنید:";


        $buttons = [];
        $row = [];
        foreach ($currentLevelCategories as $category) {
            $row[] = ['text' => $category['name'], 'callback_data' => 'select_parent_nav_' . $category['id']];
            if (count($row) >= 1) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        if ($parentCategory) {
            $buttons[] = [['text' => '✅ انتخاب همین دسته به عنوان والد', 'callback_data' => 'select_parent_confirm_' . $parentCategory['id']]];
        }

        $backCallback = ($parentCategory && $parentCategory['parent_id'] !== null)
            ? 'select_parent_nav_' . $parentCategory['parent_id']
            : 'admin_manage_categories';
        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => $backCallback]];

        $keyboard = ['inline_keyboard' => $buttons];
        $data = ['chat_id' => $this->chatId, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard)];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            $this->sendRequest("sendMessage", $data);
        }
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
        $text .= "کدام بخش را می خواهید ویرایش کنید؟";

        // Add discount button
        $discountButtonText = $product['discount_price'] ? '✏️ ویرایش/حذف تخفیف' : '🔥 ثبت تخفیف';

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ ویرایش نام', 'callback_data' => "edit_field_name_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش توضیحات', 'callback_data' => "edit_field_description_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '✏️ ویرایش موجودی', 'callback_data' => "edit_field_stock_{$productId}_{$categoryId}_{$page}"],
                    ['text' => '✏️ ویرایش قیمت', 'callback_data' => "edit_field_price_{$productId}_{$categoryId}_{$page}"]
                ],
                [
                    ['text' => '🖼️ ویرایش عکس', 'callback_data' => "edit_field_imagefileid_{$productId}_{$categoryId}_{$page}"],
                    ['text' => $discountButtonText, 'callback_data' => "edit_field_discount_{$productId}_{$categoryId}_{$page}"]
                ],
                [['text' => '✅ تایید و بازگشت', 'callback_data' => "confirm_product_edit_{$productId}_cat_{$categoryId}_page_{$page}"]],
            ]
        ];

        $method = !empty($product['images']) && count($product['images']) > 0 ? "editMessageCaption" : "editMessageText";
        $textOrCaptionKey = !empty($product['images']) && count($product['images']) > 0 ? "caption" : "text";


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
        $cartItems = $this->db->getUserCart($this->chatId);
        if (empty($cartItems)) {
            $this->Alert("سبد خرید شما خالی است.");
            return;
        }

        $tempEditCart = [];
        foreach ($cartItems as $item) {
            $tempEditCart[$item['cart_item_id']] = $item['quantity'];
        }
        $this->fileHandler->addData($this->chatId, ['edit_cart_state' => $tempEditCart]);

        $groupedCart = [];
        foreach ($cartItems as $item) {
            $groupedCart[$item['product_id']][] = $item;
        }

        $guideMessageRes = $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => "✏️ *حالت ویرایش سبد خرید*\n\nتعداد هر آیتم را به دلخواه تغییر دهید.",
            'parse_mode' => 'Markdown'
        ]);

        $newMessageIds = [$guideMessageRes['result']['message_id'] ?? null];

        foreach ($groupedCart as $productId => $items) {
            $res = $this->sendEditableCartCard($productId);
            if (isset($res['result']['message_id'])) {
                $newMessageIds[] = $res['result']['message_id'];
            }
        }

        $finalButtons = [
            ['text' => '✅ تایید نهایی تمام تغییرات', 'callback_data' => 'edit_cart_confirm_all'],
            ['text' => '❌ انصراف و بازگشت', 'callback_data' => 'edit_cart_cancel_all']
        ];
        $this->sendRequest("sendMessage", [
            'chat_id' => $this->chatId,
            'text' => "پس از اتمام ویرایش، وضعیت نهایی را مشخص کنید:",
            'reply_markup' => ['inline_keyboard' => [$finalButtons]]
        ]);

        $this->fileHandler->addData($this->chatId, ['message_ids' => array_filter($newMessageIds)]);
    }


    // فایل: classes/BotHandler.php

    private function sendEditableCartCard(int $productId, ?int $messageId = null)
    {
        $product = $this->db->getProductById($productId);
        if (!$product) return null;

        $tempEditCart = $this->fileHandler->getData($this->chatId, 'edit_cart_state') ?? [];
        $userCart = $this->db->getUserCart($this->chatId);

        // 1. یک لیست کامل از تمام آیتم‌های ممکن ایجاد می‌کنیم
        $itemsToDisplay = [];
        $processedVariants = [];

        // آیتم‌های موجود در سبد را اضافه می‌کنیم
        foreach ($userCart as $item) {
            if ($item['product_id'] == $productId) {
                $variantId = $item['variant_id'] ?? 0;
                $itemsToDisplay[] = $item;
                $processedVariants[$variantId] = true;
            }
        }

        // ویژگی‌هایی که در سبد نیستند را به عنوان آیتم جدید اضافه می‌کنیم
        if (!empty($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                if (!isset($processedVariants[$variant['id']])) {
                    $itemsToDisplay[] = [
                        'cart_item_id' => 'new_' . $variant['id'],
                        'product_id' => $productId,
                        'variant_id' => $variant['id'],
                        'variant_name' => $variant['variant_name'],
                        'price' => $variant['price'] // قیمت ویژگی را اضافه می‌کنیم
                    ];
                }
            }
        }

        // اگر محصول ساده (بدون ویژگی) باشد و در سبد نباشد
        if (empty($product['variants']) && empty($itemsToDisplay)) {
            $itemsToDisplay[] = [
                'cart_item_id' => $product['id'], // از آیدی محصول به عنوان شناسه استفاده می‌کنیم
                'product_id' => $productId,
                'variant_id' => null,
                'variant_name' => null,
                'price' => $product['price']
            ];
        }


        // 2. ساخت متن و دکمه‌ها بر اساس لیست کامل
        $text = "🛍 <b>" . htmlspecialchars($product['name']) . "</b>\n";
        $text .= "------------------------------------\n";
        $buttons = [];
        $totalCardPrice = 0;

        foreach ($itemsToDisplay as $item) {
            $identifier = $item['cart_item_id'];
            $quantity = $tempEditCart[$identifier] ?? 0;
            $price = (float)($item['price'] ?? 0);
            $itemTotalPrice = $quantity * $price;
            $totalCardPrice += $itemTotalPrice;

            $itemName = !empty($item['variant_name']) ? htmlspecialchars($item['variant_name']) : "قیمت واحد:";
            $itemPriceFormatted = number_format($price) . " تومان";

            $decreaseCallback = ($quantity > 0) ? "edit_cart_item_dec:{$identifier}:{$productId}" : 'nope';
            $increaseCallback = "edit_cart_item_inc:{$identifier}:{$productId}";

            $buttons[] = [['text' => "{$itemName} - {$itemPriceFormatted}", 'callback_data' => 'nope']];
            $buttons[] = [
                ['text' => '➕', 'callback_data' => $increaseCallback],
                ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                ['text' => '➖', 'callback_data' => $decreaseCallback]
            ];
        }

        $text .= "لطفاً تعداد هر آیتم را مشخص کنید:\n\n";
        if ($totalCardPrice > 0) {
            $text .= "💰 <b>جمع کل این محصول: " . number_format($totalCardPrice) . " تومان</b>";
        }


        $keyboard = ['inline_keyboard' => $buttons];

        // 3. ارسال یا ویرایش پیام
        if ($messageId) {
            $this->editTextOrCaption($this->chatId, $messageId, $text, $keyboard);
            return ['result' => ['message_id' => $messageId]];
        }

        $requestData = ['chat_id' => $this->chatId, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard];
        if (!empty($product['images'])) {
            $requestData['photo'] = $product['images'][0];
            $requestData['caption'] = $text;
            return $this->sendRequest("sendPhoto", $requestData);
        } else {
            $requestData['text'] = $text;
            return $this->sendRequest("sendMessage", $requestData);
        }
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
        $text = "🧾 بخش مدیریت فاکتورها.\n\nلطفاً وضعیت فاکتورهایی که می خواهید مشاهده کنید را انتخاب نمایید:";
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

    public function showCategoryList(?int $parentId = null, ?int $messageId = null): void
    {
        // گرفتن دسته‌ها
        $categories = ($parentId === null)
            ? $this->db->getRootCategories()
            : $this->db->getSubcategories($parentId);

        $parentCategory = $parentId ? $this->db->getCategoryById($parentId) : null;
        $pathText = $parentId ? $this->db->getCategoryPath($parentId) : '🏠 دسته‌بندی‌های اصلی';

        // متن نمایش
        $text  = "✨ <b>مدیریت دسته‌بندی‌ها</b>\n";
        $text .= "📍 مسیر: <b>" . htmlspecialchars($pathText) . "</b>\n\n";
        $text .= "📂 یکی از دسته‌های زیر رو انتخاب کن تا زیرشاخه‌هاش رو ببینی یا مدیریت کنی:";

        $buttons = [];

        foreach ($categories as $category) {
            $depth = $this->db->getCategoryDepth($category['id']);
            $icon = $this->getCategoryIcon($depth);

            $buttons[] = [
                ['text' => $icon . ' ' . htmlspecialchars($category['name']), 'callback_data' => 'admin_list_subcategories_' . $category['id']],
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'admin_cat_actions_' . $category['id']]
            ];
        }

        // دکمه بازگشت
        if ($parentCategory) {
            if ($parentCategory['parent_id'] !== null) {
                $backCallback = 'admin_list_subcategories_' . $parentCategory['parent_id'];
                $buttons[] = [['text' => '🔙 بازگشت به «' . htmlspecialchars($parentCategory['name']) . '»', 'callback_data' => $backCallback]];
            } else {
                $buttons[] = [['text' => '🏠 بازگشت به دسته‌های اصلی', 'callback_data' => 'admin_category_list_root']];
            }
        }

        // دکمه بازگشت به مدیریت
        $buttons[] = [['text' => '⬅️ بازگشت به مدیریت', 'callback_data' => 'admin_manage_categories']];

        $keyboard = ['inline_keyboard' => $buttons];

        $data = [
            'chat_id'      => $this->chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ];

        // اگر پیام قبلی وجود داشت ویرایش می‌کنیم
        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest("editMessageText", $data);
        } else {
            // پاک‌سازی پیام‌های قدیمی
            $previousMessageIds = $this->fileHandler->getMessageIds($this->chatId);
            if (!empty($previousMessageIds)) {
                $this->deleteMessages($previousMessageIds);
            }
            $res = $this->sendRequest("sendMessage", $data);
            if (isset($res['result']['message_id'])) {
                $this->fileHandler->addData($this->chatId, ['message_ids' => [$res['result']['message_id']]]);
            }
        }
    }

    private function getCategoryIcon(int $depth): string
    {
        switch ($depth) {
            case 0:
                return '📂'; // ریشه
            case 1:
                return '📁'; // زیردسته
            case 2:
                return '🗂️'; // زیرزیر
            default:
                return '🗃️'; // سطح‌های پایین‌تر
        }
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
        $text = "بخش مدیریت دسته بندی ها. لطفاً یک گزینه را انتخاب کنید:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن دسته بندی جدید', 'callback_data' => 'admin_add_category']],
                [['text' => '➕ افزودن زیردسته', 'callback_data' => 'admin_add_subcategory_select_parent']],
                [['text' => '📜 لیست دسته بندی ها', 'callback_data' => 'admin_category_list']],
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
    public function promptForParentCategorySelection($messageId): void
    {
        $allCategories = $this->db->getAllCategories();

        if (empty($allCategories)) {
            $this->Alert("ابتدا باید حداقل یک دسته بندی اصلی ایجاد کنید!");
            $this->showCategoryManagementMenu($messageId);
            return;
        }

        $buttons = [];

        $generateButtons = function ($categories, $level = 0) use (&$generateButtons, &$buttons) {
            foreach ($categories as $category) {
                $prefix = str_repeat('— ', $level);
                $buttons[] = [['text' => $prefix . $category['name'], 'callback_data' => 'select_parent_category_' . $category['id']]];
                if (!empty($category['children'])) {
                    $generateButtons($category['children'], $level + 1);
                }
            }
        };

        $generateButtons($allCategories);
        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'admin_manage_categories']];

        $keyboard = ['inline_keyboard' => $buttons];
        $text = "لطفاً برای زیردسته جدید، یک دسته بندی والد انتخاب کنید:";

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    private function refreshCartItemCard(int $cartItemId, int $messageId): void
    {
        // با استفاده از cartItemId، تمام اطلاعات مورد نیاز آیتم را از سبد خرید دریافت می‌کنیم
        $cartItems = $this->db->getUserCart($this->chatId);
        $item = null;
        foreach ($cartItems as $cartItem) {
            if ($cartItem['cart_item_id'] == $cartItemId) {
                $item = $cartItem;
                break;
            }
        }

        if (!$item) {
            $this->deleteMessage($messageId);
            $this->Alert("محصول دیگر در سبد شما نیست.", false);
            return;
        }

        $quantity = $item['quantity'];

        if ($quantity <= 0) {
            $this->deleteMessage($messageId);
            $this->Alert("محصول از سبد شما حذف شد.", false);
            return;
        }

        $itemText = $this->generateProductCardText($item);

        $newKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕', 'callback_data' => "edit_cart_increase_{$cartItemId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => "edit_cart_decrease_{$cartItemId}"]
                ],
                [
                    ['text' => '🗑 حذف کامل از سبد', 'callback_data' => "edit_cart_remove_{$cartItemId}"]
                ]
            ]
        ];

        // منطق ویرایش پیام (عکس یا متن)
        if (!empty($item['image_file_id'])) {
            $this->sendRequest('editMessageCaption', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'caption' => $itemText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        } else {
            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $itemText,
                'parse_mode' => 'HTML',
                'reply_markup' => $newKeyboard
            ]);
        }
    }
    public function promptForDeleteConfirmation(int $categoryId, int $messageId): void
    {
        $category = $this->db->getCategoryById($categoryId);
        if (!$category) {
            $this->Alert("دسته بندی یافت نشد.");
            return;
        }

        $summary = $this->db->getCategoryContentSummary($categoryId);
        $productCount = $summary['products'];
        $subcategoryCount = $summary['subcategories'];
        $categoryName = htmlspecialchars($category['name']);

        $warningText = "❓ آیا از حذف دسته بندی \"<b>{$categoryName}</b>\" مطمئن هستید؟\n\n";
        $keyboard = [];

        if ($productCount > 0) {
            $warningText .= "🔴 <b>هشدار:</b> این دسته بندی شامل <b>{$productCount} محصول</b> است و طبق قوانین پایگاه داده، قابل حذف نیست.\n\nابتدا باید تمام محصولات داخل آن را حذف کرده یا به دسته بندی دیگری منتقل کنید.";
            $keyboard = [['text' => '🔙 بازگشت', 'callback_data' => "cancel_edit_category_" . $categoryId]];
        } elseif ($subcategoryCount > 0) {
            $warningText .= "🟡 <b>توجه:</b> این دسته بندی محصولی ندارد، اما با حذف آن، <b>{$subcategoryCount} زیرشاخه</b> موجود در آن به دسته بندی های اصلی (سطح بالا) منتقل خواهند شد.";
            $keyboard = [
                ['text' => '✅ بله، حذف کن', 'callback_data' => 'confirm_delete_category_' . $categoryId],
                ['text' => '❌ انصراف', 'callback_data' => "cancel_edit_category_" . $categoryId]
            ];
        } else {
            $warningText .= "این یک دسته بندی خالی است و با خیال راحت می توانید آن را حذف کنید.";
            $keyboard = [
                ['text' => '✅ بله، حذف کن', 'callback_data' => 'confirm_delete_category_' . $categoryId],
                ['text' => '❌ انصراف', 'callback_data' => "cancel_edit_category_" . $categoryId]
            ];
        }

        $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => $warningText,
            "parse_mode" => "HTML",
            "reply_markup" => ['inline_keyboard' => [$keyboard]]
        ]);
    }
    public function reconstructCategoryMessage(int $categoryId, int $messageId): void
    {
        $category = $this->db->getCategoryById($categoryId);

        if (!$category) {
            $this->Alert("خطا: دسته‌بندی دیگر وجود ندارد.");
            $this->deleteMessage($messageId);
            return;
        }

        // ---------- اطلاعات پایه ----------
        $categoryName = htmlspecialchars($category['name']);
        $isActive = (bool)$category['is_active'];
        $toggleStatusText = $isActive ? '🔴 غیرفعال کردن' : '🟢 فعال کردن';

        // ---------- دکمه‌های مرتب‌سازی ----------
        $siblings = $this->db->getCategorySiblings($categoryId);
        $categoryIds = array_column($siblings, 'id');
        $currentIndex = array_search($categoryId, $categoryIds);

        $sortButtons = [];
        // دکمه‌ها فقط زمانی اضافه می‌شوند که بیش از یک آیتم برای مرتب‌سازی وجود داشته باشد
        if ($currentIndex !== false && count($siblings) > 1) {
            if ($currentIndex > 0) {
                $sortButtons[] = ['text' => '🔼بالا', 'callback_data' => 'move_category_up_' . $categoryId];
            }
            if ($currentIndex < count($siblings) - 1) {
                $sortButtons[] = ['text' => '🔽پایین', 'callback_data' => 'move_category_down_' . $categoryId];
            }
        }

        // ---------- دکمه‌های عملیاتی و بازگشت ----------
        $parentId = $category['parent_id'];
        $backCallback = $parentId !== null
            ? 'admin_list_subcategories_' . $parentId
            : 'admin_category_list_root'; // بازگشت به ریشه

        $keyboardRows = [];
        if (!empty($sortButtons)) {
            $keyboardRows[] = $sortButtons; // ردیف مرتب‌سازی
        }
        $summary = $this->db->getCategoryContentSummary($categoryId);
        if ($summary['products'] > 0) {

            $keyboardRows[] = [
                ['text' => '📦 مشاهده محصولات (' . $summary['products'] . ')', 'callback_data' => 'admin_view_category_products_' . $categoryId]
            ];
        }
        $keyboardRows[] = [
            ['text' => '✏️ ویرایش نام', 'callback_data' => 'admin_edit_category_' . $categoryId],
            ['text' => '🗑 حذف', 'callback_data' => 'admin_delete_category_' . $categoryId]
        ];
        $keyboardRows[] = [
            ['text' => $toggleStatusText, 'callback_data' => 'toggle_cat_status_' . $categoryId],
            ['text' => '🔄 جابجایی به والد دیگر', 'callback_data' => 'move_category_' . $categoryId]
        ];
        $keyboardRows[] = [
            ['text' => '⬅️ بازگشت به لیست', 'callback_data' => $backCallback]
        ];

        $keyboard = ['inline_keyboard' => $keyboardRows];

        // ---------- متن پیام ----------
        $path = $this->db->getCategoryPath($categoryId);
        $text  = "⚙️ <b>مدیریت دسته‌بندی</b>\n\n";
        $text .= "<b>مسیر:</b> " . htmlspecialchars($path) . "\n\n";
        $text .= "لطفاً عملیات مورد نظر را انتخاب کنید:";

        $this->sendRequest("editMessageText", [
            "chat_id"      => $this->chatId,
            "message_id"   => $messageId,
            "text"         => $text,
            "parse_mode"   => "HTML",
            "reply_markup" => $keyboard,
        ]);
    }

    public function showCategoryProductsForAdmin(int $categoryId, int $messageId): void
    {
        $category = $this->db->getCategoryById($categoryId);
        if (!$category) {
            $this->Alert("خطا: دسته‌بندی یافت نشد.");
            return;
        }

        $products = $this->db->getActiveProductsByCategoryId($categoryId);

        $text = "📦 لیست محصولات در دسته‌بندی: <b>" . htmlspecialchars($category['name']) . "</b>\n\n";

        if (empty($products)) {
            $text .= "<i>هیچ محصولی در این دسته‌بندی وجود ندارد.</i>";
        } else {
            foreach ($products as $product) {
                $price = number_format($product['price']);
                $stock = $product['stock'];
                $text .= "- <b>" . htmlspecialchars($product['name']) . "</b>";
                $text .= "  <blockquote>موجودی: {$stock} عدد | قیمت: {$price} تومان</blockquote>\n\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ بازگشت به مدیریت دسته', 'callback_data' => 'admin_cat_actions_' . $categoryId]]
            ]
        ];

        $this->sendRequest("editMessageText", [
            "chat_id" => $this->chatId,
            "message_id" => $messageId,
            "text" => $text,
            "parse_mode" => "HTML",
            "reply_markup" => $keyboard
        ]);
    }
    public function showAboutUs(): void
    {

        $text = "🤖 *درباره توسعه دهنده ربات*\n\n";
        $text .= "این ربات یک *نمونه کار حرفه ای* در زمینه طراحی و توسعه ربات های فروشگاهی در تلگرام است که توسط *امیر سلیمانی* طراحی و برنامه نویسی شده است.\n\n";
        $text .= "✨ *ویژگی های برجسته ربات:*\n";
        $text .= "🔹 پنل مدیریت کامل از داخل تلگرام (افزودن، ویرایش، حذف محصول)\n";
        $text .= "🗂️ مدیریت هوشمند دسته بندی محصولات\n";
        $text .= "🛒 سیستم سبد خرید و لیست علاقه مندی ها\n";
        $text .= "🔍 جستجوی پیشرفته با سرعت بالا (Inline Mode)\n";
        $text .= "💳 اتصال امن به درگاه پرداخت\n\n";
        $text .= "💼 *آیا برای کسب وکار خود به یک ربات تلگرامی نیاز دارید؟*\n";
        $text .= "ما آماده ایم تا ایده های شما را به یک ربات کاربردی و حرفه ای تبدیل کنیم.\n\n";
        $text .= "📞 *راه ارتباط با توسعه دهنده:* [@Amir_soleimani_79](https://t.me/Amir_soleimani_79)";

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
        $category = $this->db->getCategoryById($categoryId);
        if (!$category) {
            $this->Alert("دسته‌بندی یافت نشد.");
            return;
        }

        $allProducts = $this->db->getActiveProductsByCategoryId($categoryId);

        if (empty($allProducts)) {
            $this->Alert("متاسفانه محصولی در این دسته‌بندی یافت نشد.");
            // در صورت خالی بودن، به منوی والد یا اصلی باز می‌گردیم
            $backCallback = $category['parent_id']
                ? 'category_' . $category['parent_id']
                : 'main_menu';
            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "هیچ محصولی یافت نشد.",
                "reply_markup" => ['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => $backCallback]]]]
            ]);
            return;
        }

        $perPage = 8; // تعداد محصولات بیشتر در هر صفحه
        $totalPages = ceil(count($allProducts) / $perPage);
        $offset = ($page - 1) * $perPage;
        $productsOnPage = array_slice($allProducts, $offset, $perPage);

        $text = "محصولات دسته‌بندی: <b>" . htmlspecialchars($category['name']) . "</b>\n";
        $text .= "صفحه {$page} از {$totalPages}\n\n";
        $text .= "لطفاً محصول مورد نظر خود را انتخاب کنید:";

        $buttons = [];
        $row = [];
        foreach ($productsOnPage as $product) {
            // دکمه‌ای برای مشاهده کارت هر محصول با اطلاعات صفحه‌بندی
            $callbackData = 'user_view_product_' . $product['id'] . '_cat_' . $categoryId . '_page_' . $page;
            $row[] = ['text' => htmlspecialchars($product['name']), 'callback_data' => $callbackData];
            if (count($row) >= 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => "◀️ قبل", 'callback_data' => "user_list_products_cat_{$categoryId}_page_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => "بعد ▶️", 'callback_data' => "user_list_products_cat_{$categoryId}_page_" . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        // دکمه بازگشت به دسته‌بندی والد یا منوی اصلی
        $backCallback = $category['parent_id'] !== null
            ? 'category_' . $category['parent_id']
            : 'main_menu';
        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => $backCallback]];

        $keyboard = ['inline_keyboard' => $buttons];

        // اگر messageId وجود داشت، پیام را ویرایش می‌کنیم، در غیر این صورت یک پیام جدید ارسال می‌شود
        if ($messageId) {
            $this->sendRequest("editMessageText", [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $this->sendRequest("sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }
    }

    public function showSubcategoryMenu(int $parentId, int $messageId): void
    {
        $parentCategory = $this->db->getCategoryById($parentId);
        $subcategories = $this->db->getSubcategories($parentId);

        if (!$parentCategory) {
            $this->Alert("دسته بندی یافت نشد.");
            return;
        }

        $text = "✨ دسته فعلی: «<b>" . htmlspecialchars($parentCategory['name']) . "</b>» \n👇 از بین زیرشاخه های زیر یکی رو انتخاب کن:";

        $buttons = [];
        $row = [];
        foreach ($subcategories as $subcategory) {
            if ($subcategory['is_active']) {
                $row[] = ['text' => $subcategory['name'], 'callback_data' => 'category_' . $subcategory['id']];
                if (count($row) >= 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        if ($parentCategory['parent_id'] !== null) {
            $buttons[] = [['text' => '⬅️ بازگشت به دسته بندی قبلی', 'callback_data' => 'category_' . $parentCategory['parent_id']]];
        } else {
            $buttons[] = [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];
        }


        $keyboard = ['inline_keyboard' => $buttons];

        $this->sendRequest("editMessageText", [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    public function publishProductToChannel(int $productId): void
    {
        $channelId = $this->db->getSettingValue('channel_id');
        if (empty($channelId) || !str_starts_with($channelId, '@')) {
            Logger::log('warning', 'Channel Publishing Skipped', 'Channel ID is not set or invalid.', ['product_id' => $productId]);
            $this->Alert("محصول ذخیره شد اما به دلیل عدم تنظیم صحیح کانال، در آن منتشر نشد.", true);
            return;
        }

        $product = $this->db->getProductById($productId);
        if (!$product) {
            Logger::log('error', 'Channel Publishing Failed', 'Product not found.', ['product_id' => $productId]);
            return;
        }

        $caption = $this->generateProductCardText($product);
        $caption = mb_substr($caption, 0, 1000); // محدودیت کپشن

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🛒 مشاهده و خرید محصول', 'url' => $this->botLink . '?start=product_' . $productId]]
            ]
        ];

        if (!empty($product['images'])) {
            $mainImageFileId = $product['images'][0];

            $messageSent = $this->sendRequest('sendPhoto', [
                'chat_id'      => $channelId,
                'photo'        => $mainImageFileId,
                'caption'      => $caption,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $messageSent = $this->sendRequest('sendMessage', [
                'chat_id'      => $channelId,
                'text'         => $caption,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }

        if ($messageSent && isset($messageSent['result']['message_id'])) {
            $channelMessageId = $messageSent['result']['message_id'];
            $this->db->updateChannelMessageId($productId, $channelMessageId);
            $this->Alert("✅ محصول با موفقیت در کانال منتشر شد.", false);
        } else {
            $this->Alert("❌ محصول ذخیره شد اما در انتشار آن در کانال خطایی رخ داد.", true);
            Logger::log('error', 'Channel Publishing Failed', 'Telegram API call failed.', ['product_id' => $productId, 'response' => $messageSent]);
        }
    }

    public function showProductImages(int $productId): void
    {
        $product = $this->db->getProductById($productId);
        if (!$product || empty($product['images'])) {
            $this->Alert("این محصول تصویری برای نمایش ندارد.");
            return;
        }

        $mediaGroup = [];
        foreach ($product['images'] as $fileId) {
            $mediaGroup[] = ['type' => 'photo', 'media' => $fileId];
        }

        $mediaGroupResponse = $this->sendRequest('sendMediaGroup', ['chat_id' => $this->chatId, 'media' => json_encode($mediaGroup)]);
        $navMessageResponse = $this->sendRequest('sendMessage', [
            'chat_id' => $this->chatId,
            'text' => '📸 گالری تصاویر محصول "' . htmlspecialchars($product['name']) . '"',
            'reply_markup' => ['inline_keyboard' => [[['text' => '🔙 بازگشت به محصول', 'callback_data' => 'view_product_' . $productId]]]]
        ]);

        $newMessageIds = [];
        if (isset($mediaGroupResponse['ok']) && $mediaGroupResponse['ok'] === true) {
            $newMessageIds = array_column($mediaGroupResponse['result'], 'message_id');
        }
        if (isset($navMessageResponse['result']['message_id'])) {
            $newMessageIds[] = $navMessageResponse['result']['message_id'];
        }

        if (!empty($newMessageIds)) {
            $this->fileHandler->addMessageId($this->chatId, $newMessageIds);
        }
    }




    public function promptQuantityManager(int $productId, ?int $messageId = null): void
    {
        $product = $this->db->getProductById($productId);
        if (!$product) {
            $this->Alert("محصول یافت نشد.");
            return;
        }

        $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
        $tempCart = $stateData['temp_quantity_cart'] ?? [];
        $hasVariants = !empty($product['variants']);

        $text = "لطفاً تعداد مورد نظر را مشخص کرده و در پایان تایید کنید:";
        $buttons = [];
        $totalPrice = 0;
        $totalItems = 0;

        if ($hasVariants) {
            // --- منطق برای محصولات دارای ویژگی ---
            foreach ($product['variants'] as $variant) {
                $variantId = $variant['id'];
                $quantity = $tempCart[$variantId] ?? 0;
                $totalPrice += $quantity * (float)$variant['price'];
                $totalItems += $quantity;

                $buttons[] = [['text' => "{$variant['variant_name']} - " . number_format($variant['price']) . " تومان", 'callback_data' => 'nope']];
                if ((int)$variant['stock'] > 0) {
                    // *** اصلاح کلیدی: غیرفعال کردن دکمه منفی وقتی تعداد صفر است ***
                    $decreaseCallback = ($quantity > 0) ? "quantity_adjust_dec_{$variantId}_{$productId}" : 'nope';

                    $buttons[] = [
                        ['text' => '➕', 'callback_data' => "quantity_adjust_inc_{$variantId}_{$productId}"],
                        ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                        ['text' => '➖', 'callback_data' => $decreaseCallback]
                    ];
                }
            }
        } else {
            // --- منطق برای محصولات ساده ---
            $simpleProductId = 0;
            $quantity = $tempCart[$simpleProductId] ?? 0;
            $totalPrice += $quantity * (float)$product['price'];
            $totalItems += $quantity;

            $buttons[] = [['text' => htmlspecialchars($product['name']), 'callback_data' => 'nope']];
            if ((int)$product['stock'] > 0) {
                // *** اصلاح کلیدی: غیرفعال کردن دکمه منفی وقتی تعداد صفر است ***
                $decreaseCallback = ($quantity > 0) ? "quantity_adjust_dec_{$simpleProductId}_{$productId}" : 'nope';

                $buttons[] = [
                    ['text' => '➕', 'callback_data' => "quantity_adjust_inc_{$simpleProductId}_{$productId}"],
                    ['text' => "{$quantity} عدد", 'callback_data' => 'nope'],
                    ['text' => '➖', 'callback_data' => $decreaseCallback]
                ];
            }
        }

        if ($totalItems > 0) {
            $text .= "\n\n<b>مجموع انتخاب شما:</b>\n- تعداد کل: {$totalItems} عدد\n- مبلغ کل: " . number_format($totalPrice) . " تومان";
            $buttons[] = [['text' => '✅ تایید و افزودن به سبد', 'callback_data' => 'quantity_confirm_' . $productId]];
        }

        $buttons[] = [['text' => '🔙 بازگشت به محصول (بدون ذخیره)', 'callback_data' => 'quantity_manager_back_' . $productId]];

        $newKeyboard = ['inline_keyboard' => $buttons];

        if ($messageId) {
            $this->editTextOrCaption($this->chatId, $messageId, $text, $newKeyboard);
        } else {
            $hasImage = !empty($product['images']);
            $mainImageFileId = $hasImage ? $product['images'][0] : null;
            if ($hasImage) {
                $this->sendRequest("sendPhoto", ["chat_id" => $this->chatId, "photo" => $mainImageFileId, "caption" => $text, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            } else {
                $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => $text, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            }
        }
    }


    private function handleProductEditingSteps(): void
    {
        $state = $this->fileHandler->getState($this->chatId) ?? null;
        $stateData = json_decode($this->fileHandler->getStateData($this->chatId) ?? '{}', true);
        $this->deleteMessage($this->messageId); // پیام شما (مقدار جدید) را حذف می‌کند

        if (empty($stateData['product_id']) || empty($stateData['message_id'])) {
            $this->Alert("خطا: اطلاعات ویرایش منقضی شده است. لطفاً دوباره تلاش کنید.");
            $this->fileHandler->addData($this->chatId, ['state' => null, 'state_data' => null]);
            return;
        }

        $productId = $stateData['product_id'];
        $messageId = $stateData['message_id'];
        $categoryId = $stateData['category_id'];
        $page = $stateData['page'];
        $field = str_replace('editing_product_', '', $state);
        $success = false;
        $alertMessage = '';

        switch ($field) {
            case 'name':
                if ($newName = trim($this->text)) {
                    $success = $this->db->updateProductName($productId, $newName);
                    $alertMessage = "✅ نام محصول ویرایش شد.";
                }
                break;
            case 'description':
                if ($newDesc = trim($this->text)) {
                    $success = $this->db->updateProductDescription($productId, $newDesc);
                    $alertMessage = "✅ توضیحات ویرایش شد.";
                }
                break;
            case 'price':
                if (is_numeric($this->text) && ($newPrice = trim($this->text)) >= 0) {
                    $success = $this->db->updateProductPrice($productId, (float)$newPrice);
                    $alertMessage = "✅ قیمت ویرایش شد.";
                } else {
                    $this->Alert("⚠️ لطفاً یک قیمت معتبر (عدد) وارد کنید.");
                    return;
                }
                break;
            case 'stock':
                if (is_numeric($this->text) && ($newStock = trim($this->text)) >= 0) {
                    $success = $this->db->updateProductStock($productId, (int)$newStock);
                    $alertMessage = "✅ موجودی ویرایش شد.";
                } else {
                    $this->Alert("⚠️ لطفاً یک عدد معتبر برای موجودی وارد کنید.");
                    return;
                }
                break;
            case 'imagefileid':
                if (isset($this->message['photo'])) {
                    $fileId = end($this->message['photo'])['file_id'];
                    $success = $this->db->updateProductImage($productId, $fileId);
                    $alertMessage = "✅ عکس محصول ویرایش شد.";
                } elseif (trim($this->text) === '/remove') {
                    $success = $this->db->removeProductImage($productId);
                    $alertMessage = "✅ عکس محصول حذف شد.";
                } else {
                    $this->Alert("⚠️ لطفاً یک عکس ارسال کنید یا برای حذف /remove را بفرستید.");
                    return;
                }
                break;
        }

        if ($success) {
            $this->fileHandler->addData($this->chatId, ['state' => null, 'state_data' => null]);
            $this->Alert($alertMessage, false);
            // پس از ویرایش موفق، دوباره منوی ویرایش را نمایش می‌دهیم
            $this->showProductEditMenu($productId, $messageId, $categoryId, $page);
        } else {
            $this->Alert("❌ خطایی در به‌روزرسانی رخ داد. ورودی شما نامعتبر بود.");
        }
    }
    public function showUserSingleProductCard(int $productId, ?int $fromCategoryId = null, ?int $fromPage = null, ?int $messageId = null): void
    {
        // ذخیره اطلاعات بازگشت (زمینه) در فایل
        $returnContext = ['category_id' => $fromCategoryId, 'page' => $fromPage];
        $this->fileHandler->addData($this->chatId, ['product_view_context' => $returnContext]);

        $product = $this->db->getProductById($productId);
        if (!$product) {
            $this->Alert("محصول یافت نشد.", true);
            if ($messageId) $this->deleteMessage($messageId);
            return;
        }

        $quantityInCart = $this->db->getCartItemQuantity($this->chatId, $productId, null);
        $isFavorite = $this->db->isProductInFavorites($this->chatId, $productId);
        $totalStock = empty($product['variants']) ? (int)$product['stock'] : (int)array_sum(array_column($product['variants'], 'stock'));

        if ($quantityInCart > 0) {
            $product['quantity'] = $quantityInCart;
        }
        $productText = $this->generateProductCardText($product);

        $keyboardRows = [];
        $hasVariants = !empty($product['variants']);

        // ردیف اول: علاقه‌مندی و گالری
        $mainActionsRow = [];
        $favoriteButtonText = $isFavorite ? '❤️ حذف از علاقه‌مندی' : '🤍 افزودن به علاقه‌مندی';
        $mainActionsRow[] = ['text' => $favoriteButtonText, 'callback_data' => 'toggle_favorite_' . $productId];
        if (isset($product['images']) && count($product['images']) > 1) {
            $mainActionsRow[] = ['text' => '🖼 مشاهده تصاویر', 'callback_data' => 'view_product_images_' . $productId];
        }
        $keyboardRows[] = $mainActionsRow;

        $cartButtonsRow = $this->generateCartActionButtons($product, $quantityInCart, $totalStock);
        if ($cartButtonsRow) {
            $keyboardRows[] = $cartButtonsRow;
        }

        // ردیف سوم: دکمه بازگشت
        if ($fromCategoryId !== null && $fromPage !== null) {
            $keyboardRows[] = [['text' => '⬅️ بازگشت به لیست محصولات', 'callback_data' => "user_list_products_cat_{$fromCategoryId}_page_{$fromPage}"]];
        } elseif ($messageId === null) {
            $keyboardRows[] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'main_menu2']];
        }

        $newKeyboard = ['inline_keyboard' => $keyboardRows];

        // --- منطق ارسال/ویرایش پیام ---
        if ($messageId) {
            $this->editTextOrCaption($this->chatId, $messageId, $productText, $newKeyboard);
        } else {
            $hasImage = !empty($product['images']);
            $mainImageFileId = $hasImage ? $product['images'][0] : null;
            if ($hasImage) {
                $this->sendRequest("sendPhoto", ["chat_id" => $this->chatId, "photo" => $mainImageFileId, "caption" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            } else {
                $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => $productText, "parse_mode" => "HTML", "reply_markup" => $newKeyboard]);
            }
        }
    }

    // File: BotHandler.php

    private function generateCartActionButtons(array $product, int $quantityInCart, int $totalStock): ?array
    {
        $productId = $product['id'];
        $callback = 'open_quantity_manager_' . $productId;

        if ($quantityInCart > 0) {
            return [['text' => "🛒 ویرایش تعداد ({$quantityInCart} عدد)", 'callback_data' => $callback]];
        }
        if ($totalStock > 0) {
            return [['text' => '🛒 افزودن به سبد خرید', 'callback_data' => $callback]];
        }

        return null;
    }
    private function editTextOrCaption(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        $params = [
            "chat_id"    => $chatId,
            "message_id" => $messageId,
            "caption"    => $text,
            "parse_mode" => "HTML"
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $response = $this->sendRequest("editMessageCaption", $params);

        if (isset($response['ok']) && !$response['ok']) {
            unset($params['caption']);
            $params['text'] = $text;
            $this->sendRequest("editMessageText", $params);
        }
    }
}
