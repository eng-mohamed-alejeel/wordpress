(function () {
    'use strict';
    if (!window.adcPublicTools) return;

    function request(data) {
        data.append('nonce', window.adcPublicTools.nonce);
		data.set('lang', window.adcPublicTools.language || (document.documentElement.lang.startsWith('en') ? 'en' : 'ar'));
        return fetch(window.adcPublicTools.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) { return response.json(); });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.cd-compare-button');
        if (!button || button.disabled) return;
        event.preventDefault();
        var data = new FormData();
        data.append('action', 'car_dealer_comparison');
        data.append('compare_action', button.getAttribute('aria-pressed') === 'true' ? 'remove' : 'add');
        data.append('car_id', button.dataset.carId || '');
        button.disabled = true;
        request(data).then(function (result) {
            if (!result.success) throw new Error(result.data && result.data.message ? result.data.message : window.adcPublicTools.comparisonError);
            document.querySelectorAll('.cd-compare-button[data-car-id="' + button.dataset.carId + '"]').forEach(function (item) {
                item.setAttribute('aria-pressed', result.data.active ? 'true' : 'false');
                item.classList.toggle('is-active', !!result.data.active);
                item.textContent = result.data.active ? (item.dataset.removeLabel || window.adcPublicTools.removeLabel) : (item.dataset.addLabel || window.adcPublicTools.addLabel);
            });
        }).catch(function (error) {
            button.textContent = error.message || window.adcPublicTools.comparisonError;
        }).finally(function () { button.disabled = false; });
    });


    var english = (window.adcPublicTools.language || document.documentElement.lang).startsWith('en');
    var locale = english ? 'en-SA' : 'ar-SA';
    var number = new Intl.NumberFormat(locale, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function tr(ar, en) { return english ? en : ar; }
    function el(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    function money(value) { return value == null ? '—' : number.format(value) + ' ' + (window.adcPublicTools.currencyLabel || 'SAR'); }
    function percent(value) { return value == null ? '—' : number.format(value) + '%'; }
    function download(name, text, type) {
        var url = URL.createObjectURL(new Blob([text], {type: type}));
        var a = el('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }
    function status(row) {
        return ({eligible: tr('يجتاز الشروط المنشورة مبدئيًا', 'Passes published checks provisionally'), ineligible: tr('لا يستوفي الشروط', 'Does not meet criteria'), incomplete: tr('يلزم استكمال / تحقق', 'Needs information / verification'), unavailable: tr('غير متاح لهذه المدخلات', 'Unavailable for these inputs')})[row.status];
    }
    function csvCell(value) {
        var text = String(value == null ? '' : value);
        if (/^[=+@\-\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }
    function renderResults(container, data) {
        container.replaceChildren();
        container.append(el('p', data.notice, 'adc-finance-notice'));
        container.append(el('p', tr('شارات الأفضل تقارن النتائج التي اجتازت الشروط المنشورة فقط؛ القيم الناقصة والافتراضات مستبعدة.', 'Best-offer badges compare rows passing published checks only; missing values and assumptions are excluded.')));
        var toolbar = el('div', undefined, 'adc-finance-toolbar');
        var sortLabel = el('label', tr('ترتيب حسب', 'Sort by'));
        var sort = el('select');
        [['monthly_payment',tr('القسط الشهري','Monthly payment')],['annual_rate',tr('نسبة الربح','Profit rate')],['name',tr('اسم الجهة','Provider name')]].forEach(function (item) { var o=el('option',item[1]); o.value=item[0];sort.append(o); });
        sortLabel.append(sort); toolbar.append(sortLabel);
        var filterLabel = el('label'); var filter = el('input'); filter.type='checkbox';
        filterLabel.append(filter, document.createTextNode(tr('اجتياز الشروط المنشورة فقط','Passing published checks only')));toolbar.append(filterLabel);
        var exportButton=el('button',tr('تصدير CSV','Export CSV'));exportButton.type='button';
        exportButton.addEventListener('click',function () {
            var rows=[['Financing comparison estimate / SAR / flat rate; not an approval'],['Source',data.source],['Price including VAT',data.input.price],['Salary',data.input.salary],['Existing obligations',data.input.obligations],['Sector',data.input.sector],['Nationality',data.input.nationality],['Transfer',data.input.transfer],['Employer',data.input.employer],['Campaign',data.input.campaign],['Months',data.input.months],['Category',data.input.category],['Chinese',data.input.chinese],['Brand',data.input.brand],['Annual insurance %',data.input.insurance_rate],[],['Provider','Status','Flat rate %','Monthly incl insurance SAR','Base monthly SAR','Insurance monthly SAR','Down payment SAR','Balloon SAR','Admin SAR','Profit SAR','Total SAR','Source page','Dealer rebate % (not deducted)','Reasons','Notes']];
            data.results.forEach(function(r){rows.push([r.name,status(r),r.annual_rate,r.monthly_payment,r.base_monthly,r.insurance_monthly,r.down_payment,r.balloon_payment,r.admin_fees,r.profit,r.total_payable,r.source_page,r.rebate_percent,r.reasons.join('; '),r.notes.join('; ')]);});
            download('finance-comparison.csv','\uFEFF'+rows.map(function(r){return r.map(csvCell).join(',');}).join('\r\n'),'text/csv;charset=utf-8');
        });toolbar.append(exportButton);
        var jsonButton=el('button',tr('تصدير التفاصيل JSON','Export details JSON'));jsonButton.type='button';
        jsonButton.addEventListener('click',function(){download('finance-details.json',JSON.stringify(data,null,2),'application/json');});toolbar.append(jsonButton);
        container.append(toolbar);
        var scroll=el('div',undefined,'adc-finance-scroll'); scroll.tabIndex=0;scroll.setAttribute('role','region');scroll.setAttribute('aria-label',tr('مقارنة نتائج التمويل','Financing comparison results'));
        var table=el('table');var caption=el('caption',tr('المقارنة بالريال السعودي — القسط يشمل التأمين التقديري','Comparison in SAR — payment includes estimated insurance'));table.append(caption);
        var head=el('thead'); var hr=el('tr');
        [tr('الجهة','Provider'),tr('الربح الثابت','Flat rate'),tr('القسط الشهري','Monthly payment'),tr('الدفعة الأولى','Down payment'),tr('الدفعة الأخيرة','Balloon'),tr('الحالة والتفاصيل','Status and details')].forEach(function(h){var th=el('th',h);th.scope='col';hr.append(th);});head.append(hr);table.append(head);
        var body=el('tbody');table.append(body);scroll.append(table);container.append(scroll);
        function draw() {
            body.replaceChildren();
            var rows=data.results.filter(function(r){return !filter.checked||r.status==='eligible';}).slice();
            rows.sort(function(a,b){if(sort.value==='name')return a.name.localeCompare(b.name,locale);return (a[sort.value]==null?Infinity:a[sort.value])-(b[sort.value]==null?Infinity:b[sort.value]);});
            rows.forEach(function(r){
                var row=el('tr',undefined,'adc-finance-'+r.status);var name=el('th',r.name);name.scope='row';
                if(r.lowest_payment)name.append(el('span',tr('أقل قسط شهري','Lowest monthly payment'),'adc-finance-badge'));
                if(r.lowest_rate)name.append(el('span',tr('أقل نسبة ربح','Lowest profit rate'),'adc-finance-badge'));
                row.append(name,el('td',percent(r.annual_rate)),el('td',money(r.monthly_payment)),el('td',money(r.down_payment)),el('td',money(r.balloon_payment)));
                var cell=el('td');cell.append(el('strong',status(r)));
                r.reasons.forEach(function(reason){cell.append(el('p',reason));});
                var card=el('details');card.append(el('summary',tr('تفاصيل الحسبة','Calculation details')));
                var breakdown=el('dl',undefined,'adc-finance-breakdown');
                var fields=[['annual_rate',tr('الربح السنوي الثابت','Annual flat rate'),percent],['minimum_salary',tr('الحد الأدنى للراتب','Minimum salary'),money],['down_percent',tr('نسبة الدفعة الأولى','Down percentage'),percent],['down_payment',tr('الدفعة الأولى','Down payment'),money],['principal',tr('مبلغ التمويل الخاضع للربح','Profit-bearing principal'),money],['profit',tr('إجمالي هامش الربح','Total profit'),money],['balloon_percent',tr('نسبة الدفعة الأخيرة','Balloon percentage'),percent],['balloon_payment',tr('الدفعة الأخيرة','Balloon payment'),money],['admin_fees',tr('الرسوم الإدارية','Administrative fees'),money],['installment_total',tr('المبلغ للتقسيط دون تأمين','Installment total excluding insurance'),money],['base_monthly',tr('القسط الأساسي','Base monthly payment'),money],['insurance_monthly',tr('التأمين الشهري التقديري','Estimated monthly insurance'),money],['insurance_total',tr('التأمين خلال المدة','Insurance over tenure'),money],['monthly_payment',tr('القسط شامل التأمين','Payment including insurance'),money],['total_payable',tr('الإجمالي شامل الدفعتين والتأمين','Total including down payment, balloon and insurance'),money],['debt_ratio',tr('نسبة القسط والالتزامات إلى الراتب','Payment and obligations / salary'),percent],['rebate_percent',tr('دعم الوكيل (لا يخصم تلقائيًا)','Dealer support (not automatically deducted)'),percent]];
                fields.forEach(function(f){var d=el('div');d.append(el('dt',f[1]),el('dd',f[2](r[f[0]])));breakdown.append(d);});card.append(breakdown);
                card.append(el('p',tr('مدة التمويل: ','Tenure: ')+data.input.months+tr(' شهرًا؛ صفحة المرجع: ',' months; Source page: ')+r.source_page));
                r.notes.forEach(function(note){card.append(el('p',note));});cell.append(card);row.append(cell);body.append(row);
            });
            if(!rows.length){var empty=el('tr');var td=el('td',tr('لا توجد نتائج تجتاز الشروط المنشورة لهذه المدخلات.','No results pass the published checks for these inputs.'));td.colSpan=6;empty.append(td);body.append(empty);}
        }
        sort.addEventListener('change',draw);filter.addEventListener('change',draw);draw();
    }
    document.addEventListener('change', function(event) {
        var form=event.target.closest('[data-loan-calculator]'); if(!form||!event.target.name)return;
        if(event.target.name==='sector')form.elements.employer.value=event.target.value==='private_unapproved'?'unapproved':'approved';
        var output=form.querySelector('[data-loan-results]');if(output)output.replaceChildren();
        var statusNode=form.querySelector('[data-loan-status]');if(statusNode)statusNode.textContent=tr('أعد الحساب بعد تغيير المدخلات.','Calculate again after changing inputs.');
    });
    document.addEventListener('submit', function (event) {
        var form=event.target;
        if(!(form instanceof HTMLFormElement)||!form.matches('[data-loan-calculator]'))return;
        event.preventDefault();
        if(form.dataset.calculating==='true')return;
        var statusNode=form.querySelector('[data-loan-status]');var container=form.querySelector('[data-loan-results]');var button=form.querySelector('[type="submit"]');
        var data=new FormData(form);data.append('action','car_dealer_loan_calculator');
        var snapshot=new URLSearchParams(new FormData(form)).toString();
        form.dataset.calculating='true';if(button)button.disabled=true;container.replaceChildren();
        if(statusNode)statusNode.textContent=window.adcPublicTools.calculatingLabel;
        request(data).then(function(result){
            if(snapshot!==new URLSearchParams(new FormData(form)).toString()) { if(statusNode)statusNode.textContent=tr('تغيرت المدخلات؛ أعد الحساب.','Inputs changed; calculate again.'); return; }
            if(!result.success)throw new Error(result.data&&result.data.message?result.data.message:window.adcPublicTools.calculatorError);
            renderResults(container,result.data);if(statusNode)statusNode.textContent=tr('تم حساب المقارنة.','Comparison calculated.');
        }).catch(function(error){if(statusNode)statusNode.textContent=error.message||window.adcPublicTools.calculatorError;}).finally(function(){delete form.dataset.calculating;if(button)button.disabled=false;});
    });
}());
