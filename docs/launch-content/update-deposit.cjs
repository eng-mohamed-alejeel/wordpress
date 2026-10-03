'use strict';
const fs = require('node:fs');
const path = require('node:path');
for (const name of ['editorial.ar-en.json', 'business-profile.autobrands.json']) {
  const file = path.join(__dirname, name);
  const data = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (data.pages) {
    for (const page of data.pages) {
      page.ar.content = page.ar.content.replace('يتم الحجز بدفع عربون لمدة لا تتجاوز ثلاثة أيام. عند إلغاء الحجز يُعاد مبلغ العربون. يُوضح مبلغ العربون وإجراءات الإلغاء والاسترداد في تفاصيل الحجز قبل الدفع.', 'يتم الحجز بدفع عربون ثابت قدره 20,000 ريال سعودي لمدة لا تتجاوز ثلاثة أيام. عند إلغاء الحجز يُعاد مبلغ العربون عبر طريقة الدفع نفسها. تُوضح إجراءات الاسترداد قبل الدفع.');
      page.ar.content = page.ar.content.replace('يُحجز بدفع عربون لمدة ثلاثة أيام كحد أقصى، ويُعاد العربون عند إلغاء الحجز. يُوضح المبلغ وإجراءات الاسترداد قبل الدفع.', 'يُحجز بدفع عربون ثابت قدره 20,000 ريال سعودي لمدة ثلاثة أيام كحد أقصى. يُعاد العربون عند الإلغاء عبر طريقة الدفع نفسها.');
      page.en.content = page.en.content.replace('A reservation requires a deposit and lasts no longer than three days. The deposit is returned if the reservation is cancelled. The deposit amount and cancellation and refund procedure are specified in the reservation details before payment.', 'A reservation requires a fixed deposit of SAR 20,000 and lasts no longer than three days. If cancelled, the deposit is returned through the same payment method. Refund procedures are explained before payment.');
      page.en.content = page.en.content.replace('A deposit reserves the vehicle for a maximum of three days and is returned on cancellation. The amount and refund procedure are specified before payment.', 'A fixed deposit of SAR 20,000 reserves the vehicle for a maximum of three days and is returned through the same payment method on cancellation.');
      page.ar.content = page.ar.content.replace('يُعاد مبلغ العربون عبر طريقة الدفع نفسها.', 'يُعاد مبلغ العربون خلال ثلاثة أيام من إلغاء الحجز عبر طريقة الدفع نفسها.').replace('يُعاد العربون عند الإلغاء عبر طريقة الدفع نفسها.', 'يُعاد العربون خلال ثلاثة أيام من إلغاء الحجز عبر طريقة الدفع نفسها.');
      page.en.content = page.en.content.replace('the deposit is returned through the same payment method.', 'the deposit is returned within three days of reservation cancellation through the same payment method.').replace('is returned through the same payment method on cancellation.', 'is returned within three days of reservation cancellation through the same payment method.');
    }
  } else {
    data.reservation.amount = 20000;
    data.reservation.currency = 'SAR';
    data.reservation.amount_halalas = 2000000;
    data.reservation.refund_method = 'same_as_original_payment';
    data.reservation.refund_time = 'within_3_days_of_reservation_cancellation';
    data.reservation.refund_processing_days = 3;
    data.pending = data.pending.filter(item => !['deposit amount or percentage', 'refund timing and method'].includes(item));
    data.pending = data.pending.filter(item => item !== 'refund processing time');
  }
  fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}
console.log('Deposit copy and profile updated: SAR 20,000, 72 hours, original payment method.');
