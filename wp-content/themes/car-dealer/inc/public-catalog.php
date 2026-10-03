<?php
/** Thin presentation adapter for the plugin-owned public catalog. */
defined( 'ABSPATH' ) || exit;

/** Public language is explicit and URL-bound; Arabic remains the safe default. */
function car_dealer_catalog_language(): string {
	return function_exists( 'adc_catalog_language' ) ? adc_catalog_language() : 'ar';
}

function car_dealer_catalog_direction(): string {
	return 'en' === car_dealer_catalog_language() ? 'ltr' : 'rtl';
}

function car_dealer_catalog_is_request(): bool {
	return function_exists( 'adc_catalog_is_request' ) && adc_catalog_is_request();
}

function car_dealer_text( string $arabic, string $english ): string {
	return 'en' === car_dealer_catalog_language() ? $english : $arabic;
}

/** Text from the theme's editorial shortcode sections, translated after rendering. */
function car_dealer_editorial_english_copy(): array {
	return array(
		'من نحن' => 'About us', 'AUTO BRANDS للسيارات' => 'AUTO BRANDS Automotive',
		'إحدى شركات ناصر مطر المطيري للسيارات' => 'A Nasser Matar Al-Mutairi Automotive company',
		'نقدّم معلومات السيارات المنشورة ووسائل التواصل مع فريق المعرض. تتحدد أي خدمات إضافية وشروطها بحسب الاتفاق المتاح لكل سيارة.' => 'We provide published vehicle information and ways to reach our dealership team. Additional services and terms depend on the agreement available for each vehicle.',
		'استكشف سياراتنا' => 'Explore our vehicles', 'تواصل معنا' => 'Contact us',
		'رسالتنا ورؤيتنا' => 'Our mission and vision', 'نحن هنا لنوفر لك أفضل تجربة' => 'A clearer way to choose your car',
		'نؤمن بأن كل عميل يستحق الأفضل، ونعمل بلا كلل لتحقيق ذلك' => 'Review vehicle details and contact our team when you need help.',
		'رسالتنا' => 'Our mission', 'نهدف إلى عرض معلومات السيارات وأسعارها المنشورة بوضوح، وتيسير التواصل مع فريق المعرض.' => 'We aim to present published vehicle information and prices clearly and make it easy to contact our team.',
		'رؤيتنا' => 'Our vision', 'تطوير تجربة واضحة لاختيار السيارة ومتابعة الطلب من الاستفسار حتى إتمام البيع.' => 'A clear experience for choosing a car and following a request from enquiry through sale.',
		'لماذا تختارنا' => 'Why choose us', 'قيمنا تميّزنا' => 'What matters to us',
		'نحن لا نبيع سيارات فقط، بل نبني علاقات ثقة مع عملائنا' => 'Clear information and helpful communication throughout your journey.',
		'معلومات السيارة' => 'Vehicle information', 'اطّلع على المواصفات والحالة المنشورة لكل سيارة، واطلب الوثائق والتفاصيل قبل الشراء.' => 'Review published specifications and condition, and ask for documents and details before buying.',
		'أسعار منشورة' => 'Published prices', 'قارن الأسعار المعروضة واستفسر عن الشروط المتاحة لكل سيارة.' => 'Compare advertised prices and ask about the terms available for each vehicle.',
		'خدمة عملاء ممتازة' => 'Customer support', 'يمكنك إرسال استفسارك عبر قنوات التواصل المتاحة وسيتابعه فريق المعرض.' => 'Send an enquiry through the available contact channels for our team to review.',
		'تسليم سريع' => 'Delivery arrangements', 'تُحدد خطوات التسليم ومستنداته في اتفاق البيع المعتمد.' => 'Delivery steps and documents are set out in the approved sale agreement.',
		'تفاصيل مكتوبة' => 'Written details', 'راجع مستندات البيع والضمان والشروط المتاحة قبل الموافقة النهائية.' => 'Review the sale documents, warranty, and available terms before final approval.',
		'الإدارة العليا' => 'Leadership', 'خبراء في المجال' => 'Our team', 'إدارة مؤسسة وكفاءات عالية' => 'Meet the people behind the dealership.',
		'الاستاذ/ ناصر مطر المطيري' => 'Mr. Nasser Matar Al-Mutairi', 'الرئيس المؤسس ' => 'Founding President',
		'خبرة 30 سنة في تجارة السيارات' => '30 years in the automotive trade',
		'الاستاذ/ محمد مطر المطيري' => 'Mr. Mohammed Matar Al-Mutairi', 'المدير التنفيذي' => 'Executive Director',
		'خبرة 25 سنة في معارض السيارات' => '25 years in car dealerships',
		'الاستاذ/ عاطف يوسف الشافعي' => 'Mr. Atef Youssef Al-Shafei', 'المدير العام' => 'General Manager',
		'خبرة 40 سنة في مجال السيارات' => '40 years in the automotive sector',
		'الاستاذة/ نجلاء ناصر المطيري' => 'Ms. Najla Nasser Al-Mutairi', 'مدير الموارد البشرية' => 'Human Resources Manager',
		'خبرة 6 سنوات في مجال السيارات' => '6 years in the automotive sector',
		'جاهز لتجربة سيارة جديدة؟' => 'Ready to explore a new car?',
		'تواصل مع فريق المعرض للاستفسار عن السيارات والشروط المتاحة.' => 'Contact our team about available vehicles and terms.',
		'تواصل معنا الآن' => 'Contact us now', 'تواصل عبر واتساب' => 'Message us on WhatsApp',
		'نحن هنا لمساعدتك' => 'How can we help?',
		'فريقنا جاهز للإجابة على جميع استفساراتك ومساعدتك في اختيار السيارة المناسبة لك. تواصل معنا عبر أي من القنوات أدناه.' => 'Contact our team about vehicles and available options using the channels below.',
		'العنوان' => 'Address', 'الهاتف' => 'Phone', 'البريد الإلكتروني' => 'Email address',
		'أرسل لنا رسالة' => 'Send us a message', 'املأ النموذج أدناه وسوف نتواصل معك في أقرب وقت' => 'Complete the form below and our team will review your message.',
		'الأسئلة الشائعة' => 'Frequently asked questions', 'الأسئلة المتكررة' => 'Common questions',
		'إجابات على أكثر الأسئلة شيوعاً' => 'Answers to common questions.',
		'ما هي سياسات الضمان المتوفرة لديكم؟' => 'What warranty terms are available?',
		'تختلف تغطية الضمان ومدته حسب السيارة والجهة المزوّدة. اطلب التفاصيل المكتوبة قبل إتمام الشراء.' => 'Coverage and duration vary by vehicle and provider. Request written details before purchase.',
		'هل تقدمون خدمات التمويل؟' => 'Is financing available?',
		'تُعرض خيارات التمويل وشروطها بعد توفر مزود معتمد ودراسة الطلب. الحاسبة في الموقع تقديرية فقط.' => 'Financing options and terms are provided after an approved provider becomes available and reviews the application. The calculator is for estimates only.',
		'هل يمكنني تجربة قيادة السيارة؟' => 'Can I request a test drive?',
		'يمكنك إرسال طلب تجربة قيادة، ويؤكد المعرض الموعد والتوفر قبل الزيارة.' => 'You can request a test drive. The dealership will confirm availability and the appointment before your visit.',
		'ما هي خدمات ما بعد البيع؟' => 'What after-sales services are available?', 'تلميع.' => 'Vehicle detailing.',
		'تابعنا على وسائل التواصل' => 'Follow us', 'ابقَ على اطلاع بآخر العروض والمبادرات الجديدة' => 'See our latest published offers and updates.',
		'فيسبوك' => 'Facebook', 'إنستغرام' => 'Instagram', 'واتساب' => 'WhatsApp',
	);
}

