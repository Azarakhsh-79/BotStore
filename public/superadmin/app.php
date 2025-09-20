<?php
// ===================================================================
// public/superadmin/app.php - Main Application Interface
?>

<?php
session_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>پنل مدیریت کل</title>
  <link rel="stylesheet" href="/superadmin/styles.css">
</head>

<body>
  <div class="container">
    <header>
      <h1>پنل مدیریت کل</h1>
      <nav>
        <a href="#dashboard" id="nav-dashboard" class="active">داشبورد</a>
        <a href="#bots" id="nav-bots">مدیریت ربات‌ها</a>
        <a href="#logs" id="nav-logs">گزارش‌ها</a>
        <a href="#settings" id="nav-settings">تنظیمات</a>
      </nav>
      <form id="logout-form" style="display: inline-block; margin-right: auto">
        <button type="submit" class="logout-btn">خروج</button>
      </form>
    </header>
    <main id="main-content"></main>
  </div>

  <!-- Dashboard Template -->
  <template id="dashboard-template">
    <div class="dashboard">
      <h2>آمار کلی سیستم</h2>
      <div class="stats-grid">
        <div class="stat-card">
          <h3>کل ربات‌ها</h3>
          <p class="stat-number" data-stat="total_bots">۰</p>
        </div>
        <div class="stat-card">
          <h3>ربات‌های فعال</h3>
          <p class="stat-number active" data-stat="active_bots">۰</p>
        </div>
        <div class="stat-card">
          <h3>کل کاربران</h3>
          <p class="stat-number" data-stat="total_users">۰</p>
        </div>
        <div class="stat-card">
          <h3>اشتراک‌های رو به اتمام</h3>
          <p class="stat-number warning" data-stat="expiring_soon">۰</p>
          <small>(کمتر از ۷ روز)</small>
        </div>
      </div>

      <div class="dashboard-charts">
        <div class="chart-card">
          <h3>وضعیت ربات‌ها</h3>
          <canvas id="botsChart"></canvas>
        </div>
        <div class="chart-card">
          <h3>آمار کاربران</h3>
          <div id="usersStats"></div>
        </div>
      </div>
    </div>
  </template>

  <!-- Bots Management Template -->
  <template id="bots-template">
    <div class="actions-bar">
      <details>
        <summary style="cursor: pointer; font-weight: bold; color: var(--accent-color);">
          افزودن ربات جدید
        </summary>
        <form id="add-bot-form" style="margin-top: 15px; background: #252525; padding: 20px; border-radius: 8px;">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px">
            <input type="text" name="bot_id" placeholder="شناسه ربات (مثل: store1)" required style="padding: 10px; border-radius: 5px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-color);">
            <input type="text" name="bot_name" placeholder="نام نمایشی ربات" required style="padding: 10px; border-radius: 5px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-color);">
            <input type="text" name="bot_token" placeholder="توکن ربات" required style="grid-column: 1 / -1; padding: 10px; border-radius: 5px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-color);">
          </div>
          <button type="submit" style="margin-top: 15px; width: 100%">افزودن</button>
        </form>
      </details>
    </div>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>شناسه ربات</th>
            <th>نام</th>
            <th>وضعیت</th>
            <th>انقضای اشتراک</th>
            <th>کاربران</th>
            <th>عملیات</th>
            <th>وب‌هوک</th>
          </tr>
        </thead>
        <tbody id="bots-table-body"></tbody>
      </table>
    </div>
  </template>

  <!-- Logs Template -->
  <template id="logs-template">
    <div class="logs-section">
      <h2>گزارش‌های سیستم</h2>
      <div class="logs-filters">
        <select id="log-filter-action">
          <option value="">همه اعمال</option>
          <option value="Login">ورود</option>
          <option value="Logout">خروج</option>
          <option value="Bot Created">ایجاد ربات</option>
          <option value="Bot Deleted">حذف ربات</option>
          <option value="Status Toggled">تغییر وضعیت</option>
        </select>
        <input type="date" id="log-filter-date">
        <button id="apply-log-filters">اعمال فیلتر</button>
        <button id="clear-log-filters">پاک کردن</button>
      </div>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>زمان</th>
              <th>کاربر</th>
              <th>عمل</th>
              <th>جزئیات</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody id="logs-table-body"></tbody>
        </table>
      </div>
    </div>
  </template>

  <!-- Settings Template -->
  <template id="settings-template">
    <div class="settings-section">
      <h2>تنظیمات سیستم</h2>
      <div class="settings-grid">
        <div class="setting-card">
          <h3>تنظیمات امنیتی</h3>
          <form id="security-settings-form">
            <label>تغییر رمز عبور مدیر:</label>
            <input type="password" name="current_password" placeholder="رمز عبور فعلی" required>
            <input type="password" name="new_password" placeholder="رمز عبور جدید" required>
            <input type="password" name="confirm_password" placeholder="تکرار رمز عبور جدید" required>
            <button type="submit">تغییر رمز عبور</button>
          </form>
        </div>

        <div class="setting-card">
          <h3>تنظیمات پشتیبان‌گیری</h3>
          <button id="backup-database" class="action-btn">پشتیبان‌گیری از پایگاه داده</button>
          <button id="backup-configs" class="action-btn">پشتیبان‌گیری از تنظیمات</button>
          <div id="backup-status"></div>
        </div>

        <div class="setting-card">
          <h3>تنظیمات سیستم</h3>
          <form id="system-settings-form">
            <label>URL اصلی سایت:</label>
            <input type="url" name="app_url" placeholder="https://yoursite.com">

            <label>حداکثر تعداد ربات‌ها:</label>
            <input type="number" name="max_bots" placeholder="100">

            <label>مدت نگهداری لاگ‌ها (روز):</label>
            <input type="number" name="log_retention_days" placeholder="30">

            <button type="submit">ذخیره تنظیمات</button>
          </form>
        </div>
      </div>
    </div>
  </template>

  <script>
    const csrfToken = "<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>";
    const logoutUrl = `index.php?action=logout&csrf_token=${csrfToken}`;
  </script>
  <script src="app.js"></script>
</body>

</html>