<?php
// فایل autoload برای استفاده از کتابخانه‌ها
require_once __DIR__ . '/../vendor/autoload.php';

function get_bot_info(string $filePath): ?array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $bot_link = '#';
    $store_name = basename($filePath, '.env'); // Fallback name
    $bot_logo = "../assets/images/default.jpg"; // پیش‌فرض

    if (preg_match('/^BOT_LINK=(.*)$/m', $content, $matches)) {
        $bot_link = trim($matches[1]);
    }
    if (preg_match('/^STORE_NAME=(.*)$/m', $content, $matches)) {
        $store_name = trim($matches[1]);
    } elseif (preg_match('/^BOT_NAME=(.*)$/m', $content, $matches)) {
        $store_name = trim($matches[1]);
    }
    if (preg_match('/^BOT_LOGO=(.*)$/m', $content, $matches)) {
        $bot_logo = trim($matches[1]);
    }

    return [
        'link' => $bot_link,
        'name' => $store_name,
        'logo' => $bot_logo,
    ];
}

$bots = [];
$configPath = __DIR__ . '/../config/';
$envFiles = glob($configPath . '*.env');

// master.env shouldn't be listed as a bot
$masterEnvFile = $configPath . 'master.env';
if (($key = array_search($masterEnvFile, $envFiles)) !== false) {
    unset($envFiles[$key]);
}

foreach ($envFiles as $file) {
    $info = get_bot_info($file);
    if ($info) {
        $bots[] = $info;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لیست ربات‌های فروشگاهی</title>
    <style>
        body {
            background: #0d1117;
            font-family: 'Vazirmatn', sans-serif;
            margin: 0;
            padding: 20px;
            color: #e6edf3;
        }

        .container {
            max-width: 1000px;
            margin: 50px auto;
        }

        h1 {
            font-size: 2.6em;
            margin-bottom: 30px;
            text-align: center;
            color: #f0f6fc;
        }

        .bot-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
        }

        .bot-card {
            background: #161b22;
            border-radius: 16px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.6);
            padding: 30px 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .bot-card img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 4px solid #222;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.6);
        }

        .bot-card h2 {
            font-size: 1.8em;
            margin-bottom: 10px;
            color: #f0f6fc;
        }

        .bot-card p {
            font-size: 1em;
            color: #9ca3af;
            margin-bottom: 20px;
        }

        .btn-primary {
            display: inline-block;
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: #fff;
            padding: 12px 30px;
            font-size: 1em;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #0056b3, #003a75);
            transform: translateY(-3px);
        }

        .project-about {
            margin-top: 20px;
            padding: 30px;
            border-radius: 12px;
            background: #161b22;
            color: #e6edf3;
            line-height: 1.9;
            text-align: right;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.5);
        }

        .project-about h1,
        .project-about h3,
        .project-about h4 {
            color: #c9d1d9;
            margin-bottom: 15px;
        }

        .project-about ul {
            margin: 10px 0 20px 0;
            padding-right: 25px;
        }

        .project-about ul li {
            margin-bottom: 10px;
            color: #9ca3af;
        }

        .about-me {
            margin-top: 80px;
            padding-top: 30px;
            border-top: 1px solid #30363d;
            text-align: center;
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
        }

        .about-me a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
</head>

<body>

    <div class="container">

        <!-- معرفی پروژه -->
        <div class="project-about">
            <h1>🤖 درباره این پروژه</h1>
            <p>
                این ربات فروشگاهی، یک نمونه کار پیشرفته و جامع است که برای نمایش قدرت و انعطاف‌پذیری ربات‌های تلگرامی در حوزه تجارت الکترونیک، توسط <strong>امیر سلیمانی</strong> طراحی و توسعه داده شده است. این پروژه تنها یک ربات ساده نیست، بلکه یک پلتفرم کامل با معماری چند-رباتی (Multi-Bot) است که امکان مدیریت همزمان چندین فروشگاه را فراهم می‌کند.
            </p>

            <h3>✨ برخی از ویژگی‌های برجسته:</h3>
            <h4>برای کاربران:</h4>
            <ul>
                <li>🛍 تجربه خرید آسان: مشاهده دسته‌بندی‌ها، محصولات و افزودن به سبد خرید به سادگی.</li>
                <li>🛒 سبد خرید هوشمند: امکان ویرایش تعداد محصولات و نهایی کردن خرید در هر زمان.</li>
                <li>❤️ لیست علاقه‌مندی‌ها: ذخیره محصولات مورد علاقه برای دسترسی سریع‌تر در آینده.</li>
                <li>🔍 جستجوی آنی (Inline): پیدا کردن سریع محصولات در هر صفحه چت.</li>
                <li>📜 پیگیری سفارشات: مشاهده تاریخچه و وضعیت سفارش‌های ثبت‌شده.</li>
                <li>📸 پرداخت امن: فرآیند پرداخت از طریق کارت به کارت و ارسال رسید.</li>
            </ul>

            <h4>برای مدیران:</h4>
            <ul>
                <li>⚙️ پنل مدیریت کامل در تلگرام: مدیریت همه‌چیز از جمله محصولات، دسته‌بندی‌ها، سفارش‌ها و تنظیمات فروشگاه بدون نیاز به خروج از تلگرام.</li>
                <li>📊 داشبورد آمار تحت وب: مشاهده آمار فروش، کاربران جدید و درآمد روزانه در یک پنل وب زیبا و واکنش‌گرا.</li>
                <li>🔔 اطلاع‌رسانی آنی: دریافت نوتیفیکیشن فوری برای سفارش‌های جدید و رسیدهای پرداخت.</li>
                <li>🏢 معماری چند-رباتی: قابلیت تعریف و مدیریت چندین ربات فروشگاهی مجزا از طریق یک پنل ادمین مرکزی (Super Admin).</li>
            </ul>
        </div>

        <!-- لیست ربات‌ها -->
        <h1 style="margin-top:60px;">🤖 لیست ربات‌های فعال</h1>

        <?php if (empty($bots)): ?>
            <p style="text-align:center;">در حال حاضر ربات فعالی برای نمایش وجود ندارد.</p>
        <?php else: ?>
            <div class="bot-list">
                <?php foreach ($bots as $bot): ?>
                    <div class="bot-card">
                        <img src="<?= htmlspecialchars($bot['logo']); ?>" alt="لوگوی ربات">
                        <h2><?= htmlspecialchars($bot['name']); ?></h2>
                        <p>خرید آسان، امن و سریع محصولات فقط با چند کلیک! 🚀</p>
                        <a href="<?= htmlspecialchars($bot['link']); ?>" target="_blank" class="btn-primary">شروع خرید</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- درباره توسعه‌دهنده -->
        <div class="about-me">
            <h2>👨‍💻 درباره توسعه‌دهنده</h2>
            <p>
                این پروژه توسط <strong>امیر سلیمانی</strong> به عنوان یک نمونه کار حرفه‌ای طراحی و توسعه داده شده است.
                <br>
                اگر برای کسب‌وکار خود به یک ربات سفارشی و قدرتمند نیاز دارید، خوشحال می‌شوم با شما همکاری کنم.
            </p>
            <a href="https://t.me/Amir_soleimani_79" target="_blank">ارتباط با من در تلگرام</a>
        </div>

    </div>

</body>

</html>