function car_dealer_account_english_copy(): array {
	return array(
		'الحساب غير متاح حاليًا' => 'Account currently unavailable',
		'خدمة المعرض غير متاحة. يرجى المحاولة لاحقًا.' => 'The dealership service is unavailable. Please try again later.',
		'أهلاً بك في ' => 'Welcome to ', 'ابدأ رحلتك معنا' => 'Get started', 'سعداء بعودتك' => 'Welcome back',
		'حساب واحد لمتابعة طلباتك وحجوزات تجربة القيادة والتواصل مع المعرض.' => 'Use one account to follow your requests, test drive bookings, and messages.',
		'استكشف السيارات' => 'Explore vehicles', 'تسجيل الدخول' => 'Log in', 'إنشاء حساب' => 'Create account',
		'الاسم الكامل' => 'Full name', 'البريد الإلكتروني أو اسم المستخدم' => 'Email or username',
		'البريد الإلكتروني' => 'Email address', 'رقم الهاتف' => 'Phone number', '(اختياري)' => '(optional)',
		'كلمة المرور' => 'Password', 'استخدم 10 أحرف على الأقل.' => 'Use at least 10 characters.',
		'تأكيد كلمة المرور' => 'Confirm password', 'أوافق على ' => 'I agree to the ',
		'سياسة الخصوصية' => 'Privacy policy', 'شروط الاستخدام' => 'Terms of use',
		'تذكرني' => 'Remember me', 'نسيت كلمة المرور؟' => 'Forgot your password?',
		'مدير الموقع' => 'Site administrator', 'مدير المعرض' => 'Dealership manager',
		'مستشار المبيعات' => 'Sales adviser', 'موظف المعرض' => 'Dealership staff', 'حساب العميل' => 'Customer account',
		'مرحباً، ' => 'Hello, ', 'كل ما تحتاجه لإدارة حسابك في مكان واحد.' => 'Manage your account in one place.',
		'تسجيل الخروج' => 'Log out', 'تم تحديث بياناتك.' => 'Your details were updated.',
		'تم حفظ تفضيلات التواصل التسويقي.' => 'Your marketing preferences were saved.',
		'تم تحديث الحجز وإبلاغ المعرض داخل لوحة الإدارة.' => 'The booking was updated and the dealership was notified.',
		'مساحة العمل' => 'Workspace', 'مساحة عمليات المعرض' => 'Dealership operations',
		'الوصول إلى العمليات المسموح بها حسب دورك وفرعك' => 'Access operations allowed for your role and branch.',
		'العملاء والمتابعات' => 'Customers and follow ups', 'العملاء المسندون إليك' => 'Customers assigned to you',
		'إدارة علاقات العملاء وفرص البيع' => 'Manage customer relationships and sales opportunities.',
		'رسائل العملاء' => 'Customer messages', 'متابعة طلبات التواصل والبيع الواقعة ضمن نطاقك' => 'Review messages and sales requests in your scope.',
		'حجوزات التجربة' => 'Test drive bookings', 'متابعة مواعيد تجربة القيادة الواقعة ضمن نطاقك' => 'Review test drive appointments in your scope.',
		'النشرة البريدية' => 'Newsletter', 'عرض موافقات الاشتراك التسويقي الحالية' => 'View current marketing consents.',
		'إضافة سيارة منشورة' => 'Add published vehicle', 'إنشاء صفحة السيارة التحريرية في الموقع' => 'Create a vehicle listing page.',
		'المخزون التشغيلي' => 'Inventory operations', 'متابعة المركبات وحالاتها التشغيلية' => 'Review vehicles and their operational status.',
		'المستخدمون والصلاحيات' => 'Users and permissions', 'إدارة حسابات فريق العمل' => 'Manage staff accounts.',
		'إعدادات المنصة' => 'Platform settings', 'إدارة إعدادات ووحدات منصة المعرض' => 'Manage dealership platform settings and modules.',
		'تصفح السيارات واحجز تجربة قيادة' => 'Browse vehicles and request a test drive',
		'إرسال رسالة إلى المعرض' => 'Message the dealership',
		'ستظهر الرسالة ورد المعرض ضمن طلباتك في هذه الصفحة.' => 'Your message and the dealership reply will appear in your requests.',
		'بياناتي الشخصية' => 'My details', 'الاسم' => 'Name', 'الهاتف' => 'Phone',
		'حفظ البيانات' => 'Save details', 'إعادة تعيين كلمة المرور' => 'Reset password',
		'تفضيلات التواصل' => 'Communication preferences',
		'اختر ما إذا كنت ترغب في استقبال عروض وأخبار تسويقية. رسائل الطلبات والحجوزات والخدمة اللازمة لتنفيذ طلبك لا تتأثر بهذا الاختيار.' => 'Choose whether to receive marketing offers and news. Essential request, booking, and service messages are unaffected.',
		'التواصل التسويقي' => 'Marketing communication',
		'أوافق على استقبال العروض والأخبار التسويقية.' => 'I agree to receive marketing offers and news.',
		'لا أرغب في استقبال تواصل تسويقي.' => 'I do not want marketing communication.',
		'آخر موافقة مسجلة: ' => 'Last recorded consent: ', 'حفظ التفضيلات' => 'Save preferences',
		'تعذر تحميل التفضيلات حالياً. حاول مرة أخرى لاحقاً.' => 'Preferences could not be loaded. Please try again later.',
		'حجوزات تجربة القيادة' => 'Test drive bookings', 'طلباتي ورسائلي' => 'My requests and messages',
		'جدول حجوزات تجربة القيادة' => 'Test drive bookings table', 'جدول الطلبات والرسائل' => 'Requests and messages table',
		'الطلب' => 'Request', 'السيارة' => 'Vehicle', 'التاريخ' => 'Date', 'الحالة' => 'Status',
		'السيارة غير متاحة حاليًا' => 'Vehicle currently unavailable', 'طلب عام' => 'General request',
		'رد المعرض:' => 'Dealership reply:', 'آخر تحديث: ' => 'Last updated: ', 'إلغاء الحجز' => 'Cancel booking',
		'السابق' => 'Previous', 'التالي' => 'Next',
		'قيد الانتظار' => 'Pending', 'مؤكد' => 'Confirmed', 'مكتمل' => 'Completed',
		'ملغى' => 'Cancelled', 'جديد' => 'New', 'قيد المتابعة' => 'In progress',
	);
}

