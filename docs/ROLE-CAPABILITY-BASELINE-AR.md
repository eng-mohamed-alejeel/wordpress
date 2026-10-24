# خط الأساس الفعلي لقدرات الأدوار والحسابات

**وقت القراءة:** 2026-10-24 18:59 (+03:00) — لقطة قبل تحديث قالب دور اعتماد المشتريات  
**قاعدة البيانات:** `wp-autobrands`  
**المنهج:** قراءة مباشرة لمخزني أدوار WordPress وتعيينات المستخدمين ضمن معاملة قراءة فقط؛ لا أسماء حسابات ولا معرفات مستخدمين في هذا التقرير. لم تُكتب أي تغييرات.

## خلاصة مهمة قبل توزيع الحسابات

يوجد 23 حسابًا: حساب مسؤول واحد و22 حساب مراجعة اصطناعيًا موثقًا سابقًا. الحسابات الـ22 موزعة بالفعل على أدوار مراجعة للتغطية الفنية، وليست قائمة موظفين فعلية يمكن استنتاج وظائفها. لا توجد تخصيصات قدرات فردية مباشرة في اللقطة السابقة؛ ولا يثبت اسم الدور الحالي وظيفة المستخدم. لذلك لا يصح اعتبار هذا التوزيع اعتمادًا وظيفيًا أو إعادة تعيين الحسابات على أنه إعداد إنتاجي مهني.

الأرقام والقوائم أدناه هي المنح الإيجابية المخزنة على الأدوار وقت القراءة، وليست إثباتًا كاملًا لكل قرار تفويض وقت التشغيل؛ فقد تؤثر مرشحات WordPress أو إضافات أخرى أو حالة المورد في النتيجة. لم يُقلع WordPress ولم تُختبر كل الأفعال.

## التوزيع الحالي للأدوار

| الدور | الحسابات | القدرات الإيجابية المخزنة |
|---|---:|---:|
| `administrator` | 1 | 139 |
| `car_dealer_customer` | 1 | 1 |
| `dealership_auditor` | 1 | 6 |
| `dealership_chat_adviser` | 2 | 7 |
| `dealership_customer_service` | 2 | 11 |
| `dealership_delivery` | 2 | 5 |
| `dealership_finance` | 2 | 10 |
| `dealership_general_manager` | 2 | 43 |
| `dealership_inventory` | 2 | 7 |
| `dealership_marketing` | 2 | 11 |
| `dealership_purchasing` | 2 | 11 |
| `dealership_sales` | 2 | 11 |
| `dealership_sales_manager` | 2 | 23 |

الأدوار القياسية `editor`, `author`, `contributor`, و`subscriber` موجودة في تعريفات WordPress، لكن لا حسابات مسندة إليها في هذه اللقطة.

## القدرات الكاملة المخزنة حسب الدور

القوائم تشمل كل قدرة إيجابية مخزنة للدور، بما فيها قدرات WordPress الأصلية. لا تشمل مفاتيح `level_N` القديمة، ولا تمنح القائمة صلاحية خارج نطاق الفرع أو قيود الخدمة.

### مسؤول الموقع — `administrator` (139)

```text
activate_plugins, adc_add_marketing_activity, adc_approve_delivery, adc_approve_high_discounts, adc_approve_high_purchase_orders, adc_approve_purchase_orders, adc_approve_sales, adc_assign_conversations, adc_assign_marketing_leads, adc_cancel_purchase_orders, adc_cancel_sales, adc_change_vehicle_vin, adc_close_conversations, adc_confirm_vehicle_vin, adc_create_marketing_leads, adc_create_purchase_orders, adc_create_quotes, adc_create_reservations, adc_edit_marketing_lead_identity, adc_manage_branch_leads, adc_manage_finance, adc_manage_integrations, adc_manage_inventory, adc_manage_newsletter, adc_manage_outbox, adc_manage_own_leads, adc_manage_pricing, adc_manage_reservations, adc_manage_suppliers, adc_manage_vehicle_costs, adc_moderate_reviews, adc_process_returns, adc_publish_customer_documents, adc_publish_reviews, adc_receive_purchase_orders, adc_record_payments, adc_record_refunds, adc_reply_conversations, adc_request_discounts, adc_review_discounts, adc_start_sales, adc_transfer_inventory, adc_verify_payments, adc_verify_refunds, adc_view_audit, adc_view_branch_leads, adc_view_campaign_metrics, adc_view_conversations, adc_view_finance, adc_view_integrations, adc_view_inventory, adc_view_marketing_leads, adc_view_marketing_subscribers, adc_view_outbox, adc_view_own_leads, adc_view_purchase_orders, adc_view_reports, adc_view_suppliers, adc_view_vehicle_costs, adc_view_workspace, assign_car_brands, assign_car_categories, create_users, delete_car, delete_car_offer, delete_car_offers, delete_cars, delete_others_car_offers, delete_others_cars, delete_others_pages, delete_others_posts, delete_pages, delete_plugins, delete_posts, delete_private_car_offers, delete_private_cars, delete_private_pages, delete_private_posts, delete_published_car_offers, delete_published_cars, delete_published_pages, delete_published_posts, delete_themes, delete_users, edit_car, edit_car_offer, edit_car_offers, edit_cars, edit_dashboard, edit_files, edit_others_car_offers, edit_others_cars, edit_others_pages, edit_others_posts, edit_pages, edit_plugins, edit_posts, edit_private_car_offers, edit_private_cars, edit_private_pages, edit_private_posts, edit_published_car_offers, edit_published_cars, edit_published_pages, edit_published_posts, edit_theme_options, edit_themes, edit_users, export, import, install_plugins, install_themes, list_users, manage_car_brands, manage_car_categories, manage_categories, manage_links, manage_options, moderate_comments, promote_users, publish_car_offers, publish_cars, publish_pages, publish_posts, read, read_car, read_car_offer, read_private_car_offers, read_private_cars, read_private_pages, read_private_posts, remove_users, switch_themes, unfiltered_html, unfiltered_upload, update_core, update_plugins, update_themes, upload_files
```

