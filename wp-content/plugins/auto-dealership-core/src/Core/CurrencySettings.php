<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Central currency registry and first-install currency guard. */
final class CurrencySettings {
	private const OPTION = 'adc_base_currency_code';

	/** Active ISO 4217 currencies supported by the first-party dashboard; fund and historical codes are excluded. */
	private const CURRENCIES = array(
		'AED'=>array('numeric'=>'784','name_ar'=>'درهم إماراتي','name_en'=>'United Arab Emirates Dirham','symbol'=>'د.إ','decimals'=>2),
		'AFN'=>array('numeric'=>'971','name_ar'=>'أفغاني','name_en'=>'Afghan Afghani','symbol'=>'AFN','decimals'=>2),
		'ALL'=>array('numeric'=>'008','name_ar'=>'ليك ألباني','name_en'=>'Albanian Lek','symbol'=>'ALL','decimals'=>2),
		'AMD'=>array('numeric'=>'051','name_ar'=>'درام أرميني','name_en'=>'Armenian Dram','symbol'=>'AMD','decimals'=>2),
		'AOA'=>array('numeric'=>'973','name_ar'=>'كوانزا أنغولي','name_en'=>'Angolan Kwanza','symbol'=>'AOA','decimals'=>2),
		'ARS'=>array('numeric'=>'032','name_ar'=>'بيزو أرجنتيني','name_en'=>'Argentine Peso','symbol'=>'ARS','decimals'=>2),
		'AUD'=>array('numeric'=>'036','name_ar'=>'دولار أسترالي','name_en'=>'Australian Dollar','symbol'=>'A$','decimals'=>2),
		'AWG'=>array('numeric'=>'533','name_ar'=>'فلورن أروبي','name_en'=>'Aruban Florin','symbol'=>'AWG','decimals'=>2),
		'AZN'=>array('numeric'=>'944','name_ar'=>'مانات أذربيجان','name_en'=>'Azerbaijani Manat','symbol'=>'AZN','decimals'=>2),
		'BAM'=>array('numeric'=>'977','name_ar'=>'مارك البوسنة والهرسك قابل للتحويل','name_en'=>'Bosnia-Herzegovina Convertible Mark','symbol'=>'BAM','decimals'=>2),
		'BBD'=>array('numeric'=>'052','name_ar'=>'دولار بربادوسي','name_en'=>'Barbadian Dollar','symbol'=>'BBD','decimals'=>2),
		'BDT'=>array('numeric'=>'050','name_ar'=>'تاكا بنغلاديشي','name_en'=>'Bangladeshi Taka','symbol'=>'BDT','decimals'=>2),
		'BHD'=>array('numeric'=>'048','name_ar'=>'دينار بحريني','name_en'=>'Bahraini Dinar','symbol'=>'د.ب','decimals'=>3),
		'BIF'=>array('numeric'=>'108','name_ar'=>'فرنك بروندي','name_en'=>'Burundian Franc','symbol'=>'BIF','decimals'=>0),
		'BMD'=>array('numeric'=>'060','name_ar'=>'دولار برمودي','name_en'=>'Bermudan Dollar','symbol'=>'BMD','decimals'=>2),
		'BND'=>array('numeric'=>'096','name_ar'=>'دولار بروناي','name_en'=>'Brunei Dollar','symbol'=>'BND','decimals'=>2),
		'BOB'=>array('numeric'=>'068','name_ar'=>'بوليفيانو بوليفي','name_en'=>'Bolivian Boliviano','symbol'=>'BOB','decimals'=>2),
		'BRL'=>array('numeric'=>'986','name_ar'=>'ريال برازيلي','name_en'=>'Brazilian Real','symbol'=>'R$','decimals'=>2),
		'BSD'=>array('numeric'=>'044','name_ar'=>'دولار باهامي','name_en'=>'Bahamian Dollar','symbol'=>'BSD','decimals'=>2),
		'BTN'=>array('numeric'=>'064','name_ar'=>'نولتوم بوتاني','name_en'=>'Bhutanese Ngultrum','symbol'=>'BTN','decimals'=>2),
		'BWP'=>array('numeric'=>'072','name_ar'=>'بولا بتسواني','name_en'=>'Botswanan Pula','symbol'=>'BWP','decimals'=>2),
		'BYN'=>array('numeric'=>'933','name_ar'=>'روبل بيلاروسي','name_en'=>'Belarusian Ruble','symbol'=>'BYN','decimals'=>2),
		'BZD'=>array('numeric'=>'084','name_ar'=>'دولار بليزي','name_en'=>'Belize Dollar','symbol'=>'BZD','decimals'=>2),
		'CAD'=>array('numeric'=>'124','name_ar'=>'دولار كندي','name_en'=>'Canadian Dollar','symbol'=>'C$','decimals'=>2),
		'CDF'=>array('numeric'=>'976','name_ar'=>'فرنك كونغولي','name_en'=>'Congolese Franc','symbol'=>'CDF','decimals'=>2),
		'CHF'=>array('numeric'=>'756','name_ar'=>'فرنك سويسري','name_en'=>'Swiss Franc','symbol'=>'CHF','decimals'=>2),
		'CLP'=>array('numeric'=>'152','name_ar'=>'بيزو تشيلي','name_en'=>'Chilean Peso','symbol'=>'CLP','decimals'=>0),
		'CNY'=>array('numeric'=>'156','name_ar'=>'يوان صيني','name_en'=>'Chinese Yuan','symbol'=>'¥','decimals'=>2),
		'COP'=>array('numeric'=>'170','name_ar'=>'بيزو كولومبي','name_en'=>'Colombian Peso','symbol'=>'COP','decimals'=>2),
		'CRC'=>array('numeric'=>'188','name_ar'=>'كولن كوستاريكي','name_en'=>'Costa Rican Colón','symbol'=>'CRC','decimals'=>2),
		'CUP'=>array('numeric'=>'192','name_ar'=>'بيزو كوبي','name_en'=>'Cuban Peso','symbol'=>'CUP','decimals'=>2),
		'CVE'=>array('numeric'=>'132','name_ar'=>'اسكودو الرأس الأخضر','name_en'=>'Cape Verdean Escudo','symbol'=>'CVE','decimals'=>2),
		'CZK'=>array('numeric'=>'203','name_ar'=>'كرونة تشيكية','name_en'=>'Czech Koruna','symbol'=>'CZK','decimals'=>2),
		'DJF'=>array('numeric'=>'262','name_ar'=>'فرنك جيبوتي','name_en'=>'Djiboutian Franc','symbol'=>'DJF','decimals'=>0),
		'DKK'=>array('numeric'=>'208','name_ar'=>'كرونة دنماركية','name_en'=>'Danish Krone','symbol'=>'kr','decimals'=>2),
		'DOP'=>array('numeric'=>'214','name_ar'=>'بيزو الدومنيكان','name_en'=>'Dominican Peso','symbol'=>'DOP','decimals'=>2),
		'DZD'=>array('numeric'=>'012','name_ar'=>'دينار جزائري','name_en'=>'Algerian Dinar','symbol'=>'DZD','decimals'=>2),
		'EGP'=>array('numeric'=>'818','name_ar'=>'جنيه مصري','name_en'=>'Egyptian Pound','symbol'=>'ج.م','decimals'=>2),
		'ERN'=>array('numeric'=>'232','name_ar'=>'ناكفا أريتري','name_en'=>'Eritrean Nakfa','symbol'=>'ERN','decimals'=>2),
		'ETB'=>array('numeric'=>'230','name_ar'=>'بير أثيوبي','name_en'=>'Ethiopian Birr','symbol'=>'ETB','decimals'=>2),
		'EUR'=>array('numeric'=>'978','name_ar'=>'يورو','name_en'=>'Euro','symbol'=>'€','decimals'=>2),
		'FJD'=>array('numeric'=>'242','name_ar'=>'دولار فيجي','name_en'=>'Fijian Dollar','symbol'=>'FJD','decimals'=>2),
		'FKP'=>array('numeric'=>'238','name_ar'=>'جنيه جزر فوكلاند','name_en'=>'Falkland Islands Pound','symbol'=>'FKP','decimals'=>2),
		'GBP'=>array('numeric'=>'826','name_ar'=>'جنيه إسترليني','name_en'=>'British Pound','symbol'=>'£','decimals'=>2),
		'GEL'=>array('numeric'=>'981','name_ar'=>'لارى جورجي','name_en'=>'Georgian Lari','symbol'=>'GEL','decimals'=>2),
		'GHS'=>array('numeric'=>'936','name_ar'=>'سيدي غانا','name_en'=>'Ghanaian Cedi','symbol'=>'GHS','decimals'=>2),
		'GIP'=>array('numeric'=>'292','name_ar'=>'جنيه جبل طارق','name_en'=>'Gibraltar Pound','symbol'=>'GIP','decimals'=>2),
		'GMD'=>array('numeric'=>'270','name_ar'=>'دلاسي غامبي','name_en'=>'Gambian Dalasi','symbol'=>'GMD','decimals'=>2),
		'GNF'=>array('numeric'=>'324','name_ar'=>'فرنك غينيا','name_en'=>'Guinean Franc','symbol'=>'GNF','decimals'=>0),
		'GTQ'=>array('numeric'=>'320','name_ar'=>'كوتزال غواتيمالا','name_en'=>'Guatemalan Quetzal','symbol'=>'GTQ','decimals'=>2),
		'GYD'=>array('numeric'=>'328','name_ar'=>'دولار غيانا','name_en'=>'Guyanaese Dollar','symbol'=>'GYD','decimals'=>2),
		'HKD'=>array('numeric'=>'344','name_ar'=>'دولار هونغ كونغ','name_en'=>'Hong Kong Dollar','symbol'=>'HK$','decimals'=>2),
		'HNL'=>array('numeric'=>'340','name_ar'=>'ليمبيرا هنداروس','name_en'=>'Honduran Lempira','symbol'=>'HNL','decimals'=>2),
		'HTG'=>array('numeric'=>'332','name_ar'=>'جوردى هايتي','name_en'=>'Haitian Gourde','symbol'=>'HTG','decimals'=>2),
		'HUF'=>array('numeric'=>'348','name_ar'=>'فورينت هنغاري','name_en'=>'Hungarian Forint','symbol'=>'HUF','decimals'=>2),
		'IDR'=>array('numeric'=>'360','name_ar'=>'روبية إندونيسية','name_en'=>'Indonesian Rupiah','symbol'=>'Rp','decimals'=>2),
		'ILS'=>array('numeric'=>'376','name_ar'=>'شيكل إسرائيلي جديد','name_en'=>'Israeli New Shekel','symbol'=>'ILS','decimals'=>2),
		'INR'=>array('numeric'=>'356','name_ar'=>'روبية هندية','name_en'=>'Indian Rupee','symbol'=>'₹','decimals'=>2),
		'IQD'=>array('numeric'=>'368','name_ar'=>'دينار عراقي','name_en'=>'Iraqi Dinar','symbol'=>'IQD','decimals'=>3),
		'IRR'=>array('numeric'=>'364','name_ar'=>'ريال إيراني','name_en'=>'Iranian Rial','symbol'=>'IRR','decimals'=>2),
		'ISK'=>array('numeric'=>'352','name_ar'=>'كرونة أيسلندية','name_en'=>'Icelandic Króna','symbol'=>'ISK','decimals'=>0),
		'JMD'=>array('numeric'=>'388','name_ar'=>'دولار جامايكي','name_en'=>'Jamaican Dollar','symbol'=>'JMD','decimals'=>2),
		'JOD'=>array('numeric'=>'400','name_ar'=>'دينار أردني','name_en'=>'Jordanian Dinar','symbol'=>'JOD','decimals'=>3),
		'JPY'=>array('numeric'=>'392','name_ar'=>'ين ياباني','name_en'=>'Japanese Yen','symbol'=>'¥','decimals'=>0),
		'KES'=>array('numeric'=>'404','name_ar'=>'شلن كينيي','name_en'=>'Kenyan Shilling','symbol'=>'KES','decimals'=>2),
		'KGS'=>array('numeric'=>'417','name_ar'=>'سوم قيرغستاني','name_en'=>'Kyrgyz Som','symbol'=>'KGS','decimals'=>2),
		'KHR'=>array('numeric'=>'116','name_ar'=>'رييال كمبودي','name_en'=>'Cambodian Riel','symbol'=>'KHR','decimals'=>2),
		'KMF'=>array('numeric'=>'174','name_ar'=>'فرنك جزر القمر','name_en'=>'Comorian Franc','symbol'=>'KMF','decimals'=>0),
		'KPW'=>array('numeric'=>'408','name_ar'=>'وون كوريا الشمالية','name_en'=>'North Korean Won','symbol'=>'KPW','decimals'=>2),
		'KRW'=>array('numeric'=>'410','name_ar'=>'وون كوري','name_en'=>'South Korean Won','symbol'=>'₩','decimals'=>0),
		'KWD'=>array('numeric'=>'414','name_ar'=>'دينار كويتي','name_en'=>'Kuwaiti Dinar','symbol'=>'د.ك','decimals'=>3),
		'KYD'=>array('numeric'=>'136','name_ar'=>'دولار جزر كيمن','name_en'=>'Cayman Islands Dollar','symbol'=>'KYD','decimals'=>2),
		'KZT'=>array('numeric'=>'398','name_ar'=>'تينغ كازاخستاني','name_en'=>'Kazakhstani Tenge','symbol'=>'KZT','decimals'=>2),
		'LAK'=>array('numeric'=>'418','name_ar'=>'كيب لاوسي','name_en'=>'Laotian Kip','symbol'=>'LAK','decimals'=>2),
		'LBP'=>array('numeric'=>'422','name_ar'=>'جنيه لبناني','name_en'=>'Lebanese Pound','symbol'=>'LBP','decimals'=>2),
		'LKR'=>array('numeric'=>'144','name_ar'=>'روبية سريلانكية','name_en'=>'Sri Lankan Rupee','symbol'=>'LKR','decimals'=>2),
		'LRD'=>array('numeric'=>'430','name_ar'=>'دولار ليبيري','name_en'=>'Liberian Dollar','symbol'=>'LRD','decimals'=>2),
		'LSL'=>array('numeric'=>'426','name_ar'=>'لوتي ليسوتو','name_en'=>'Lesotho Loti','symbol'=>'LSL','decimals'=>2),
		'LYD'=>array('numeric'=>'434','name_ar'=>'دينار ليبي','name_en'=>'Libyan Dinar','symbol'=>'LYD','decimals'=>3),
		'MAD'=>array('numeric'=>'504','name_ar'=>'درهم مغربي','name_en'=>'Moroccan Dirham','symbol'=>'د.م','decimals'=>2),
		'MDL'=>array('numeric'=>'498','name_ar'=>'ليو مولدوفي','name_en'=>'Moldovan Leu','symbol'=>'MDL','decimals'=>2),
		'MGA'=>array('numeric'=>'969','name_ar'=>'أرياري مدغشقر','name_en'=>'Malagasy Ariary','symbol'=>'MGA','decimals'=>2),
		'MKD'=>array('numeric'=>'807','name_ar'=>'دينار مقدوني','name_en'=>'Macedonian Denar','symbol'=>'MKD','decimals'=>2),
		'MMK'=>array('numeric'=>'104','name_ar'=>'كيات ميانمار','name_en'=>'Myanmar Kyat','symbol'=>'MMK','decimals'=>2),
		'MNT'=>array('numeric'=>'496','name_ar'=>'توغروغ منغولي','name_en'=>'Mongolian Tugrik','symbol'=>'MNT','decimals'=>2),
		'MOP'=>array('numeric'=>'446','name_ar'=>'باتاكا ماكاوي','name_en'=>'Macanese Pataca','symbol'=>'MOP','decimals'=>2),
		'MRU'=>array('numeric'=>'929','name_ar'=>'أوقية موريتانية','name_en'=>'Mauritanian Ouguiya','symbol'=>'MRU','decimals'=>2),
		'MUR'=>array('numeric'=>'480','name_ar'=>'روبية موريشيوسية','name_en'=>'Mauritian Rupee','symbol'=>'MUR','decimals'=>2),
		'MVR'=>array('numeric'=>'462','name_ar'=>'روفيه جزر المالديف','name_en'=>'Maldivian Rufiyaa','symbol'=>'MVR','decimals'=>2),
		'MWK'=>array('numeric'=>'454','name_ar'=>'كواشا مالاوي','name_en'=>'Malawian Kwacha','symbol'=>'MWK','decimals'=>2),
		'MXN'=>array('numeric'=>'484','name_ar'=>'بيزو مكسيكي','name_en'=>'Mexican Peso','symbol'=>'MX$','decimals'=>2),
		'MYR'=>array('numeric'=>'458','name_ar'=>'رينغيت ماليزي','name_en'=>'Malaysian Ringgit','symbol'=>'RM','decimals'=>2),
		'MZN'=>array('numeric'=>'943','name_ar'=>'متكال موزمبيقي','name_en'=>'Mozambican Metical','symbol'=>'MZN','decimals'=>2),
		'NAD'=>array('numeric'=>'516','name_ar'=>'دولار ناميبي','name_en'=>'Namibian Dollar','symbol'=>'NAD','decimals'=>2),
		'NGN'=>array('numeric'=>'566','name_ar'=>'نايرا نيجيري','name_en'=>'Nigerian Naira','symbol'=>'NGN','decimals'=>2),
		'NIO'=>array('numeric'=>'558','name_ar'=>'قرطبة نيكاراغوا','name_en'=>'Nicaraguan Córdoba','symbol'=>'NIO','decimals'=>2),
		'NOK'=>array('numeric'=>'578','name_ar'=>'كرونة نرويجية','name_en'=>'Norwegian Krone','symbol'=>'kr','decimals'=>2),
		'NPR'=>array('numeric'=>'524','name_ar'=>'روبية نيبالي','name_en'=>'Nepalese Rupee','symbol'=>'NPR','decimals'=>2),
		'NZD'=>array('numeric'=>'554','name_ar'=>'دولار نيوزيلندي','name_en'=>'New Zealand Dollar','symbol'=>'NZ$','decimals'=>2),
		'OMR'=>array('numeric'=>'512','name_ar'=>'ريال عماني','name_en'=>'Omani Rial','symbol'=>'ر.ع','decimals'=>3),
		'PAB'=>array('numeric'=>'590','name_ar'=>'بالبوا بنمي','name_en'=>'Panamanian Balboa','symbol'=>'PAB','decimals'=>2),
		'PEN'=>array('numeric'=>'604','name_ar'=>'سول بيروفي','name_en'=>'Peruvian Sol','symbol'=>'PEN','decimals'=>2),
		'PGK'=>array('numeric'=>'598','name_ar'=>'كينا بابوا غينيا الجديدة','name_en'=>'Papua New Guinean Kina','symbol'=>'PGK','decimals'=>2),
		'PHP'=>array('numeric'=>'608','name_ar'=>'بيزو فلبيني','name_en'=>'Philippine Peso','symbol'=>'₱','decimals'=>2),
		'PKR'=>array('numeric'=>'586','name_ar'=>'روبية باكستانية','name_en'=>'Pakistani Rupee','symbol'=>'₨','decimals'=>2),
		'PLN'=>array('numeric'=>'985','name_ar'=>'زلوتي بولندي','name_en'=>'Polish Zloty','symbol'=>'zł','decimals'=>2),
		'PYG'=>array('numeric'=>'600','name_ar'=>'غواراني باراغواي','name_en'=>'Paraguayan Guarani','symbol'=>'PYG','decimals'=>0),
		'QAR'=>array('numeric'=>'634','name_ar'=>'ريال قطري','name_en'=>'Qatari Riyal','symbol'=>'ر.ق','decimals'=>2),
		'RON'=>array('numeric'=>'946','name_ar'=>'ليو روماني','name_en'=>'Romanian Leu','symbol'=>'RON','decimals'=>2),
		'RSD'=>array('numeric'=>'941','name_ar'=>'دينار صربي','name_en'=>'Serbian Dinar','symbol'=>'RSD','decimals'=>2),
		'RUB'=>array('numeric'=>'643','name_ar'=>'روبل روسي','name_en'=>'Russian Ruble','symbol'=>'₽','decimals'=>2),
		'RWF'=>array('numeric'=>'646','name_ar'=>'فرنك رواندي','name_en'=>'Rwandan Franc','symbol'=>'RWF','decimals'=>0),
		'SAR'=>array('numeric'=>'682','name_ar'=>'ريال سعودي','name_en'=>'Saudi Riyal','symbol'=>'ر.س','decimals'=>2),
		'SBD'=>array('numeric'=>'090','name_ar'=>'دولار جزر سليمان','name_en'=>'Solomon Islands Dollar','symbol'=>'SBD','decimals'=>2),
		'SCR'=>array('numeric'=>'690','name_ar'=>'روبية سيشيلية','name_en'=>'Seychellois Rupee','symbol'=>'SCR','decimals'=>2),
		'SDG'=>array('numeric'=>'938','name_ar'=>'جنيه سوداني','name_en'=>'Sudanese Pound','symbol'=>'SDG','decimals'=>2),
		'SEK'=>array('numeric'=>'752','name_ar'=>'كرونة سويدية','name_en'=>'Swedish Krona','symbol'=>'kr','decimals'=>2),
		'SGD'=>array('numeric'=>'702','name_ar'=>'دولار سنغافوري','name_en'=>'Singapore Dollar','symbol'=>'S$','decimals'=>2),
		'SHP'=>array('numeric'=>'654','name_ar'=>'جنيه سانت هيلين','name_en'=>'St. Helena Pound','symbol'=>'SHP','decimals'=>2),
		'SLE'=>array('numeric'=>'925','name_ar'=>'Leone','name_en'=>'Leone','symbol'=>'SLE','decimals'=>2),
		'SOS'=>array('numeric'=>'706','name_ar'=>'شلن صومالي','name_en'=>'Somali Shilling','symbol'=>'SOS','decimals'=>2),
		'SRD'=>array('numeric'=>'968','name_ar'=>'دولار سورينامي','name_en'=>'Surinamese Dollar','symbol'=>'SRD','decimals'=>2),
		'SSP'=>array('numeric'=>'728','name_ar'=>'جنيه جنوب السودان','name_en'=>'South Sudanese Pound','symbol'=>'SSP','decimals'=>2),
		'STN'=>array('numeric'=>'930','name_ar'=>'دوبرا ساو تومي وبرينسيبي','name_en'=>'São Tomé & Príncipe Dobra','symbol'=>'STN','decimals'=>2),
		'SVC'=>array('numeric'=>'222','name_ar'=>'كولون سلفادوري','name_en'=>'Salvadoran Colón','symbol'=>'SVC','decimals'=>2),
		'SYP'=>array('numeric'=>'760','name_ar'=>'ليرة سورية','name_en'=>'Syrian Pound','symbol'=>'SYP','decimals'=>2),
		'SZL'=>array('numeric'=>'748','name_ar'=>'ليلانجيني سوازيلندي','name_en'=>'Swazi Lilangeni','symbol'=>'SZL','decimals'=>2),
		'THB'=>array('numeric'=>'764','name_ar'=>'بات تايلندي','name_en'=>'Thai Baht','symbol'=>'฿','decimals'=>2),
		'TJS'=>array('numeric'=>'972','name_ar'=>'سوموني طاجيكستاني','name_en'=>'Tajikistani Somoni','symbol'=>'TJS','decimals'=>2),
		'TMT'=>array('numeric'=>'934','name_ar'=>'مانات تركمانستان','name_en'=>'Turkmenistani Manat','symbol'=>'TMT','decimals'=>2),
		'TND'=>array('numeric'=>'788','name_ar'=>'دينار تونسي','name_en'=>'Tunisian Dinar','symbol'=>'د.ت','decimals'=>3),
		'TOP'=>array('numeric'=>'776','name_ar'=>'بانغا تونغا','name_en'=>'Tongan Paʻanga','symbol'=>'TOP','decimals'=>2),
		'TRY'=>array('numeric'=>'949','name_ar'=>'ليرة تركية','name_en'=>'Turkish Lira','symbol'=>'₺','decimals'=>2),
		'TTD'=>array('numeric'=>'780','name_ar'=>'دولار ترينداد وتوباغو','name_en'=>'Trinidad & Tobago Dollar','symbol'=>'TTD','decimals'=>2),
		'TWD'=>array('numeric'=>'901','name_ar'=>'دولار تايواني','name_en'=>'New Taiwan Dollar','symbol'=>'TWD','decimals'=>2),
		'TZS'=>array('numeric'=>'834','name_ar'=>'شلن تنزاني','name_en'=>'Tanzanian Shilling','symbol'=>'TZS','decimals'=>2),
		'UAH'=>array('numeric'=>'980','name_ar'=>'هريفنيا أوكراني','name_en'=>'Ukrainian Hryvnia','symbol'=>'UAH','decimals'=>2),
		'UGX'=>array('numeric'=>'800','name_ar'=>'شلن أوغندي','name_en'=>'Ugandan Shilling','symbol'=>'UGX','decimals'=>0),
		'USD'=>array('numeric'=>'840','name_ar'=>'دولار أمريكي','name_en'=>'United States Dollar','symbol'=>'$','decimals'=>2),
		'UYU'=>array('numeric'=>'858','name_ar'=>'بيزو اوروغواي','name_en'=>'Uruguayan Peso','symbol'=>'UYU','decimals'=>2),
		'UZS'=>array('numeric'=>'860','name_ar'=>'سوم أوزبكستاني','name_en'=>'Uzbekistani Som','symbol'=>'UZS','decimals'=>2),
		'VED'=>array('numeric'=>'926','name_ar'=>'بوليفار فنزويلي','name_en'=>'Venezuelan Sovereign Bolívar','symbol'=>'VED','decimals'=>2),
		'VES'=>array('numeric'=>'928','name_ar'=>'بوليفار فنزويلي','name_en'=>'Venezuelan Bolívar','symbol'=>'VES','decimals'=>2),
		'VND'=>array('numeric'=>'704','name_ar'=>'دونج فيتنامي','name_en'=>'Vietnamese Dong','symbol'=>'VND','decimals'=>0),
		'VUV'=>array('numeric'=>'548','name_ar'=>'فاتو فانواتو','name_en'=>'Vanuatu Vatu','symbol'=>'VUV','decimals'=>0),
		'WST'=>array('numeric'=>'882','name_ar'=>'تالا ساموا','name_en'=>'Samoan Tala','symbol'=>'WST','decimals'=>2),
		'XAF'=>array('numeric'=>'950','name_ar'=>'فرنك وسط أفريقي','name_en'=>'Central African CFA Franc','symbol'=>'XAF','decimals'=>0),
		'XCD'=>array('numeric'=>'951','name_ar'=>'دولار شرق الكاريبي','name_en'=>'East Caribbean Dollar','symbol'=>'XCD','decimals'=>2),
		'XCG'=>array('numeric'=>'532','name_ar'=>'جلدر كاريبي','name_en'=>'Caribbean Guilder','symbol'=>'XCG','decimals'=>2),
		'XOF'=>array('numeric'=>'952','name_ar'=>'فرنك غرب أفريقي','name_en'=>'West African CFA Franc','symbol'=>'XOF','decimals'=>0),
		'XPF'=>array('numeric'=>'953','name_ar'=>'فرنك سي إف بي','name_en'=>'CFP Franc','symbol'=>'XPF','decimals'=>0),
		'YER'=>array('numeric'=>'886','name_ar'=>'ريال يمني','name_en'=>'Yemeni Rial','symbol'=>'YER','decimals'=>2),
		'ZAR'=>array('numeric'=>'710','name_ar'=>'راند جنوب أفريقي','name_en'=>'South African Rand','symbol'=>'R','decimals'=>2),
		'ZMW'=>array('numeric'=>'967','name_ar'=>'كواشا زامبي','name_en'=>'Zambian Kwacha','symbol'=>'ZMW','decimals'=>2),
		'ZWG'=>array('numeric'=>'924','name_ar'=>'ذهب زيمبابوي','name_en'=>'Zimbabwe Gold','symbol'=>'ZWG','decimals'=>2),
	);

	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		add_filter( 'gettext', array( self::class, 'translate_unit' ), 20, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'translate_unit_context' ), 20, 4 );
		// Run after the domain catalog so translated Arabic labels are normalized too.
		add_filter( 'gettext_auto-dealership-core', array( self::class, 'translate_unit' ), 99, 3 );
		add_filter( 'gettext_car-dealer', array( self::class, 'translate_unit' ), 99, 3 );
		add_filter( 'gettext_with_context_auto-dealership-core', array( self::class, 'translate_unit_context' ), 99, 4 );
		add_filter( 'gettext_with_context_car-dealer', array( self::class, 'translate_unit_context' ), 99, 4 );
	}

	public static function translate_unit( string $translated, string $text, string $domain ): string {
		if ( ! in_array( $domain, array( 'auto-dealership-core', 'car-dealer' ), true ) ) { return $translated; }
		$localized = self::replace_unit( $translated );
		return self::format_unit_placeholder( $localized, $text );
	}

	public static function translate_unit_context( string $translated, string $text, string $context, string $domain ): string {
		return self::translate_unit( $translated, $text, $domain );
	}

	private static function replace_unit( string $value, ?string $unit = null ): string {
		$unit = $unit ?? self::ui_unit();
		$value = str_replace( array( 'SAR', 'ريال سعودي', 'الريال السعودي' ), array( $unit, $unit, $unit ), $value );
		$value = preg_replace( '/(?<=بال)ريال/u', $unit, $value );
		$value = preg_replace( '/(?<=\()ريال(?=\))/u', $unit, $value );
		return preg_replace( '/(?<=\s)ريال(?=\s|$)/u', $unit, $value );
	}

	/** Normalize a manually selected bilingual label that does not pass through gettext. */
	public static function localize_unit_text( string $value ): string {
		$value = self::replace_unit( $value );
		return self::format_unit_placeholder( $value, $value, true );
	}

	private static function format_unit_placeholder( string $value, string $source, bool $manual = false ): string {
		if ( 1 !== preg_match_all( '/%(?:\d+\$)?s/', $value, $matches ) ) { return $value; }
		if ( ! self::is_currency_template( $source ) ) { return $value; }
		return (string) preg_replace_callback( '/%(?:\d+\$)?s/', static function (): string { return self::ui_unit(); }, $value, 1 );
	}

	private static function is_currency_template( string $value ): bool {
		return (bool) preg_match( '/(?:SAR|ريال|amount|Amount|price|Price|discount|Discount|payment|Payment|fee|Fee|monetary|Monetary|financial|Financial|plates|Plates|total|Total|maximum|Maximum|received|Received|deposit|Deposit|limit|Limit|المبلغ|المبالغ|السعر|الخصم|الدفعة|القسط|الرسوم|رسوم|الإجمالي|الأقصى|المستلم|العربون|الهدف الشهري|العمولة)/u', $value );
	}

	/** Unit used in UI copy; never exposes a hard-coded currency when setup is incomplete. */
	public static function ui_unit( string $language = '' ): string {
		if ( self::is_configured() ) { return self::display_unit( null, $language ); }
		$language = $language ?: Localization::language();
		return 'en' === $language ? 'currency' : 'العملة';
	}

	public static function currencies(): array { return self::CURRENCIES; }

	public static function choices( string $language = '' ): array {
		$language = $language ?: Localization::language();
		$choices = array();
		foreach ( self::CURRENCIES as $code => $currency ) {
			$name = 'en' === $language ? $currency['name_en'] : $currency['name_ar'];
			$choices[ $code ] = $code . ' — ' . $name . ' (' . $currency['symbol'] . ')';
		}
		return $choices;
	}

	public static function get( string $code ): ?array {
		$code = strtoupper( sanitize_key( $code ) );
		return self::CURRENCIES[ $code ] ?? null;
	}

	public static function is_valid( string $code ): bool { return null !== self::get( $code ); }

	public static function base_code(): string {
		$code = strtoupper( sanitize_key( (string) get_option( self::OPTION, '' ) ) );
		return self::is_valid( $code ) ? $code : '';
	}

	public static function is_configured(): bool { return '' !== self::base_code(); }

	public static function option_name(): string { return self::OPTION; }

	/** Set once during onboarding; changing a configured base currency is forbidden. */
	public static function set_base( string $code ) {
		$code = strtoupper( sanitize_key( $code ) );
		if ( ! self::is_valid( $code ) ) {
			return new \WP_Error( 'adc_currency_invalid', __( 'اختر عملة أساسية صحيحة قبل متابعة الإعداد.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		$current = self::base_code();
		if ( '' !== $current && $current !== $code ) {
			return new \WP_Error( 'adc_currency_locked', __( 'العملة الأساسية مقفلة بعد اعتمادها. أنشئ إجراء تغيير مالي موثق قبل استبدالها.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		if ( $current === $code ) { return array( 'updated'=>false, 'currency_code'=>$code ); }
		update_option( self::OPTION, $code, false );
		wp_cache_delete( self::OPTION, 'options' );
		return get_option( self::OPTION ) === $code ? array( 'updated'=>true, 'currency_code'=>$code ) : new \WP_Error( 'adc_currency_storage', __( 'تعذر حفظ العملة الأساسية.', 'auto-dealership-core' ), array( 'status'=>500 ) );
	}

	public static function label( ?string $code = null, string $language = '' ): string {
		$actual_code = strtoupper( sanitize_key( $code ?: self::base_code() ) );
		$currency = self::get( $actual_code );
		if ( ! $currency ) { return ''; }
		return ( 'en' === ( $language ?: Localization::language() ) ? $currency['name_en'] : $currency['name_ar'] ) . ' (' . $actual_code . ')';
	}

	/** Return the short unit intended for visible amounts, while keeping ISO codes for storage and APIs. */
	public static function display_unit( ?string $code = null, string $language = '' ): string {
		$actual_code = strtoupper( sanitize_key( $code ?: self::base_code() ) );
		$language = $language ?: Localization::language();
		if ( 'en' === $language ) { return $actual_code; }
		$short_names = array(
			'AED'=>'درهم إماراتي', 'AUD'=>'دولار أسترالي', 'BHD'=>'دينار بحريني', 'BRL'=>'ريال برازيلي',
			'CAD'=>'دولار كندي', 'CHF'=>'فرنك سويسري', 'CNY'=>'يوان صيني', 'DKK'=>'كرونة دنماركية',
			'EGP'=>'جنيه مصري', 'EUR'=>'يورو', 'GBP'=>'جنيه إسترليني', 'HKD'=>'دولار هونغ كونغ',
			'IDR'=>'روبية إندونيسية', 'INR'=>'روبية هندية', 'JPY'=>'ين ياباني', 'KRW'=>'وون كوري',
			'KWD'=>'دينار كويتي', 'MAD'=>'درهم مغربي', 'MXN'=>'بيزو مكسيكي', 'MYR'=>'رينغيت ماليزي',
			'NOK'=>'كرونة نرويجية', 'NZD'=>'دولار نيوزيلندي', 'OMR'=>'ريال عماني', 'PHP'=>'بيزو فلبيني',
			'PKR'=>'روبية باكستانية', 'PLN'=>'زلوتي بولندي', 'QAR'=>'ريال قطري', 'RUB'=>'روبل روسي',
			'SAR'=>'ريال', 'SEK'=>'كرونة سويدية', 'SGD'=>'دولار سنغافوري', 'THB'=>'بات تايلندي',
			'TND'=>'دينار تونسي', 'TRY'=>'ليرة تركية', 'USD'=>'دولار أمريكي', 'ZAR'=>'راند جنوب أفريقي',
		);
		$currency = self::get( $actual_code );
		return $short_names[ $actual_code ] ?? ( $currency['name_ar'] ?? $actual_code );
	}

	public static function decimals( ?string $code = null ): int {
		$currency = self::get( $code ?: self::base_code() );
		return $currency ? (int) $currency['decimals'] : 2;
	}

	/** HTML number-input step matching the configured currency precision. */
	public static function input_step( ?string $code = null ): string {
		$decimals = self::decimals( $code );
		return 0 === $decimals ? '1' : '0.' . str_repeat( '0', $decimals - 1 ) . '1';
	}

	public static function minor_factor( ?string $code = null ): int {
		return 10 ** self::decimals( $code );
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || self::is_configured() ) { return; }
		$url = add_query_arg( array( 'page'=>'adc-platform-setup', 'section'=>'identity' ), admin_url( 'admin.php' ) );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'العملة الأساسية غير مفعلة.', 'auto-dealership-core' ) . '</strong> ' . esc_html__( 'يجب اختيار العملة من إعداد المنصة قبل إنشاء الأسعار أو العروض أو تسجيل أي عملية مالية.', 'auto-dealership-core' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'تفعيل العملة الآن', 'auto-dealership-core' ) . '</a></p></div>';
	}
}
