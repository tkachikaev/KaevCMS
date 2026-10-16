<?php

namespace KaevCMS\Modules\Donations\Services;

/** Versioned shortlist of recognized active tender ISO 4217 codes, with currency-specific decimal precision.
 * Currency codes and exponents are controlled by the module, not submitted by users.
 * Providers may support only a subset of this list.
 */
final class DonationCurrencyRegistry
{
    /** @var array<string, array{int, string, string, string}> */
    private const CURRENCIES = [
        'AED' => [2, 'United Arab Emirates Dirham', 'дирхам ОАЭ', 'AED'],
        'AFN' => [0, 'Afghan Afghani', 'афгани', 'AFN'],
        'ALL' => [0, 'Albanian Lek', 'албанский лек', 'ALL'],
        'AMD' => [2, 'Armenian Dram', 'армянский драм', 'AMD'],
        'AOA' => [2, 'Angolan Kwanza', 'ангольская кванза', 'AOA'],
        'ARS' => [2, 'Argentine Peso', 'аргентинский песо', 'ARS'],
        'AUD' => [2, 'Australian Dollar', 'австралийский доллар', 'A$'],
        'AWG' => [2, 'Aruban Florin', 'арубанский флорин', 'AWG'],
        'AZN' => [2, 'Azerbaijani Manat', 'азербайджанский манат', 'AZN'],
        'BAM' => [2, 'Bosnia-Herzegovina Convertible Mark', 'конвертируемая марка Боснии и Герцеговины', 'BAM'],
        'BBD' => [2, 'Barbadian Dollar', 'барбадосский доллар', 'BBD'],
        'BDT' => [2, 'Bangladeshi Taka', 'бангладешская така', 'BDT'],
        'BGN' => [2, 'Bulgarian Lev', 'болгарский лев', 'BGN'],
        'BHD' => [3, 'Bahraini Dinar', 'бахрейнский динар', 'BHD'],
        'BIF' => [0, 'Burundian Franc', 'бурундийский франк', 'BIF'],
        'BMD' => [2, 'Bermudan Dollar', 'бермудский доллар', 'BMD'],
        'BND' => [2, 'Brunei Dollar', 'брунейский доллар', 'BND'],
        'BOB' => [2, 'Bolivian Boliviano', 'боливийский боливиано', 'BOB'],
        'BRL' => [2, 'Brazilian Real', 'бразильский реал', 'R$'],
        'BSD' => [2, 'Bahamian Dollar', 'багамский доллар', 'BSD'],
        'BTN' => [2, 'Bhutanese Ngultrum', 'бутанский нгултрум', 'BTN'],
        'BWP' => [2, 'Botswanan Pula', 'ботсванская пула', 'BWP'],
        'BYN' => [2, 'Belarusian Ruble', 'белорусский рубль', 'BYN'],
        'BZD' => [2, 'Belize Dollar', 'белизский доллар', 'BZD'],
        'CAD' => [2, 'Canadian Dollar', 'канадский доллар', 'CA$'],
        'CDF' => [2, 'Congolese Franc', 'конголезский франк', 'CDF'],
        'CHF' => [2, 'Swiss Franc', 'швейцарский франк', 'CHF'],
        'CLP' => [0, 'Chilean Peso', 'чилийский песо', 'CLP'],
        'CNY' => [2, 'Chinese Yuan', 'китайский юань', 'CN¥'],
        'COP' => [2, 'Colombian Peso', 'колумбийский песо', 'COP'],
        'CRC' => [2, 'Costa Rican Colón', 'костариканский колон', 'CRC'],
        'CUP' => [2, 'Cuban Peso', 'кубинский песо', 'CUP'],
        'CVE' => [2, 'Cape Verdean Escudo', 'эскудо Кабо-Верде', 'CVE'],
        'CZK' => [2, 'Czech Koruna', 'чешская крона', 'CZK'],
        'DJF' => [0, 'Djiboutian Franc', 'франк Джибути', 'DJF'],
        'DKK' => [2, 'Danish Krone', 'датская крона', 'DKK'],
        'DOP' => [2, 'Dominican Peso', 'доминиканский песо', 'DOP'],
        'DZD' => [2, 'Algerian Dinar', 'алжирский динар', 'DZD'],
        'EGP' => [2, 'Egyptian Pound', 'египетский фунт', 'EGP'],
        'ERN' => [2, 'Eritrean Nakfa', 'эритрейская накфа', 'ERN'],
        'ETB' => [2, 'Ethiopian Birr', 'эфиопский быр', 'ETB'],
        'EUR' => [2, 'Euro', 'евро', '€'],
        'FJD' => [2, 'Fijian Dollar', 'доллар Фиджи', 'FJD'],
        'FKP' => [2, 'Falkland Islands Pound', 'фунт Фолклендских островов', 'FKP'],
        'GBP' => [2, 'British Pound', 'британский фунт стерлингов', '£'],
        'GEL' => [2, 'Georgian Lari', 'грузинский лари', 'GEL'],
        'GHS' => [2, 'Ghanaian Cedi', 'ганский седи', 'GHS'],
        'GIP' => [2, 'Gibraltar Pound', 'гибралтарский фунт', 'GIP'],
        'GMD' => [2, 'Gambian Dalasi', 'гамбийский даласи', 'GMD'],
        'GNF' => [0, 'Guinean Franc', 'гвинейский франк', 'GNF'],
        'GTQ' => [2, 'Guatemalan Quetzal', 'гватемальский кетсаль', 'GTQ'],
        'GYD' => [2, 'Guyanaese Dollar', 'гайанский доллар', 'GYD'],
        'HKD' => [2, 'Hong Kong Dollar', 'гонконгский доллар', 'HK$'],
        'HNL' => [2, 'Honduran Lempira', 'гондурасская лемпира', 'HNL'],
        'HTG' => [2, 'Haitian Gourde', 'гаитянский гурд', 'HTG'],
        'HUF' => [2, 'Hungarian Forint', 'венгерский форинт', 'HUF'],
        'IDR' => [2, 'Indonesian Rupiah', 'индонезийская рупия', 'IDR'],
        'ILS' => [2, 'Israeli New Shekel', 'новый израильский шекель', '₪'],
        'INR' => [2, 'Indian Rupee', 'индийская рупия', '₹'],
        'IQD' => [0, 'Iraqi Dinar', 'иракский динар', 'IQD'],
        'IRR' => [0, 'Iranian Rial', 'иранский риал', 'IRR'],
        'ISK' => [0, 'Icelandic Króna', 'исландская крона', 'ISK'],
        'JMD' => [2, 'Jamaican Dollar', 'ямайский доллар', 'JMD'],
        'JOD' => [3, 'Jordanian Dinar', 'иорданский динар', 'JOD'],
        'JPY' => [0, 'Japanese Yen', 'японская иена', '¥'],
        'KES' => [2, 'Kenyan Shilling', 'кенийский шиллинг', 'KES'],
        'KGS' => [2, 'Kyrgystani Som', 'киргизский сом', 'KGS'],
        'KHR' => [2, 'Cambodian Riel', 'камбоджийский риель', 'KHR'],
        'KMF' => [0, 'Comorian Franc', 'коморский франк', 'KMF'],
        'KPW' => [0, 'North Korean Won', 'северокорейская вона', 'KPW'],
        'KRW' => [0, 'South Korean Won', 'южнокорейская вона', '₩'],
        'KWD' => [3, 'Kuwaiti Dinar', 'кувейтский динар', 'KWD'],
        'KYD' => [2, 'Cayman Islands Dollar', 'доллар Островов Кайман', 'KYD'],
        'KZT' => [2, 'Kazakhstani Tenge', 'казахский тенге', 'KZT'],
        'LAK' => [0, 'Laotian Kip', 'лаосский кип', 'LAK'],
        'LBP' => [0, 'Lebanese Pound', 'ливанский фунт', 'LBP'],
        'LKR' => [2, 'Sri Lankan Rupee', 'шри-ланкийская рупия', 'LKR'],
        'LRD' => [2, 'Liberian Dollar', 'либерийский доллар', 'LRD'],
        'LSL' => [2, 'Lesotho Loti', 'лоти', 'LSL'],
        'LYD' => [3, 'Libyan Dinar', 'ливийский динар', 'LYD'],
        'MAD' => [2, 'Moroccan Dirham', 'марокканский дирхам', 'MAD'],
        'MDL' => [2, 'Moldovan Leu', 'молдавский лей', 'MDL'],
        'MGA' => [0, 'Malagasy Ariary', 'малагасийский ариари', 'MGA'],
        'MKD' => [2, 'Macedonian Denar', 'македонский денар', 'MKD'],
        'MMK' => [0, 'Myanmar Kyat', 'мьянманский кьят', 'MMK'],
        'MNT' => [2, 'Mongolian Tugrik', 'монгольский тугрик', 'MNT'],
        'MOP' => [2, 'Macanese Pataca', 'патака Макао', 'MOP'],
        'MRU' => [2, 'Mauritanian Ouguiya', 'мавританская угия', 'MRU'],
        'MUR' => [2, 'Mauritian Rupee', 'маврикийская рупия', 'MUR'],
        'MVR' => [2, 'Maldivian Rufiyaa', 'мальдивская руфия', 'MVR'],
        'MWK' => [2, 'Malawian Kwacha', 'малавийская квача', 'MWK'],
        'MXN' => [2, 'Mexican Peso', 'мексиканский песо', 'MX$'],
        'MYR' => [2, 'Malaysian Ringgit', 'малайзийский ринггит', 'MYR'],
        'MZN' => [2, 'Mozambican Metical', 'мозамбикский метикал', 'MZN'],
        'NAD' => [2, 'Namibian Dollar', 'доллар Намибии', 'NAD'],
        'NGN' => [2, 'Nigerian Naira', 'нигерийская найра', 'NGN'],
        'NIO' => [2, 'Nicaraguan Córdoba', 'никарагуанская кордоба', 'NIO'],
        'NOK' => [2, 'Norwegian Krone', 'норвежская крона', 'NOK'],
        'NPR' => [2, 'Nepalese Rupee', 'непальская рупия', 'NPR'],
        'NZD' => [2, 'New Zealand Dollar', 'новозеландский доллар', 'NZ$'],
        'OMR' => [3, 'Omani Rial', 'оманский риал', 'OMR'],
        'PAB' => [2, 'Panamanian Balboa', 'панамский бальбоа', 'PAB'],
        'PEN' => [2, 'Peruvian Sol', 'перуанский соль', 'PEN'],
        'PGK' => [2, 'Papua New Guinean Kina', 'кина Папуа – Новой Гвинеи', 'PGK'],
        'PHP' => [2, 'Philippine Peso', 'филиппинский песо', '₱'],
        'PKR' => [2, 'Pakistani Rupee', 'пакистанская рупия', 'PKR'],
        'PLN' => [2, 'Polish Zloty', 'польский злотый', 'PLN'],
        'PYG' => [0, 'Paraguayan Guarani', 'парагвайский гуарани', 'PYG'],
        'QAR' => [2, 'Qatari Riyal', 'катарский риал', 'QAR'],
        'RON' => [2, 'Romanian Leu', 'румынский лей', 'RON'],
        'RSD' => [0, 'Serbian Dinar', 'сербский динар', 'RSD'],
        'RUB' => [2, 'Russian Ruble', 'российский рубль', 'RUB'],
        'RWF' => [0, 'Rwandan Franc', 'франк Руанды', 'RWF'],
        'SAR' => [2, 'Saudi Riyal', 'саудовский риял', 'SAR'],
        'SBD' => [2, 'Solomon Islands Dollar', 'доллар Соломоновых Островов', 'SBD'],
        'SCR' => [2, 'Seychellois Rupee', 'сейшельская рупия', 'SCR'],
        'SDG' => [2, 'Sudanese Pound', 'суданский фунт', 'SDG'],
        'SEK' => [2, 'Swedish Krona', 'шведская крона', 'SEK'],
        'SGD' => [2, 'Singapore Dollar', 'сингапурский доллар', 'SGD'],
        'SHP' => [2, 'St. Helena Pound', 'фунт острова Святой Елены', 'SHP'],
        'SLE' => [2, 'Sierra Leonean Leone', 'леоне', 'SLE'],
        'SOS' => [0, 'Somali Shilling', 'сомалийский шиллинг', 'SOS'],
        'SRD' => [2, 'Surinamese Dollar', 'суринамский доллар', 'SRD'],
        'SSP' => [2, 'South Sudanese Pound', 'южносуданский фунт', 'SSP'],
        'STN' => [2, 'São Tomé & Príncipe Dobra', 'добра Сан-Томе и Принсипи', 'STN'],
        'SYP' => [0, 'Syrian Pound', 'сирийский фунт', 'SYP'],
        'SZL' => [2, 'Swazi Lilangeni', 'свазилендский лилангени', 'SZL'],
        'THB' => [2, 'Thai Baht', 'таиландский бат', 'THB'],
        'TJS' => [2, 'Tajikistani Somoni', 'таджикский сомони', 'TJS'],
        'TMT' => [2, 'Turkmenistani Manat', 'новый туркменский манат', 'TMT'],
        'TND' => [3, 'Tunisian Dinar', 'тунисский динар', 'TND'],
        'TOP' => [2, 'Tongan Paʻanga', 'тонганская паанга', 'TOP'],
        'TRY' => [2, 'Turkish Lira', 'турецкая лира', 'TRY'],
        'TTD' => [2, 'Trinidad & Tobago Dollar', 'доллар Тринидада и Тобаго', 'TTD'],
        'TWD' => [2, 'New Taiwan Dollar', 'новый тайваньский доллар', 'NT$'],
        'TZS' => [2, 'Tanzanian Shilling', 'танзанийский шиллинг', 'TZS'],
        'UAH' => [2, 'Ukrainian Hryvnia', 'украинская гривна', 'UAH'],
        'UGX' => [0, 'Ugandan Shilling', 'угандийский шиллинг', 'UGX'],
        'USD' => [2, 'US Dollar', 'доллар США', '$'],
        'UYU' => [2, 'Uruguayan Peso', 'уругвайский песо', 'UYU'],
        'UZS' => [2, 'Uzbekistani Som', 'узбекский сум', 'UZS'],
        'VES' => [2, 'Venezuelan Bolívar', 'венесуэльский боливар', 'VES'],
        'VND' => [0, 'Vietnamese Dong', 'вьетнамский донг', '₫'],
        'VUV' => [0, 'Vanuatu Vatu', 'вату Вануату', 'VUV'],
        'WST' => [2, 'Samoan Tala', 'самоанская тала', 'WST'],
        'XAF' => [0, 'Central African CFA Franc', 'франк КФА BEAC', 'FCFA'],
        'XCD' => [2, 'East Caribbean Dollar', 'восточно-карибский доллар', 'EC$'],
        'XCG' => [2, 'Caribbean guilder', 'XCG', 'Cg.'],
        'XOF' => [0, 'West African CFA Franc', 'франк КФА ВСЕАО', 'F CFA'],
        'XPF' => [0, 'CFP Franc', 'французский тихоокеанский франк', 'CFPF'],
        'YER' => [0, 'Yemeni Rial', 'йеменский риал', 'YER'],
        'ZAR' => [2, 'South African Rand', 'южноафриканский рэнд', 'ZAR'],
        'ZMW' => [2, 'Zambian Kwacha', 'замбийская квача', 'ZMW'],
        'ZWG' => [2, 'Zimbabwean Gold', 'ZWG', 'ZWG'],
    ];

    /** @return array{code:string,exponent:int,name:string,symbol:string}|null */
    public function find(string $code, string $locale = 'en'): ?array
    {
        $row = self::CURRENCIES[$code] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'code' => $code,
            'exponent' => $row[0],
            'name' => $locale === 'ru' ? $row[2] : $row[1],
            'symbol' => $row[3],
        ];
    }

    /** @return list<array{code:string,exponent:int,name:string,symbol:string}> */
    public function all(string $locale = 'en'): array
    {
        $result = [];
        foreach (array_keys(self::CURRENCIES) as $code) {
            $entry = $this->find($code, $locale);
            if ($entry !== null) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}