### عميل البوابة — `car_dealer_customer` (1)

```text
read
```

### المدقق — `dealership_auditor` (6)

```text
adc_view_audit, adc_view_integrations, adc_view_outbox, adc_view_reports, adc_view_workspace, read
```

### مستشار المحادثات — `dealership_chat_adviser` (7)

```text
adc_close_conversations, adc_manage_own_leads, adc_reply_conversations, adc_view_conversations, adc_view_own_leads, adc_view_workspace, read
```

### خدمة العملاء — `dealership_customer_service` (11)

```text
adc_close_conversations, adc_create_quotes, adc_create_reservations, adc_manage_branch_leads, adc_reply_conversations, adc_request_discounts, adc_start_sales, adc_view_branch_leads, adc_view_conversations, adc_view_workspace, read
```

### التسليم — `dealership_delivery` (5)

```text
adc_confirm_vehicle_vin, adc_process_returns, adc_view_inventory, adc_view_workspace, read
```

### المالية — `dealership_finance` (10)

```text
adc_manage_finance, adc_publish_customer_documents, adc_record_payments, adc_record_refunds, adc_verify_payments, adc_verify_refunds, adc_view_finance, adc_view_purchase_orders, adc_view_workspace, read
```

### المدير العام — `dealership_general_manager` (43)

```text
adc_approve_delivery, adc_approve_high_discounts, adc_approve_high_purchase_orders, adc_approve_purchase_orders, adc_approve_sales, adc_assign_conversations, adc_assign_marketing_leads, adc_cancel_purchase_orders, adc_cancel_sales, adc_change_vehicle_vin, adc_close_conversations, adc_create_quotes, adc_create_reservations, adc_manage_branch_leads, adc_manage_integrations, adc_manage_newsletter, adc_manage_outbox, adc_manage_pricing, adc_manage_reservations, adc_manage_suppliers, adc_manage_vehicle_costs, adc_moderate_reviews, adc_process_returns, adc_publish_customer_documents, adc_publish_reviews, adc_reply_conversations, adc_request_discounts, adc_review_discounts, adc_start_sales, adc_view_audit, adc_view_branch_leads, adc_view_conversations, adc_view_finance, adc_view_integrations, adc_view_inventory, adc_view_marketing_subscribers, adc_view_outbox, adc_view_purchase_orders, adc_view_reports, adc_view_suppliers, adc_view_vehicle_costs, adc_view_workspace, read
```

### المخزون — `dealership_inventory` (7)

```text
adc_confirm_vehicle_vin, adc_manage_inventory, adc_transfer_inventory, adc_view_inventory, adc_view_suppliers, adc_view_workspace, read
```

### التسويق — `dealership_marketing` (11)

```text
adc_add_marketing_activity, adc_create_marketing_leads, adc_edit_marketing_lead_identity, adc_manage_newsletter, adc_moderate_reviews, adc_publish_reviews, adc_view_campaign_metrics, adc_view_marketing_leads, adc_view_marketing_subscribers, adc_view_workspace, read
```

### المشتريات — `dealership_purchasing` (11)

```text
adc_cancel_purchase_orders, adc_create_purchase_orders, adc_manage_suppliers, adc_manage_vehicle_costs, adc_receive_purchase_orders, adc_view_inventory, adc_view_purchase_orders, adc_view_suppliers, adc_view_vehicle_costs, adc_view_workspace, read
```

### المبيعات — `dealership_sales` (11)

```text
adc_close_conversations, adc_create_quotes, adc_create_reservations, adc_manage_own_leads, adc_reply_conversations, adc_request_discounts, adc_start_sales, adc_view_conversations, adc_view_own_leads, adc_view_workspace, read
```

