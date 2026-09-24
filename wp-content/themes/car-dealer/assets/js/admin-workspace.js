/* Progressive enhancements: native forms and tables remain usable without JS. */
document.addEventListener('DOMContentLoaded', function () {
 'use strict';
 const form = document.querySelector('.cd-add-car-form');
 if (form) {
  const nav = document.createElement('nav');
  nav.className = 'cd-form-jumps';
  nav.setAttribute('aria-label', 'أقسام بيانات السيارة');
  form.querySelectorAll('.cd-form-panel').forEach(function (panel, index) {
   const heading = panel.querySelector('h2');
   if (!heading) return;
   panel.id = 'cd-form-section-' + index;
   const link = document.createElement('a');
   link.href = '#' + panel.id;
   link.textContent = (index + 1) + '. ' + heading.textContent;
   nav.appendChild(link);
  });
  form.before(nav);
 }
 document.querySelectorAll('.cd-admin > table.widefat, .cd-users-table').forEach(function (table, index) {
  const rows = Array.from(table.querySelectorAll('tbody tr')).filter(function (row) { return !row.querySelector('[colspan]'); });
  const toolbar = document.createElement('div');
  toolbar.className = 'cd-table-toolbar';
  const label = document.createElement('label');
  const input = document.createElement('input');
  input.type = 'search';
  input.id = 'cd-table-search-' + index;
  input.placeholder = 'اكتب للبحث…';
  label.htmlFor = input.id;
  label.append('بحث في السجلات المعروضة', input);
  const count = document.createElement('small');
  count.setAttribute('role', 'status');
  toolbar.append(label, count);
  const region = document.createElement('div');
  region.className = 'cd-table-scroll';
  region.tabIndex = 0;
  region.setAttribute('role', 'region');
  region.setAttribute('aria-label', 'السجلات');
  table.before(toolbar, region);
  region.appendChild(table);
  const empty = document.createElement('p');
  empty.className = 'cd-table-no-results';
  empty.textContent = 'لا توجد نتائج مطابقة. جرّب كلمة أخرى.';
  empty.hidden = true;
  region.after(empty);
  const normalize = function (text) { return text.normalize('NFKC').replace(/[\u064B-\u065F\u0670\u0640]/g, '').toLocaleLowerCase(); };
  input.addEventListener('input', function () {
   const query = normalize(input.value.trim());
   let visible = 0;
   rows.forEach(function (row) {
    row.hidden = !normalize(row.textContent).includes(query);
    if (!row.hidden) visible++;
   });
   count.textContent = visible + ' من ' + rows.length + ' سجل';
   empty.hidden = visible !== 0 || rows.length === 0;
  });
  count.textContent = rows.length + ' سجل معروض';
 });
});
