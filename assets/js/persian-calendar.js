(function () {
  'use strict';
  const faDigits = value => String(value).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const enDigits = value => String(value).replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)));
  const months = ['حمل','ثور','جوزا','سرطان','اسد','سنبله','میزان','عقرب','قوس','جدی','دلو','حوت'];
  const weekdays = ['ش','ی','د','س','چ','پ','ج'];
  const formatter = new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn', {year:'numeric', month:'numeric', day:'numeric'});
  const partsOf = date => {
    const parts = {};
    formatter.formatToParts(date).forEach(part => { if (part.type !== 'literal') parts[part.type] = Number(part.value); });
    return {year:parts.year, month:parts.month, day:parts.day};
  };
  const toIso = date => date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0');
  const fromIso = value => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return match ? new Date(Number(match[1]), Number(match[2])-1, Number(match[3]), 12) : null;
  };
  const formatJalali = date => {
    if (!date) return '';
    const p = partsOf(date);
    return faDigits(p.year + '/' + String(p.month).padStart(2,'0') + '/' + String(p.day).padStart(2,'0'));
  };
  const dateForJalaliDay = (year, month, day) => {
    for (let gYear = year + 621; gYear <= year + 623; gYear++) {
      const cursor = new Date(gYear, 0, 1, 12);
      const end = new Date(gYear + 1, 0, 1, 12);
      while (cursor < end) {
        const p = partsOf(cursor);
        if (p.year === year && p.month === month && p.day === day) return new Date(cursor);
        cursor.setDate(cursor.getDate()+1);
      }
    }
    return null;
  };
  const monthDays = (year, month) => {
    const result = [];
    for (let day=1; day<=31; day++) {
      const date = dateForJalaliDay(year, month, day);
      if (date) result.push(date);
      else if (day > 28 && result.length) break;
    }
    return result;
  };
  function attach(original) {
    if (original.dataset.persianCalendarReady === '1') return;
    original.dataset.persianCalendarReady = '1';
    const required = original.required;
    const name = original.name;
    const isoValue = original.value;
    const visible = document.createElement('input');
    visible.type = 'text';
    visible.className = original.className;
    visible.id = original.id + '_persian';
    visible.placeholder = '۱۴۰۵/۰۷/۲۰';
    visible.autocomplete = 'off';
    visible.inputMode = 'numeric';
    visible.readOnly = true;
    visible.setAttribute('aria-label', (document.querySelector('label[for="' + original.id + '"]')?.textContent || 'تاریخ').trim() + ' به تقویم هجری شمسی');
    visible.value = formatJalali(fromIso(isoValue));
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = name;
    hidden.value = isoValue;
    hidden.required = required;
    original.name = '';
    original.type = 'text';
    original.removeAttribute('required');
    original.style.display = 'none';
    original.parentNode.insertBefore(visible, original);
    original.parentNode.insertBefore(hidden, original);
    original.remove();
    const wrapper = document.createElement('div');
    wrapper.className = 'persian-calendar-popup';
    wrapper.hidden = true;
    wrapper.innerHTML = '<div class="pc-head"><button type="button" data-step="-1" aria-label="ماه قبل">‹</button><div class="pc-selectors"><select class="pc-month-select" aria-label="ماه"></select><select class="pc-year-select" aria-label="سال"></select></div><button type="button" data-step="1" aria-label="ماه بعد">›</button></div><div class="pc-week"></div><div class="pc-days"></div><div class="pc-foot"><button type="button" class="pc-today">امروز</button><button type="button" class="pc-clear">پاک کردن</button><button type="button" class="pc-close">بستن</button></div>';
    visible.parentNode.insertBefore(wrapper, visible.nextSibling);
    const calendarContainer = document.createElement('div');
    calendarContainer.className = 'persian-calendar-container';
    visible.parentNode.insertBefore(calendarContainer, visible);
    calendarContainer.appendChild(visible);
    calendarContainer.appendChild(wrapper);
    const monthSelect = wrapper.querySelector('.pc-month-select');
    const yearSelect = wrapper.querySelector('.pc-year-select');
    months.forEach((month, index) => { const option = document.createElement('option'); option.value = String(index + 1); option.textContent = month; monthSelect.appendChild(option); });
    const current = partsOf(new Date());
    for (let year = current.year - 120; year <= current.year + 10; year++) { const option = document.createElement('option'); option.value = String(year); option.textContent = faDigits(year); yearSelect.appendChild(option); }
    monthSelect.addEventListener('change', () => { shown.month = Number(monthSelect.value); render(); });
    yearSelect.addEventListener('change', () => { shown.year = Number(yearSelect.value); render(); });
    const week = wrapper.querySelector('.pc-week');
    const days = wrapper.querySelector('.pc-days');
    weekdays.forEach(day => { const item=document.createElement('span'); item.textContent=day; week.appendChild(item); });
    let shown = partsOf(fromIso(hidden.value) || new Date());
    function render() {
      if (shown.year < Number(yearSelect.options[0].value) || shown.year > Number(yearSelect.options[yearSelect.options.length - 1].value)) {
        const option = document.createElement('option'); option.value = String(shown.year); option.textContent = faDigits(shown.year); yearSelect.add(option);
      }
      monthSelect.value = String(shown.month);
      yearSelect.value = String(shown.year);
      days.replaceChildren();
      const dates = monthDays(shown.year, shown.month);
      if (!dates.length) return;
      const offset = (dates[0].getDay()+1)%7;
      for (let i=0;i<offset;i++) { const blank=document.createElement('span'); blank.className='pc-empty'; days.appendChild(blank); }
      dates.forEach(date => {
        const p=partsOf(date), button=document.createElement('button');
        button.type='button'; button.textContent=faDigits(p.day);
        if (hidden.value === toIso(date)) button.classList.add('is-selected');
        if (p.year===current.year && p.month===current.month && p.day===current.day) button.classList.add('is-today');
        button.addEventListener('click', () => { hidden.value=toIso(date); visible.value=formatJalali(date); wrapper.hidden=true; hidden.dispatchEvent(new Event('change',{bubbles:true})); visible.dispatchEvent(new Event('change',{bubbles:true})); });
        days.appendChild(button);
      });
    }
    function moveMonth(delta) {
      shown.month += delta;
      if (shown.month < 1) { shown.month=12; shown.year--; }
      if (shown.month > 12) { shown.month=1; shown.year++; }
      render();
    }
    wrapper.querySelectorAll('[data-step]').forEach(button => button.addEventListener('click', () => moveMonth(Number(button.dataset.step))));
    wrapper.querySelector('.pc-today').addEventListener('click', () => { shown={...current}; const today=dateForJalaliDay(current.year,current.month,current.day); if(today){hidden.value=toIso(today);visible.value=formatJalali(today);} wrapper.hidden=true; render(); });
    wrapper.querySelector('.pc-clear').addEventListener('click', () => { hidden.value=''; visible.value=''; wrapper.hidden=true; });
    wrapper.querySelector('.pc-close').addEventListener('click', () => { wrapper.hidden=true; });
    visible.addEventListener('click', () => { const p=partsOf(fromIso(hidden.value)||new Date()); shown={year:p.year,month:p.month}; render(); wrapper.hidden=!wrapper.hidden; });
    visible.addEventListener('keydown', event => { if(event.key==='Escape') wrapper.hidden=true; if(event.key==='Enter'||event.key===' ') {event.preventDefault(); visible.click();} });
    const form=visible.closest('form');
    if(form) form.addEventListener('submit', event => {
      if(required && !hidden.value) { event.preventDefault(); visible.setCustomValidity('لطفاً تاریخ را از تقویم انتخاب کنید.'); visible.reportValidity(); }
      else visible.setCustomValidity('');
    });
    document.addEventListener('click', event => { if(!wrapper.contains(event.target)&&event.target!==visible) wrapper.hidden=true; });
  }
  document.querySelectorAll('[data-persian-display-date]').forEach(element => {
    const date = fromIso(element.dataset.persianDisplayDate);
    if (!date) { element.textContent = '—'; return; }
    const p = partsOf(date);
    element.textContent = faDigits(p.day) + ' ' + months[p.month - 1] + ' ' + faDigits(p.year);
  });
  document.querySelectorAll('input[type="date"][data-persian-date]').forEach(attach);
})();