/** English public UI copy while Arabic remains the default theme language. */
function car_dealer_catalog_english_copy(): array {
	static $copy = null;
	if ( null !== $copy ) {
		return $copy;
	}
	$copy = array(
		'استعرض سيارات AUTO BRANDS' => 'Browse AUTO BRANDS Vehicles',
		'فلترة دقيقة حسب الماركة، الموديل، السنة، السعر، نوع السيارة، الوقود وناقل الحركة.' => 'Filter by brand, model, year, price, body type, fuel and transmission.',
		'السابق' => 'Previous', 'التالي' => 'Next', 'لا توجد سيارات مطابقة للبحث.' => 'No vehicles match your search.',
		'بحث' => 'Search', 'الماركة أو الموديل' => 'Brand or model', 'الماركة أو الموديل أو رقم المخزون' => 'Brand, model or stock number',
		'الماركة' => 'Brand', 'كل الماركات' => 'All brands', 'نوع السيارة' => 'Vehicle type', 'كل الأنواع' => 'All types',
		'الموديل' => 'Model', 'الفئة' => 'Trim', 'السنة من' => 'Year from', 'السنة إلى' => 'Year to',
		'السعر من (ريال)' => 'Price from (SAR)', 'السعر إلى (ريال)' => 'Price to (SAR)', 'السعر إلى' => 'Price to',
		'الممشى من (كم)' => 'Mileage from (km)', 'الممشى إلى (كم)' => 'Mileage to (km)', 'نوع الهيكل' => 'Body type',
		'الوقود' => 'Fuel', 'ناقل الحركة' => 'Transmission', 'المحرك' => 'Engine', 'نظام الدفع' => 'Drivetrain',
		'اللون الخارجي' => 'Exterior color', 'اللون الداخلي' => 'Interior color', 'الحالة' => 'Condition',
		'الفرع' => 'Branch', 'كل الفروع' => 'All branches', 'الترتيب' => 'Sort', 'الأحدث' => 'Newest',
		'السعر: الأقل أولًا' => 'Price: low to high', 'السعر: الأعلى أولًا' => 'Price: high to low',
		'سنة الصنع' => 'Model year', 'الأقل ممشى' => 'Lowest mileage', 'الكل' => 'All',
		'جديدة' => 'New', 'مستعملة' => 'Used', 'بنزين' => 'Gasoline', 'ديزل' => 'Diesel', 'هجين' => 'Hybrid',
		'كهربائي' => 'Electric', 'أوتوماتيكي' => 'Automatic', 'يدوي' => 'Manual', 'تطبيق' => 'Apply',
		'تطبيق الفلاتر' => 'Apply filters', 'مسح الفلاتر' => 'Clear filters', 'تصفية مخزون السيارات' => 'Filter vehicle inventory',
		'مميزة' => 'Featured', 'سيارة متاحة' => 'Available vehicle', 'عرض التفاصيل' => 'View details', 'قسط يبدأ من %s' => 'Monthly payment from %s',
		'عرض تفاصيل %s' => 'View %s details',
		'متاحة' => 'Available', 'محجوزة' => 'Reserved', 'مباعة' => 'Sold', 'إزالة من المقارنة' => 'Remove from comparison', 'أضف للمقارنة' => 'Add to comparison',
		'اطلب السعر' => 'Request price', 'احجز تجربة قيادة' => 'Book a test drive', 'اطلب تمويل' => 'Request financing',
		'تواصل واتساب' => 'Contact on WhatsApp', 'الوصف' => 'Description', 'المزايا' => 'Features', 'المواصفات' => 'Specifications',
		'حالة المخزون' => 'Inventory status', 'اللون' => 'Color', 'القوة' => 'Power', 'الأبواب' => 'Doors', 'المقاعد' => 'Seats',
		'الممشى' => 'Mileage', 'رقم المخزون' => 'Stock number', 'إرسال طلب السعر' => 'Send price request',
		'إرسال طلب التمويل' => 'Send financing request', 'إرسال الطلب' => 'Send request', 'الاسم' => 'Name',
		'البريد الإلكتروني' => 'Email address', 'الهاتف' => 'Phone', 'اليوم' => 'Date', 'الوقت' => 'Time',
		'ملاحظات إضافية' => 'Additional notes', 'حاسبة التمويل' => 'Finance Calculator', 'قدّر قسطك الشهري' => 'Estimate your monthly payment', 'سعر السيارة: %s' => 'Vehicle price: %s',
		'سعر السيارة' => 'Vehicle price', 'الدفعة الأولى' => 'Down payment', 'النسبة السنوية التقديرية %' => 'Estimated annual rate %',
		'المدة بالأشهر' => 'Term in months', 'القسط الشهري التقديري:' => 'Estimated monthly payment:',
		'هذا تقدير إرشادي وليس عرض تمويل أو موافقة. تعتمد الشروط النهائية على مزود التمويل.' => 'This is an estimate, not a financing offer or approval. Final terms depend on the provider.',
		'مبلغ التمويل' => 'Finance amount', 'المدة (سنوات)' => 'Term (years)', 'احسب' => 'Calculate',
		'مشاركة:' => 'Share:', 'واتساب' => 'WhatsApp', 'تويتر' => 'X / Twitter', 'فيسبوك' => 'Facebook',
		'الانتقال إلى المحتوى' => 'Skip to content', 'فتح القائمة' => 'Open menu', 'القائمة الرئيسية' => 'Primary navigation',
		'ابحث' => 'Search', 'ابحث عن سيارة' => 'Search for a vehicle', 'تواصل عبر واتساب' => 'Contact on WhatsApp',
		'تواصل معنا' => 'Contact us', 'العودة إلى الأعلى' => 'Back to top', 'اشترك' => 'Subscribe',
		'الكتالوج غير متاح حاليًا. يرجى التواصل مع إدارة الموقع.' => 'The catalog is currently unavailable. Please contact the site administrator.',
		'اختر سيارتك.. وابدأ رحلتك بثقة' => 'Find your next car with confidence',
		'استعرض السيارات والعروض المنشورة، وتعرّف على تفاصيلها قبل إرسال طلبك إلى فريق المعرض.' => 'Browse published vehicles and offers, then review their details before contacting our team.',
		'استعرض السيارات' => 'Browse vehicles', 'سيارة منشورة' => 'Published vehicles', 'تصفح الآن' => 'Browse now', 'اكتشف المزيد' => 'Discover more',
		'مختارة بعناية' => 'Selected for you', 'السيارات المميزة' => 'Featured vehicles',
		'أفضل السيارات التي نرشحها لك بعناية فائقة' => 'Explore vehicles selected for this section.', 'عرض الكل' => 'View all',
		'ثقة وخبرة' => 'Clear information', 'لماذا AUTO BRANDS؟' => 'Why AUTO BRANDS?',
		'نقدم لك تجربة شراء لا مثيل لها' => 'Explore vehicles and contact our team with confidence.',
		'اختيار متنوع' => 'Vehicle selection',
		'استعرض السيارات المنشورة وقارن بين مواصفاتها لاختيار ما يناسب احتياجك.' => 'Compare published vehicle specifications to find the right fit.',
		'حلول تمويل' => 'Financing options',
		'استخدم الحاسبة للاطلاع على تقدير أولي، وتعرّف على الخيارات المتاحة عند توفيرها.' => 'Use the calculator for an initial estimate. Financing options depend on provider availability.',
		'فريق مبيعات سريع' => 'Contact our sales team', 'أرسل استفسارك عبر الموقع ليتابعه فريق المعرض.' => 'Send an enquiry through the website for our team to review.',
		'تفاصيل واضحة' => 'Clear details', 'اطلع على معلومات كل سيارة وشروطها المنشورة قبل إرسال طلبك.' => 'Review each vehicle’s published details and terms before sending a request.',
		'وصل حديثاً' => 'Recently added', 'أحدث السيارات' => 'Latest vehicles',
		'آخر الإضافات لمخزوننا المتجدد باستمرار' => 'The latest vehicles published in our catalog.', 'تصفح المخزون' => 'Browse inventory',
		'عروض محدودة' => 'Published offers', 'العروض الحالية' => 'Current offers',
		'لا تفوّت هذه الفرص الاستثنائية' => 'Explore the offers currently available.', 'كل العروض' => 'All offers',
		'سيارات مختارة' => 'Selected vehicles', 'من الكتالوج' => 'From the catalog',
		'استعرض سيارات منشورة اختارها فريق المعرض لهذا القسم.' => 'Browse published vehicles selected for this section.',
		'جاهز لاختيار سيارتك القادمة؟' => 'Ready to choose your next car?',
		'اختر سيارة وأرسل طلبك عبر الموقع.' => 'Choose a vehicle and send your request online.',
		'تمويل' => 'Financing', 'احسب فرصتك التمويلية' => 'Estimate your financing',
		'احسب قسطًا تقديريًا للمقارنة الأولية. شروط التمويل الفعلية تعتمد على مزود الخدمة عند توفره.' => 'Calculate an initial payment estimate. Actual financing terms depend on an available provider.',
		'إرسال الطلبات غير متاح حاليًا. يرجى المحاولة لاحقًا.' => 'Requests are temporarily unavailable. Please try again later.',
		'أوافق على استلام رسائل وتسويق المعرض ويمكنني إلغاء الاشتراك لاحقًا.' => 'I agree to receive marketing messages from the dealership. I can unsubscribe later.',
		'خدمة الحساب غير متاحة حاليًا. يرجى المحاولة لاحقًا.' => 'Account service is temporarily unavailable. Please try again later.',
		'سجل الطلبات غير متاح حاليًا.' => 'Request history is temporarily unavailable.', 'طلباتي' => 'My requests',
		'لا توجد طلبات في هذه الصفحة حاليًا.' => 'There are no requests on this page.',
		'مرّر الجدول أفقيًا لعرض بقية التفاصيل.' => 'Scroll the table horizontally to see all details.',
		'الموقع' => 'Location', 'عرض الموقع على الخريطة' => 'View on map',
		'عروض AUTO BRANDS' => 'AUTO BRANDS Offers',
		'عروض مختارة، أقساط مرنة، وفرص محدودة على سيارات تناسب رحلتك القادمة.' => 'Explore currently published offers and review the terms for each vehicle.',
		'العروض غير متاحة حاليًا. يرجى المحاولة لاحقًا.' => 'Offers are temporarily unavailable. Please try again later.',
		'لا توجد عروض منشورة حالياً.' => 'There are no published offers at the moment.',
		'بيانات العرض غير متاحة حاليًا.' => 'Offer details are temporarily unavailable.',
		'عرض AUTO BRANDS' => 'AUTO BRANDS Offer', 'احصل على العرض' => 'Ask about this offer',
	);
	return $copy;
}

