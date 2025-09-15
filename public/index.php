<?php
// فایل autoload برای استفاده از کتابخانه‌ها
require_once __DIR__ . '/../vendor/autoload.php';

// استفاده از AppConfig برای خواندن تنظیمات
use Config\AppConfig;

// بارگذاری تنظیمات از فایل .env
AppConfig::get();

// خواندن مقادیر مورد نیاز از تنظیمات
$bot_link   = AppConfig::get('bot.bot_link', '#');
$store_name = AppConfig::get('bot.store_name', 'فروشگاه تلگرامی ما');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معرفی ربات فروشگاهی</title>
    <style>
        /* 🌙 دارک مدرن */
        body {
            background: #0d1117;
            font-family: 'Vazirmatn', sans-serif;
            margin: 0;
            padding: 0;
            color: #e6edf3;
        }

        .container {
            max-width: 900px;
            margin: 50px auto;
            background: #161b22;
            border-radius: 16px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.6);
            padding: 50px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: "";
            position: absolute;
            top: -100px;
            left: -100px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(0, 123, 255, 0.15), transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }

        .container::after {
            content: "";
            position: absolute;
            bottom: -120px;
            right: -120px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 0, 123, 0.1), transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }

        .container>* {
            position: relative;
            z-index: 1;
        }

        .logo {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
            border: 4px solid #222;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.6);
        }

        h1 {
            font-size: 2.6em;
            margin-bottom: 10px;
            color: #f0f6fc;
        }

        .lead {
            font-size: 1.2em;
            color: #9ca3af;
            margin-bottom: 25px;
        }

        .btn-primary {
            display: inline-block;
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: #fff;
            padding: 14px 36px;
            font-size: 1.1em;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #0056b3, #003a75);
            transform: translateY(-3px);
        }

        .features {
            text-align: right;
            margin: 50px 0;
        }

        .features h2 {
            text-align: center;
            font-size: 2em;
            margin-bottom: 30px;
            color: #c9d1d9;
        }

        .features ul {
            list-style: none;
            padding: 0;
        }

        .features li {
            background: #1c2128;
            margin-bottom: 15px;
            padding: 18px 20px;
            border-radius: 12px;
            border-right: 5px solid #007bff;
            font-size: 1.05em;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .features li:hover {
            transform: translateX(-6px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
        }

        .features li strong {
            color: #f0f6fc;
        }

        .about-me {
            margin-top: 60px;
            padding-top: 30px;
            border-top: 1px solid #30363d;
        }

        .about-me h2 {
            font-size: 1.8em;
            margin-bottom: 15px;
            color: #c9d1d9;
        }

        .about-me p {
            font-size: 1.1em;
            color: #9ca3af;
            line-height: 1.9;
        }

        .about-me a {
            display: inline-block;
            margin-top: 15px;
            color: #58a6ff;
            font-weight: bold;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: border-color 0.2s;
        }

        .about-me a:hover {
            border-color: #58a6ff;
        }

        /* 📱 ریسپانسیو */
        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }

            h1 {
                font-size: 2em;
            }

            .features li {
                font-size: 1em;
                padding: 14px;
            }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
</head>

<body>

    <div class="container">
        <img src="../assets/images/baf09e6a160d7d7b9917759c23d34dfb.jpg" alt="لوگوی ربات" class="logo">
        <h1><?= htmlspecialchars($store_name); ?></h1>
        <p class="lead">خرید آسان، امن و سریع محصولات فقط با چند کلیک! 🚀</p>

        <a href="<?= htmlspecialchars($bot_link); ?>" target="_blank" class="btn-primary">شروع خرید از ربات</a>

        <div class="features">
            <h2>✨ امکانات ویژه ربات</h2>
            <ul>
                <li>🛍 <strong>مدیریت محصولات:</strong> افزودن، ویرایش و حذف محصولات از طریق پنل مدیریت.</li>
                <li>🛒 <strong>سبد خرید هوشمند:</strong> مدیریت تعداد و خرید یکپارچه.</li>
                <li>❤️ <strong>لیست علاقه‌مندی‌ها:</strong> ذخیره محصولات برای خریدهای بعدی.</li>
                <li>💳 <strong>پرداخت امن:</strong> کارت‌به‌کارت و ثبت رسید.</li>
                <li>📊 <strong>پنل مدیریت:</strong> مدیریت سفارشات و کاربران.</li>
                <li>🔍 <strong>جستجوی سریع:</strong> Inline Search برای محصولات.</li>
            </ul>
        </div>

        <div class="about-me">
            <h2>👨‍💻 درباره توسعه‌دهنده</h2>
            <p>
                این ربات توسط <strong>امیر سلیمانی</strong> به عنوان یک پروژه حرفه‌ای طراحی و توسعه داده شده است.
                <br>
                اگر برای کسب‌وکار خود به یک ربات سفارشی و قدرتمند نیاز دارید، خوشحال می‌شوم با شما همکاری کنم.
            </p>
            <a href="https://t.me/Amir_soleimani_79" target="_blank">ارتباط با من در تلگرام</a>
        </div>
    </div>

</body>

</html>