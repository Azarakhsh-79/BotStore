class WheelDatePicker {
  constructor(options) {
    this.el =
      typeof options.el === "string"
        ? document.querySelector(options.el)
        : options.el;
    this.options = {
      format: "YYYY-MM-DD",
      jalali: false,
      responsive: true,
      sync: false,
      ...options,
    };
    this.currentDate = this.parseDate(this.el.value);
    this.build();
    this.bindEvents();
  }

  // <<-- اصلاحیه اصلی برای تشخیص تاریخ شمسی -->>
  parseDate(value) {
    if (!value || !value.trim()) {
      return new Date();
    }

    const parts = value.split(/[\s/:-]+/);
    const year = parseInt(parts[0]);
    const month = parseInt(parts[1]);
    const day = parseInt(parts[2]);
    const hour = parseInt(parts[3] || 0);
    const minute = parseInt(parts[4] || 0);

    if (isNaN(year) || isNaN(month) || isNaN(day)) {
      return new Date();
    }

    // اگر حالت شمسی فعال است و سال ورودی شبیه سال شمسی است
    if (this.options.jalali && year > 1300) {
      const gregorian = this.jalaliToGregorian(year, month, day);
      return new Date(
        gregorian.gy,
        gregorian.gm - 1,
        gregorian.gd,
        hour,
        minute
      );
    }

    // اگر تاریخ میلادی معتبر بود
    if (year > 1900) {
      return new Date(year, month - 1, day, hour, minute);
    }

    // در هر حالت دیگر، تاریخ فعلی را برگردان
    return new Date();
  }

  build() {
    this.datepicker = document.createElement("div");
    this.datepicker.className = "wheel-datepicker";
    if (this.options.responsive) this.datepicker.classList.add("responsive");

    this.datepicker.innerHTML = `
            <div class="wheel-datepicker-header">انتخاب تاریخ</div>
            <div class="wheel-datepicker-body"></div>
            <div class="wheel-datepicker-footer">
                <button class="wheel-datepicker-button">تایید</button>
            </div>
        `;
    document.body.appendChild(this.datepicker);
    this.body = this.datepicker.querySelector(".wheel-datepicker-body");
    this.renderWheels();
  }

  renderWheels() {
    this.body.innerHTML = "";
    const format = this.options.format;
    const parts = format.split(/([YMDHms]{1,4})/);
    parts.forEach((part) => {
      if (/YYYY|YY/.test(part))
        this.addWheel("year", 1350, 1450); // محدوده سال شمسی
      else if (/MM/.test(part)) this.addWheel("month", 1, 12);
      else if (/DD/.test(part)) this.addWheel("day", 1, this.getDaysInMonth());
      else if (/HH/.test(part)) this.addWheel("hour", 0, 23);
      else if (/mm/.test(part)) this.addWheel("minute", 0, 59);
      else if (/[/:\s-]/.test(part)) {
        const separator = document.createElement("div");
        separator.className = "wheel-datepicker-separator";
        separator.textContent = part.trim();
        this.body.appendChild(separator);
      }
    });
    this.updateWheels();
  }

  addWheel(type, min, max) {
    const container = document.createElement("div");
    container.className = "wheel-datepicker-container";
    const highlight = document.createElement("div");
    highlight.className = "wheel-datepicker-highlight";
    const wheel = document.createElement("div");
    wheel.className = "wheel-datepicker-wheel";
    wheel.dataset.type = type;

    for (let i = min; i <= max; i++) {
      const item = document.createElement("div");
      item.className = "wheel-datepicker-item";
      item.textContent =
        this.options.jalali && type === "month"
          ? this.getJalaliMonthName(i)
          : String(i).padStart(2, "0");
      item.dataset.value = i;
      wheel.appendChild(item);
    }
    container.appendChild(highlight);
    container.appendChild(wheel);
    this.body.appendChild(container);

    let isDragging = false,
      startY,
      startTransform;
    const onDragStart = (e) => {
      isDragging = true;
      startY = e.pageY || e.touches[0].pageY;
      startTransform = this.getTransformY(wheel);
      wheel.style.transition = "none";
    };
    const onDragMove = (e) => {
      if (!isDragging) return;
      e.preventDefault();
      const currentY = e.pageY || e.touches[0].pageY;
      const diff = currentY - startY;
      wheel.style.transform = `translateY(${startTransform + diff}px)`;
    };
    const onDragEnd = () => {
      if (!isDragging) return;
      isDragging = false;
      wheel.style.transition = "transform 0.2s ease-out";
      const currentTransform = this.getTransformY(wheel);
      const index = Math.round(-currentTransform / 36);
      this.scrollTo(wheel, index);
    };
    wheel.addEventListener("mousedown", onDragStart);
    document.addEventListener("mousemove", onDragMove);
    document.addEventListener("mouseup", onDragEnd);
    wheel.addEventListener("touchstart", onDragStart);
    document.addEventListener("touchmove", onDragMove, { passive: false });
    document.addEventListener("touchend", onDragEnd);
    wheel.addEventListener("click", (e) => {
      if (e.target.classList.contains("wheel-datepicker-item")) {
        const index = Array.from(wheel.children).indexOf(e.target);
        this.scrollTo(wheel, index);
      }
    });
  }

  updateWheels() {
    const date = this.options.jalali
      ? this.gregorianToJalali(
          this.currentDate.getFullYear(),
          this.currentDate.getMonth() + 1,
          this.currentDate.getDate()
        )
      : this.currentDate;
    this.setWheelValue(
      "year",
      this.options.jalali ? date.jy : date.getFullYear()
    );
    this.setWheelValue(
      "month",
      this.options.jalali ? date.jm : date.getMonth() + 1
    );
    this.setWheelValue("day", this.options.jalali ? date.jd : date.getDate());
    this.setWheelValue("hour", this.currentDate.getHours());
    this.setWheelValue("minute", this.currentDate.getMinutes());
  }

  setWheelValue(type, value) {
    const wheel = this.body.querySelector(
      `.wheel-datepicker-wheel[data-type="${type}"]`
    );
    if (!wheel) return;
    const items = wheel.querySelectorAll(".wheel-datepicker-item");
    const index = Array.from(items).findIndex(
      (item) => parseInt(item.dataset.value) === value
    );
    if (index > -1) this.scrollTo(wheel, index, false);
  }

  scrollTo(wheel, index, update = true) {
    const items = wheel.children;
    const maxIndex = items.length - 1;
    index = Math.max(0, Math.min(index, maxIndex));
    wheel.style.transform = `translateY(${-index * 36}px)`;
    if (update) this.onWheelChange();
  }

  onWheelChange() {
    const year = this.getWheelValue("year");
    const month = this.getWheelValue("month");
    const day = this.getWheelValue("day");
    const hour = this.getWheelValue("hour");
    const minute = this.getWheelValue("minute");

    if (this.options.jalali) {
      const gDate = this.jalaliToGregorian(year, month, day);
      this.currentDate = new Date(
        gDate.gy,
        gDate.gm - 1,
        gDate.gd,
        hour || 0,
        minute || 0
      );
    } else {
      this.currentDate = new Date(year, month - 1, day, hour || 0, minute || 0);
    }

    const dayWheel = this.body.querySelector(
      '.wheel-datepicker-wheel[data-type="day"]'
    );
    if (dayWheel) {
      const daysInMonth = this.getDaysInMonth();
      if (dayWheel.children.length !== daysInMonth) {
        this.renderWheels();
      }
    }

    if (this.options.sync) this.updateInput();
  }

  getWheelValue(type) {
    const wheel = this.body.querySelector(
      `.wheel-datepicker-wheel[data-type="${type}"]`
    );
    if (!wheel) return null;
    const currentTransform = this.getTransformY(wheel);
    const index = Math.round(-currentTransform / 36);
    return parseInt(wheel.children[index].dataset.value);
  }

  getTransformY(element) {
    const style = window.getComputedStyle(element);
    const matrix = new DOMMatrix(style.transform);
    return matrix.m42;
  }

  getDaysInMonth() {
    if (this.options.jalali) {
      const jm =
        this.getWheelValue("month") ||
        this.gregorianToJalali(
          this.currentDate.getFullYear(),
          this.currentDate.getMonth() + 1,
          this.currentDate.getDate()
        ).jm;
      if (jm <= 6) return 31;
      if (jm <= 11) return 30;
      const jy =
        this.getWheelValue("year") ||
        this.gregorianToJalali(
          this.currentDate.getFullYear(),
          this.currentDate.getMonth() + 1,
          this.currentDate.getDate()
        ).jy;
      return this.isLeapJalali(jy) ? 30 : 29;
    }
    const year = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();
    return new Date(year, month + 1, 0).getDate();
  }

  updateInput() {
    const format = this.options.format;
    const d = this.options.jalali
      ? this.gregorianToJalali(
          this.currentDate.getFullYear(),
          this.currentDate.getMonth() + 1,
          this.currentDate.getDate()
        )
      : this.currentDate;

    const year = this.options.jalali ? d.jy : d.getFullYear();
    const month = this.options.jalali ? d.jm : d.getMonth() + 1;
    const day = this.options.jalali ? d.jd : d.getDate();

    const formatted = format
      .replace("YYYY", year)
      .replace("MM", String(month).padStart(2, "0"))
      .replace("DD", String(day).padStart(2, "0"))
      .replace("HH", String(this.currentDate.getHours()).padStart(2, "0"))
      .replace("mm", String(this.currentDate.getMinutes()).padStart(2, "0"));
    this.el.value = formatted;
  }

  bindEvents() {
    this.el.addEventListener("focus", () => this.show());
    this.datepicker
      .querySelector(".wheel-datepicker-button")
      .addEventListener("click", () => {
        this.updateInput();
        this.hide();
      });
    document.addEventListener("click", (e) => {
      if (!this.el.contains(e.target) && !this.datepicker.contains(e.target)) {
        this.hide();
      }
    });
  }

  show() {
    this.currentDate = this.parseDate(this.el.value);
    this.updateWheels();

    const rect = this.el.getBoundingClientRect();
    this.datepicker.style.top = `${window.scrollY + rect.bottom + 5}px`;
    this.datepicker.style.left = `${window.scrollX + rect.left}px`;
    this.datepicker.classList.add("show");
  }

  hide() {
    this.datepicker.classList.remove("show");
  }

  getJalaliMonthName(m) {
    return [
      "فروردین",
      "اردیبهشت",
      "خرداد",
      "تیر",
      "مرداد",
      "شهریور",
      "مهر",
      "آبان",
      "آذر",
      "دی",
      "بهمن",
      "اسفند",
    ][m - 1];
  }

  isLeapJalali(jy) {
    return ((jy % 33) % 4) - 1 == ~~((jy % 33) * 0.05);
  }

  gregorianToJalali(gy, gm, gd) {
    let g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let jy = gm > 2 ? gy + 1 : gy;
    let days =
      355666 +
      365 * gy +
      ~~((jy + 3) / 4) -
      ~~((jy + 99) / 100) +
      ~~((jy + 399) / 400) +
      gd +
      g_d_m[gm - 1];
    jy = -1595 + 33 * ~~(days / 12053);
    days %= 12053;
    jy += 4 * ~~(days / 1461);
    days %= 1461;
    if (days > 365) {
      jy += ~~((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let jm = days < 186 ? 1 + ~~(days / 31) : 7 + ~~((days - 186) / 30);
    let jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
    return { jy, jm, jd };
  }

  jalaliToGregorian(jy, jm, jd) {
    let gy = jy <= 979 ? 621 : 1600;
    jy -= jy <= 979 ? 0 : 979;
    let days =
      365 * jy +
      ~~(jy / 33) * 8 +
      ~~(((jy % 33) + 3) / 4) +
      78 +
      jd +
      (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    gy += 400 * ~~(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * ~~(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * ~~(days / 1461);
    days %= 1461;
    if (days > 365) {
      gy += ~~((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let gd = days + 1;
    let sal_a = [
      0,
      31,
      (gy % 4 == 0 && gy % 100 != 0) || gy % 400 == 0 ? 29 : 28,
      31,
      30,
      31,
      30,
      31,
      31,
      30,
      31,
      30,
      31,
    ];
    let gm;
    for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
    return { gy, gm, gd };
  }
}