function car_dealer_catalog_gettext( string $translation, string $text, string $domain ): string {
	if ( 'car-dealer' !== $domain || 'en' !== car_dealer_catalog_language() || ( is_admin() && ! wp_doing_ajax() ) ) {
		return $translation;
	}
	$copy = car_dealer_catalog_english_copy();
	return $copy[ $text ] ?? $translation;
}
add_filter( 'gettext', 'car_dealer_catalog_gettext', 10, 3 );

function car_dealer_catalog_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
	if ( 'car-dealer' === $domain && 'en' === car_dealer_catalog_language() && car_dealer_catalog_is_request() ) {
		return 1 === $number ? '1 matching vehicle' : '%s matching vehicles';
	}
	return $translation;
}
add_filter( 'ngettext', 'car_dealer_catalog_ngettext', 10, 5 );

function car_dealer_catalog_localized_url( string $url, string $language = '' ): string {
	if ( '' === $url || '#' === $url[0] ) { return $url; }
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	if ( $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) { return $url; }
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	if ( $host && $site_host && strtolower( $host ) !== strtolower( $site_host ) ) { return $url; }
	return function_exists( 'adc_catalog_localized_url' ) ? adc_catalog_localized_url( $url, $language ) : $url;
}

function car_dealer_catalog_language_url( string $language ): string {
	return function_exists( 'adc_catalog_language_url' ) ? adc_catalog_language_url( $language ) : '';
}