### مدير المبيعات — `dealership_sales_manager` (23)

```text
adc_approve_delivery, adc_approve_purchase_orders, adc_approve_sales, adc_assign_conversations, adc_assign_marketing_leads, adc_cancel_sales, adc_close_conversations, adc_create_quotes, adc_create_reservations, adc_manage_branch_leads, adc_manage_reservations, adc_process_returns, adc_publish_customer_documents, adc_reply_conversations, adc_request_discounts, adc_review_discounts, adc_start_sales, adc_view_branch_leads, adc_view_conversations, adc_view_purchase_orders, adc_view_reports, adc_view_workspace, read
```

## ملاحظات مهنية على التوزيع الحالي

1. **لا توجد قاعدة موظفين فعلية ممثلة في هذه اللقطة.** لا يمكن تعيين الدور الصحيح لكل حساب دون ربط موثوق بين الحساب وصاحبه ووظيفته والفرع؛ الحسابات الـ22 هي حسابات مراجعة اصطناعية.
2. **المدير العام واسع الصلاحيات (43).** يجمع إدارة الموردين والتكلفة والتكاملات والصندوق الصادر والتدقيق وموافقات مالية/شراء. راجع الحاجة إلى كل مجموعة على حدة بدل اعتبار المسمى الوظيفي مبررًا تلقائيًا للوصول.
3. **المالية تجمع التسجيل والتحقق.** دور المالية يحمل `adc_record_payments` و`adc_verify_payments` وكذلك تسجيل/تحقق الاستردادات. يجب إبقاء ضوابط عدم اعتماد المعاملة الذاتية، وتحديد مراجع مستقل حيثما تسمح الموارد والطاقم.
4. **مدير المبيعات يستطيع اعتماد أوامر الشراء.** القدرة `adc_approve_purchase_orders` عابرة لمجال المبيعات؛ تحتاج تأكيد مالك المشتريات قبل اعتمادها على حسابات الموظفين.
5. **مدير المبيعات وخدمة العملاء يملكان إدارة فرص الفرع.** راجع ذلك مقابل توزيع المسؤولية بين الإسناد وخدمة العميل، ولا تمنح هذا الدور اعتمادًا على المسمى وحده.
6. **مسؤول WordPress واسع جدًا بطبيعته** ويتضمن إدارة الإضافات والقوالب والمستخدمين و`manage_options` ورفع الملفات، إضافةً إلى قدرات الأعمال. ينبغي حصره في عدد قليل من مسؤولي النظام الموثوقين وعدم استخدامه كحساب عمل يومي.
7. تخصيصات الأفراد والمنح المؤقتة ونطاق الفرع تحتاج أن تُفحص مع الدور عند مراجعة حساب بعينه؛ تعيين الفرع لا يمنح قدرة عمل، والدور وحده لا يثبت ما يستطيع المستخدم فعله على كل سجل.

## التوصية قبل أي تطبيق على الحسابات

- استخدام حساب مستخدم واحد = دور وظيفي أساسي واحد + فرع/فروع موثقة + قدرات إضافية استثنائية محددة السبب والمدة والموافق.
- إبقاء الفصل بين طالب/مسجل المعاملة ومراجعها؛ لا تُضف القدرة المقابلة مباشرة إلى كل موظفي الدور لمجرد تسهيل التشغيل.
- فصل إدارة WordPress والتكاملات/التدقيق عن أدوار العمل ما لم يعتمد مالك النظام ذلك صراحةً.
- الإبقاء على التخصيصات الحالية بدل حذفها تلقائيًا، ثم مراجعة كل استثناء موثق على حدة.
- لا تُطبق إعادة توزيع الحسابات حتى يقدم مالك النظام قائمة الحسابات التشغيلية وربط كل حساب بدوره الوظيفي وفروعه، ويعتمد أصحاب المجالات الصلاحيات المتقاطعة المبينة أعلاه.

## تحديث قالب الاعتماد بعد اللقطة

أضيف قالب `dealership_purchase_approver` بقدرتي مساحة العمل وعرض/اعتماد أوامر الشراء. أزيل اعتماد أوامر الشراء من قالب مدير المبيعات، وبقيت له صلاحية العرض. يحتفظ المدير العام باعتماد الطلبات العادية والعالية حسب القالب السابق. عند ترحيل الإضافة إلى الإصدار 1.65.3، تُزال قدرة الاعتماد من دور مدير المبيعات الافتراضي فقط، مع احترام تعريف الدور المسجل في `adc_role_definitions` والمنح الفردية؛ ولا يُنقل أي حساب تلقائيًا إلى دور المعتمد الجديد. لذلك هذه اللقطة تسجل الحالة السابقة، ويظل إسناد الدور الجديد للموظفين بحاجة إلى خريطة وظائف معتمدة.
