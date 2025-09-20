// public/superadmin/app.js - Enhanced and Optimized Frontend Logic
document.addEventListener("DOMContentLoaded", () => {
  const mainContent = document.getElementById("main-content");
  const navLinks = document.querySelectorAll("nav a");
  const logoutForm = document.getElementById("logout-form");

  // Helper for creating elements to avoid verbose innerHTML
  const createElement = (tag, attributes = {}, children = []) => {
    const el = document.createElement(tag);
    for (const key in attributes) {
      el.setAttribute(key, attributes[key]);
    }
    children.forEach((child) => {
      if (typeof child === "string") {
        el.appendChild(document.createTextNode(child));
      } else {
        el.appendChild(child);
      }
    });
    return el;
  };

  // API Request Handler (Unchanged)
  const api = async (action, formData, method = "POST") => {
    let url = `api.php?action=${action}`;
    try {
      const options = {
        method: formData ? method : "GET",
      };

      if (formData) {
        if (method === "POST") {
          options.body = formData;
        } else {
          const params = new URLSearchParams(formData);
          url += "&" + params.toString();
        }
      }
      const response = await fetch(url, options);
      if (response.status === 401) {
        window.location.href = "index.php";
        return;
      }
      return await response.json();
    } catch (error) {
      console.error("API Error:", error);
      return {
        ok: false,
        error: "خطا در ارتباط با سرور.",
      };
    }
  };

  // Toast Notification System (Unchanged)
  const showToast = (message, isError = false) => {
    const toast = document.createElement("div");
    toast.className = `flash-message ${isError ? "error" : "success"}`;
    if (typeof message === "object" && message !== null) {
      toast.innerHTML = `<pre>${JSON.stringify(message, null, 2)}</pre>`;
    } else {
      toast.textContent = message;
    }
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add("visible"), 10);
    setTimeout(() => {
      toast.classList.remove("visible");
      toast.addEventListener("transitionend", () => toast.remove());
    }, 5000);
  };

  // Dashboard Rendering (Unchanged but can be improved with a charting library)
  const renderDashboard = async () => {
    mainContent.innerHTML =
      document.getElementById("dashboard-template").innerHTML;
    const result = await api("get_dashboard_stats");
    if (result.ok && result.data) {
      for (const key in result.data) {
        const el = mainContent.querySelector(`[data-stat="${key}"]`);
        if (el) {
          el.textContent = Number(result.data[key]).toLocaleString("fa-IR");
        }
      }
      // Consider using a library like Chart.js for better charts
      createBotsChart(result.data);
    }
    if (result.data && result.data.errors) {
      result.data.errors.forEach((error) => showToast(error, true));
    }
  };

  // *** OPTIMIZED BOTS MANAGEMENT ***
  const renderBots = async () => {
    mainContent.innerHTML = document.getElementById("bots-template").innerHTML;
    const tableBody = mainContent.querySelector("#bots-table-body");
    tableBody.innerHTML =
      '<tr><td colspan="7" class="loading">در حال بارگذاری ربات‌ها...</td></tr>';

    const [botsResult, countsResult] = await Promise.all([
      api("get_bots"),
      api("get_all_bot_user_counts", null, "GET"), // Single API call for all counts
    ]);

    if (botsResult.ok && botsResult.data) {
      tableBody.innerHTML = ""; // Clear loading message
      const userCounts = countsResult.ok ? countsResult.data : {};
      botsResult.data.forEach((bot) => {
        const userCount = userCounts[bot.bot_id] ?? "خطا";
        const row = createBotTableRow(bot, userCount);
        tableBody.appendChild(row);
      });
    } else {
      tableBody.innerHTML =
        '<tr><td colspan="7" class="error">خطا در بارگذاری لیست ربات‌ها.</td></tr>';
    }

    // Event delegation for all actions inside the table
    mainContent
      .querySelector("#add-bot-form")
      .addEventListener("submit", handleAddBot);
    tableBody.addEventListener("submit", handleBotAction);
  };

  const createBotTableRow = (bot, userCount) => {
    const tr = createElement("tr", {
      "data-bot-id": bot.bot_id,
    });

    const expiryDate = bot.subscription_expires_at
      ? bot.subscription_expires_at.split(" ")[0]
      : "";
    const statusClass = bot.status === "active" ? "active" : "inactive";
    const statusText = bot.status === "active" ? "فعال" : "غیرفعال";
    const toggleStatus = bot.status === "active" ? "inactive" : "active";

    tr.innerHTML = `
            <td><strong>${bot.bot_id}</strong></td>
            <td>${bot.bot_name || "نامشخص"}</td>
            <td>
                <form class="bot-action-form" data-action="toggle_status">
                    <input type="hidden" name="bot_id" value="${bot.bot_id}">
                    <input type="hidden" name="new_status" value="${toggleStatus}">
                    <button type="submit" class="status-btn ${statusClass}">${statusText}</button>
                </form>
            </td>
            <td>
                <form class="bot-action-form date-form" data-action="update_subscription">
                    <input type="hidden" name="bot_id" value="${bot.bot_id}">
                    <input type="date" name="expiry_date" value="${expiryDate}">
                    <button type="submit">ذخیره</button>
                </form>
            </td>
            <td class="user-count"><span class="user-count-number">${userCount.toLocaleString(
              "fa-IR"
            )}</span></td>
            <td class="actions-cell">
                <div class="action-buttons">
                    <button class="edit-btn" onclick="editBot('${
                      bot.bot_id
                    }')" title="ویرایش">✏️</button>
                    <form class="bot-action-form inline-form" data-action="delete_bot">
                        <input type="hidden" name="bot_id" value="${
                          bot.bot_id
                        }">
                        <button type="submit" class="delete-btn" title="حذف">🗑️</button>
                    </form>
                </div>
            </td>
            <td>
                <form class="bot-action-form webhook-form" data-action="manage_webhook">
                    <input type="hidden" name="bot_id" value="${bot.bot_id}">
                    <div class="webhook-controls">
                        <select name="webhook_action">
                            <option value="getInfo">دریافت اطلاعات</option>
                            <option value="set">تنظیم</option>
                            <option value="delete">حذف</option>
                        </select>
                        <button type="submit">اجرا</button>
                    </div>
                </form>
            </td>
        `;
    return tr;
  };

  const handleAddBot = async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append("csrf_token", csrfToken);
    const result = await api("add_bot", formData);
    showToast(result.message || result.error, !result.ok);
    if (result.ok) {
      form.reset();
      renderBots(); // Full reload after adding a new bot is acceptable
    }
  };

  // OPTIMIZED: Uses event delegation
  const handleBotAction = async (e) => {
    if (!e.target.closest(".bot-action-form")) return;
    e.preventDefault();

    const form = e.target.closest(".bot-action-form");
    const action = form.dataset.action;
    const row = form.closest("tr");
    const botId = row.dataset.botId;

    if (
      action === "delete_bot" &&
      !confirm(`آیا از حذف ربات '${botId}' مطمئن هستید؟`)
    )
      return;

    const formData = new FormData(form);
    formData.append("csrf_token", csrfToken);

    // Visual feedback
    row.style.opacity = "0.5";

    const result = await api(action, formData);

    // Handle specific responses without full reload
    if (result.ok) {
      switch (action) {
        case "toggle_status":
          const newStatus = formData.get("new_status");
          updateBotRowStatus(row, newStatus);
          break;
        case "delete_bot":
          row.remove();
          break;
        case "manage_webhook":
          const detail = result.data
            ? result.data
            : {
                error: result.error || "پاسخی از سرور دریافت نشد.",
              };
          const displayObject = {
            پیام: result.message,
            "پاسخ تلگرام": detail,
          };
          const isError =
            !result.ok || (result.data && result.data.ok === false);
          showToast(displayObject, isError);
          break;
        case "update_subscription":
          // Just show a success message
          break;
      }
    }

    showToast(result.message || result.error, !result.ok);
    row.style.opacity = "1";
  };

  // Helper to update a row without reloading the whole table
  const updateBotRowStatus = (row, newStatus) => {
    const statusBtn = row.querySelector(".status-btn");
    const statusForm = statusBtn.closest("form");
    const newStatusInput = statusForm.querySelector('input[name="new_status"]');

    statusBtn.classList.toggle("active", newStatus === "active");
    statusBtn.classList.toggle("inactive", newStatus === "inactive");
    statusBtn.textContent = newStatus === "active" ? "فعال" : "غیرفعال";
    newStatusInput.value = newStatus === "active" ? "inactive" : "active";
  };

  // Other functions (renderLogs, renderSettings, etc.) remain largely the same...
  // ... (rest of the JS file: renderLogs, applyLogFilters, clearLogFilters, renderSettings, handlePasswordChange, etc.)
  // Router logic remains the same.

  // --- PASTE THE REST OF THE ORIGINAL JS FILE FROM HERE ---
  // (Excluding functions that were optimized above like handleBotAction, handleAddBot)

  const renderLogs = async () => {
    mainContent.innerHTML = document.getElementById("logs-template").innerHTML;
    await loadLogs();
    mainContent
      .querySelector("#apply-log-filters")
      .addEventListener("click", applyLogFilters);
    mainContent
      .querySelector("#clear-log-filters")
      .addEventListener("click", clearLogFilters);
  };

  const loadLogs = async (filters = {}) => {
    const tableBody = mainContent.querySelector("#logs-table-body");
    if (!tableBody) return;
    const formData = new FormData();
    Object.entries(filters).forEach(
      ([key, value]) => value && formData.append(key, value)
    );
    const result = await api("get_logs", formData, "GET");

    if (result.ok && result.data) {
      tableBody.innerHTML = result.data
        .map(
          (log) => `
                <tr>
                    <td>${new Date(log.created_at).toLocaleString("fa-IR")}</td>
                    <td>${log.admin_username}</td>
                    <td><span class="log-action">${log.action}</span></td>
                    <td class="log-details">${log.details || "-"}</td>
                    <td class="ip-address">${log.ip_address}</td>
                </tr>
            `
        )
        .join("");
    }
  };

  const applyLogFilters = () =>
    loadLogs({
      action_filter: mainContent.querySelector("#log-filter-action").value,
      date_filter: mainContent.querySelector("#log-filter-date").value,
    });

  const clearLogFilters = () => {
    mainContent.querySelector("#log-filter-action").value = "";
    mainContent.querySelector("#log-filter-date").value = "";
    loadLogs();
  };

  const renderSettings = async () => {
    mainContent.innerHTML =
      document.getElementById("settings-template").innerHTML;
    mainContent
      .querySelector("#security-settings-form")
      .addEventListener("submit", handlePasswordChange);
    mainContent
      .querySelector("#backup-database")
      .addEventListener("click", () => performBackup("database"));
    mainContent
      .querySelector("#backup-configs")
      .addEventListener("click", () => performBackup("configs"));
    mainContent
      .querySelector("#system-settings-form")
      .addEventListener("submit", handleSystemSettings);
  };

  const handlePasswordChange = async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append("csrf_token", csrfToken);
    const result = await api("change_admin_password", formData);
    showToast(result.message || result.error, !result.ok);
    if (result.ok) form.reset();
  };

  const handleSystemSettings = async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append("csrf_token", csrfToken);
    const result = await api("update_system_settings", formData);
    showToast(result.message || result.error, !result.ok);
  };

  const performBackup = async (type) => {
    const statusElement = mainContent.querySelector("#backup-status");
    statusElement.innerHTML = `<div class="loading">در حال پشتیبان‌گیری...</div>`;
    const formData = new FormData();
    formData.append("backup_type", type);
    formData.append("csrf_token", csrfToken);
    const result = await api("create_backup", formData);
    if (result.ok) {
      statusElement.innerHTML = `<div class="success">پشتیبان‌گیری با موفقیت انجام شد.</div>`;
      if (result.download_url) {
        const link = document.createElement("a");
        link.href = result.download_url;
        link.textContent = "دانلود فایل پشتیبان";
        link.className = "download-link";
        statusElement.appendChild(link);
      }
    } else {
      statusElement.innerHTML = `<div class="error">خطا: ${result.error}</div>`;
    }
  };

  window.editBot = (botId) => {
    // This part remains the same as it handles a modal which is a separate component
    const modal = document.createElement("div");
    modal.className = "modal-overlay";
    modal.innerHTML = `
        <div class="modal">
            <div class="modal-header">
                <h3>ویرایش ربات ${botId}</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="edit-bot-form">
                    <input type="hidden" name="bot_id" value="${botId}">
                    <div class="form-group"><label>نام نمایشی:</label><input type="text" name="bot_name" placeholder="نام جدید ربات"></div>
                    <div class="form-group"><label>توکن جدید:</label><input type="text" name="bot_token" placeholder="توکن جدید (اختیاری)"></div>
                    <div class="form-actions"><button type="submit">ذخیره</button><button type="button" class="cancel-btn">انصراف</button></div>
                </form>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.querySelector(".modal-close").onclick = () => modal.remove();
    modal.querySelector(".cancel-btn").onclick = () => modal.remove();
    modal.onclick = (e) => {
      if (e.target === modal) modal.remove();
    };
    modal.querySelector("#edit-bot-form").onsubmit = async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      formData.append("csrf_token", csrfToken);
      const result = await api("update_bot", formData);
      showToast(result.message || result.error, !result.ok);
      if (result.ok) {
        modal.remove();
        renderBots();
      }
    };
  };

  const createBotsChart = (data) => {
    const canvas = mainContent.querySelector("#botsChart");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    const active = data.active_bots || 0;
    const inactive = (data.total_bots || 0) - active;
    const total = active + inactive;
    if (total === 0) return;
    // Simple Pie Chart drawing
    const activeAngle = (active / total) * 2 * Math.PI;
    ctx.beginPath();
    ctx.moveTo(canvas.width / 2, canvas.height / 2);
    ctx.arc(
      canvas.width / 2,
      canvas.height / 2,
      Math.min(canvas.width, canvas.height) / 2 - 10,
      0,
      activeAngle
    );
    ctx.closePath();
    ctx.fillStyle = "#4caf50";
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(canvas.width / 2, canvas.height / 2);
    ctx.arc(
      canvas.width / 2,
      canvas.height / 2,
      Math.min(canvas.width, canvas.height) / 2 - 10,
      activeAngle,
      2 * Math.PI
    );
    ctx.closePath();
    ctx.fillStyle = "#f44336";
    ctx.fill();
  };

  const router = () => {
    const hash = window.location.hash || "#dashboard";
    navLinks.forEach((link) =>
      link.classList.toggle("active", link.hash === hash)
    );
    switch (hash) {
      case "#bots":
        renderBots();
        break;
      case "#logs":
        renderLogs();
        break;
      case "#settings":
        renderSettings();
        break;
      default:
        renderDashboard();
        break;
    }
  };

  window.addEventListener("hashchange", router);
  logoutForm.addEventListener("submit", (e) => {
    e.preventDefault();
    window.location.href = logoutUrl;
  });

  router();
});