function car_dealer_catalog_language_switch(): void {
	if ( ! function_exists( 'adc_catalog_language_url' ) ) { return; }
	$current = car_dealer_catalog_language();
	?>
	<nav class="catalog-language-switch site-language-switch" aria-label="<?php echo esc_attr( 'en' === $current ? 'Website language' : 'لغة الموقع' ); ?>">
		<a lang="ar" dir="rtl" hreflang="ar" href="<?php echo esc_url( car_dealer_catalog_language_url( 'ar' ) ); ?>" <?php echo 'ar' === $current ? 'aria-current="page"' : ''; ?>>العربية</a>
		<a lang="en" dir="ltr" hreflang="en" href="<?php echo esc_url( car_dealer_catalog_language_url( 'en' ) ); ?>" <?php echo 'en' === $current ? 'aria-current="page"' : ''; ?>>English</a>
	</nav>
	<?php
}

function car_dealer_catalog_format_price( $price ): string {
	if ( ! $price ) {
		return '';
	}
	$amount = (float) $price;
	return 'en' === car_dealer_catalog_language() ? 'SAR ' . number_format( $amount, floor( $amount ) === $amount ? 0 : 2 ) : car_dealer_format_price( $price );
}

function car_dealer_catalog_distance( $distance ): string {
	return number_format_i18n( (int) $distance ) . ( 'en' === car_dealer_catalog_language() ? ' km' : ' كم' );
}

function car_dealer_catalog_value_label( string $value ): string {
	if ( 'en' !== car_dealer_catalog_language() ) {
		return $value;
	}
	$labels = array(
		'new'=>'New', 'used'=>'Used', 'gasoline'=>'Gasoline', 'diesel'=>'Diesel', 'hybrid'=>'Hybrid', 'electric'=>'Electric',
		'automatic'=>'Automatic', 'manual'=>'Manual', 'suv'=>'SUV', 'sedan'=>'Sedan', 'coupe'=>'Coupe', 'hatchback'=>'Hatchback',
		'pickup'=>'Pickup', 'van'=>'Van', 'fwd'=>'FWD', 'rwd'=>'RWD', 'awd'=>'AWD', '4wd'=>'4WD',
	);
	return $labels[ strtolower( $value ) ] ?? $value;
}

function car_dealer_vehicle_view( int $post_id, bool $preview = false ): ?array {
	return function_exists( 'adc_public_vehicle_view' ) ? adc_public_vehicle_view( $post_id, $preview ) : null;
}
