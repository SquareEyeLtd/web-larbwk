<?php
/**
 * Country name → ISO 3166-1 alpha-2 conversion.
 *
 * Stripe requires the customer address country as an alpha-2 code ("GB"),
 * while the forms collect a country name ("United Kingdom"). This is the
 * module-owned home of the mapper; the mu-plugin `law-gf-country-iso.php`
 * (which fills form 2 (Event > submit an event) field 88 (Country ISO) on the
 * legacy path) now delegates here, so retiring that mu-plugin after cutover
 * cannot break ISO derivation for the module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalise a country name for map lookup: lower-case, trimmed,
 * trailing period removed, internal whitespace collapsed.
 *
 * @param string $name Country name as typed.
 * @return string
 */
function law_events_norm_country( $name ) {
	$name = strtolower( trim( (string) $name ) );
	$name = rtrim( $name, '.' );
	$name = preg_replace( '/\s+/', ' ', $name );
	return $name;
}

/**
 * Convert a country name to an ISO 3166-1 alpha-2 code.
 * Returns '' (and logs) when no match is found, rather than
 * sending an invalid value to Stripe.
 *
 * @param string $name Country name.
 * @return string Alpha-2 code, or '' when unmapped.
 */
function law_events_country_to_iso( $name ) {
	$key = law_events_norm_country( $name );
	if ( '' === $key ) {
		return '';
	}

	$map = law_events_country_map();
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}

	// Already a 2-letter code? Accept it (upper-cased).
	if ( preg_match( '/^[a-z]{2}$/', $key ) ) {
		return strtoupper( $key );
	}

	error_log( sprintf( '[SQE LAW] Unmapped country name for Stripe ISO: "%s"', $name ) );
	return '';
}

/**
 * The reverse map: ISO 3166-1 alpha-2 code => the country NAME the forms
 * offer, built from the country list itself so the two can never disagree.
 *
 * Only names on that list are candidates, so a code resolves to the one
 * spelling a select can actually show. The list is checked against
 * law_events_country_map() directly rather than through
 * law_events_country_to_iso(), which logs every unmapped name: ten of the 249
 * choices (Åland Islands, Holy See, Micronesia and friends) have no entry, and
 * building this map must not fill the error log with them.
 *
 * @return array<string,string> e.g. 'GB' => 'United Kingdom'.
 */
function law_events_iso_country_names() {
	static $names = null;
	if ( null !== $names ) {
		return $names;
	}
	$map   = law_events_country_map();
	$names = array();
	foreach ( law_registration_country_choices() as $name ) {
		$key = law_events_norm_country( $name );
		if ( ! isset( $map[ $key ] ) || isset( $names[ $map[ $key ] ] ) ) {
			continue;
		}
		$names[ $map[ $key ] ] = (string) $name;
	}
	return $names;
}

/**
 * The country NAME to show for a stored billing country.
 *
 * Form 2 (Event > submit an event) field 74 (Address) input 74.6 (Country) was
 * filled in by two different front ends over its life and the entries show it:
 * 231 say "GB" where 198 say "United Kingdom", and the same split runs through
 * China, Portugal, Singapore and the rest. The custom form's Country select
 * offers names, so a migrated event whose country is a bare code shows an
 * option reading "GB" (the form appends an unrecognised stored value as its
 * own option, which is what stops it being lost). Mapping the code onto its
 * name here settles that (audit, 16 September 2026).
 *
 * Nothing is ever dropped: a name that is not on the list, or a code that maps
 * to nothing, comes back exactly as it went in. A billing address is the
 * host's own words and a country the list has never heard of is still better
 * than a blank.
 *
 * Stripe is unaffected either way. It is sent _law_country_iso, a separate
 * key that has always held a clean alpha-2 code, and law_events_country_to_iso()
 * accepts a bare code as well as a name, so neither spelling could ever have
 * blanked it.
 *
 * @param string $value Stored or posted country.
 * @return string
 */
function law_events_country_display_name( $value ) {
	$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
	if ( ! preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
		return $value;
	}
	return law_events_iso_country_names()[ strtoupper( $value ) ] ?? $value;
}

/**
 * Name => ISO 3166-1 alpha-2 map. Keys are normalised (see
 * law_events_norm_country()). Covers official names, common names and
 * common colloquial spellings. Add any name the log flags as unmapped.
 */
function law_events_country_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array(
		'afghanistan' => 'AF',
		'albania' => 'AL',
		'algeria' => 'DZ',
		'america' => 'US',
		'american samoa' => 'AS',
		'andorra' => 'AD',
		'angola' => 'AO',
		'anguilla' => 'AI',
		'antarctica' => 'AQ',
		'antigua and barbuda' => 'AG',
		'arab republic of egypt' => 'EG',
		'argentina' => 'AR',
		'argentine republic' => 'AR',
		'armenia' => 'AM',
		'aruba' => 'AW',
		'australia' => 'AU',
		'austria' => 'AT',
		'azerbaijan' => 'AZ',
		'bahamas' => 'BS',
		'bahrain' => 'BH',
		'bangladesh' => 'BD',
		'barbados' => 'BB',
		'belarus' => 'BY',
		'belgium' => 'BE',
		'belize' => 'BZ',
		'benin' => 'BJ',
		'bermuda' => 'BM',
		'bhutan' => 'BT',
		'bolivarian republic of venezuela' => 'VE',
		'bolivia' => 'BO',
		'bolivia, plurinational state of' => 'BO',
		'bonaire, sint eustatius and saba' => 'BQ',
		'bosnia and herzegovina' => 'BA',
		'botswana' => 'BW',
		'bouvet island' => 'BV',
		'brazil' => 'BR',
		'british indian ocean territory' => 'IO',
		'british virgin islands' => 'VG',
		'brunei' => 'BN',
		'brunei darussalam' => 'BN',
		'bulgaria' => 'BG',
		'burkina faso' => 'BF',
		'burma' => 'MM',
		'burundi' => 'BI',
		'cabo verde' => 'CV',
		'cambodia' => 'KH',
		'cameroon' => 'CM',
		'canada' => 'CA',
		'cape verde' => 'CV',
		'cayman islands' => 'KY',
		'central african republic' => 'CF',
		'chad' => 'TD',
		'chile' => 'CL',
		'china' => 'CN',
		'christmas island' => 'CX',
		'cocos (keeling) islands' => 'CC',
		'colombia' => 'CO',
		'commonwealth of dominica' => 'DM',
		'commonwealth of the bahamas' => 'BS',
		'commonwealth of the northern mariana islands' => 'MP',
		'comoros' => 'KM',
		'congo' => 'CG',
		'congo, democratic republic of the' => 'CD',
		'congo, republic of the' => 'CG',
		'congo, the democratic republic of the' => 'CD',
		'cook islands' => 'CK',
		'costa rica' => 'CR',
		'cote d\'ivoire' => 'CI',
		'croatia' => 'HR',
		'cuba' => 'CU',
		'curaçao' => 'CW',
		'cyprus' => 'CY',
		'czech republic' => 'CZ',
		'czechia' => 'CZ',
		'côte d\'ivoire' => 'CI',
		'democratic people\'s republic of korea' => 'KP',
		'democratic republic of sao tome and principe' => 'ST',
		'democratic republic of the congo' => 'CD',
		'democratic republic of timor-leste' => 'TL',
		'democratic socialist republic of sri lanka' => 'LK',
		'denmark' => 'DK',
		'djibouti' => 'DJ',
		'dominica' => 'DM',
		'dominican republic' => 'DO',
		'eastern republic of uruguay' => 'UY',
		'ecuador' => 'EC',
		'egypt' => 'EG',
		'el salvador' => 'SV',
		'england' => 'GB',
		'equatorial guinea' => 'GQ',
		'eritrea' => 'ER',
		'estonia' => 'EE',
		'eswatini' => 'SZ',
		'ethiopia' => 'ET',
		'falkland islands (malvinas)' => 'FK',
		'faroe islands' => 'FO',
		'federal democratic republic of ethiopia' => 'ET',
		'federal democratic republic of nepal' => 'NP',
		'federal republic of germany' => 'DE',
		'federal republic of nigeria' => 'NG',
		'federal republic of somalia' => 'SO',
		'federated states of micronesia' => 'FM',
		'federative republic of brazil' => 'BR',
		'fiji' => 'FJ',
		'finland' => 'FI',
		'france' => 'FR',
		'french guiana' => 'GF',
		'french polynesia' => 'PF',
		'french republic' => 'FR',
		'french southern territories' => 'TF',
		'gabon' => 'GA',
		'gabonese republic' => 'GA',
		'gambia' => 'GM',
		'georgia' => 'GE',
		'germany' => 'DE',
		'ghana' => 'GH',
		'gibraltar' => 'GI',
		'grand duchy of luxembourg' => 'LU',
		'great britain' => 'GB',
		'greece' => 'GR',
		'greenland' => 'GL',
		'grenada' => 'GD',
		'guadeloupe' => 'GP',
		'guam' => 'GU',
		'guatemala' => 'GT',
		'guernsey' => 'GG',
		'guinea' => 'GN',
		'guinea-bissau' => 'GW',
		'guyana' => 'GY',
		'haiti' => 'HT',
		'hashemite kingdom of jordan' => 'JO',
		'heard island and mcdonald islands' => 'HM',
		'hellenic republic' => 'GR',
		'holland' => 'NL',
		'holy see (vatican city state)' => 'VA',
		'honduras' => 'HN',
		'hong kong' => 'HK',
		'hong kong special administrative region of china' => 'HK',
		'hungary' => 'HU',
		'iceland' => 'IS',
		'independent state of papua new guinea' => 'PG',
		'independent state of samoa' => 'WS',
		'india' => 'IN',
		'indonesia' => 'ID',
		'iran' => 'IR',
		'iran, islamic republic of' => 'IR',
		'iraq' => 'IQ',
		'ireland' => 'IE',
		'islamic republic of afghanistan' => 'AF',
		'islamic republic of iran' => 'IR',
		'islamic republic of mauritania' => 'MR',
		'islamic republic of pakistan' => 'PK',
		'isle of man' => 'IM',
		'israel' => 'IL',
		'italian republic' => 'IT',
		'italy' => 'IT',
		'ivory coast' => 'CI',
		'jamaica' => 'JM',
		'japan' => 'JP',
		'jersey' => 'JE',
		'jordan' => 'JO',
		'kazakhstan' => 'KZ',
		'kenya' => 'KE',
		'kingdom of bahrain' => 'BH',
		'kingdom of belgium' => 'BE',
		'kingdom of bhutan' => 'BT',
		'kingdom of cambodia' => 'KH',
		'kingdom of denmark' => 'DK',
		'kingdom of eswatini' => 'SZ',
		'kingdom of lesotho' => 'LS',
		'kingdom of morocco' => 'MA',
		'kingdom of norway' => 'NO',
		'kingdom of saudi arabia' => 'SA',
		'kingdom of spain' => 'ES',
		'kingdom of sweden' => 'SE',
		'kingdom of thailand' => 'TH',
		'kingdom of the netherlands' => 'NL',
		'kingdom of tonga' => 'TO',
		'kiribati' => 'KI',
		'korea, democratic people\'s republic of' => 'KP',
		'korea, north' => 'KP',
		'korea, republic of' => 'KR',
		'korea, south' => 'KR',
		'kuwait' => 'KW',
		'kyrgyz republic' => 'KG',
		'kyrgyzstan' => 'KG',
		'lao people\'s democratic republic' => 'LA',
		'laos' => 'LA',
		'latvia' => 'LV',
		'lebanese republic' => 'LB',
		'lebanon' => 'LB',
		'lesotho' => 'LS',
		'liberia' => 'LR',
		'libya' => 'LY',
		'liechtenstein' => 'LI',
		'lithuania' => 'LT',
		'luxembourg' => 'LU',
		'macao' => 'MO',
		'macao special administrative region of china' => 'MO',
		'macau' => 'MO',
		'madagascar' => 'MG',
		'malawi' => 'MW',
		'malaysia' => 'MY',
		'maldives' => 'MV',
		'mali' => 'ML',
		'malta' => 'MT',
		'marshall islands' => 'MH',
		'martinique' => 'MQ',
		'mauritania' => 'MR',
		'mauritius' => 'MU',
		'mayotte' => 'YT',
		'mexico' => 'MX',
		'micronesia, federated states of' => 'FM',
		'moldova' => 'MD',
		'moldova, republic of' => 'MD',
		'monaco' => 'MC',
		'mongolia' => 'MN',
		'montenegro' => 'ME',
		'montserrat' => 'MS',
		'morocco' => 'MA',
		'mozambique' => 'MZ',
		'myanmar' => 'MM',
		'namibia' => 'NA',
		'nauru' => 'NR',
		'nepal' => 'NP',
		'netherlands' => 'NL',
		'new caledonia' => 'NC',
		'new zealand' => 'NZ',
		'nicaragua' => 'NI',
		'niger' => 'NE',
		'nigeria' => 'NG',
		'niue' => 'NU',
		'norfolk island' => 'NF',
		'north korea' => 'KP',
		'north macedonia' => 'MK',
		'northern ireland' => 'GB',
		'northern mariana islands' => 'MP',
		'norway' => 'NO',
		'oman' => 'OM',
		'pakistan' => 'PK',
		'palau' => 'PW',
		'palestine' => 'PS',
		'palestine, state of' => 'PS',
		'panama' => 'PA',
		'papua new guinea' => 'PG',
		'paraguay' => 'PY',
		'people\'s democratic republic of algeria' => 'DZ',
		'people\'s republic of bangladesh' => 'BD',
		'people\'s republic of china' => 'CN',
		'peru' => 'PE',
		'philippines' => 'PH',
		'pitcairn' => 'PN',
		'plurinational state of bolivia' => 'BO',
		'poland' => 'PL',
		'portugal' => 'PT',
		'portuguese republic' => 'PT',
		'principality of andorra' => 'AD',
		'principality of liechtenstein' => 'LI',
		'principality of monaco' => 'MC',
		'puerto rico' => 'PR',
		'qatar' => 'QA',
		'republic of albania' => 'AL',
		'republic of angola' => 'AO',
		'republic of armenia' => 'AM',
		'republic of austria' => 'AT',
		'republic of azerbaijan' => 'AZ',
		'republic of belarus' => 'BY',
		'republic of benin' => 'BJ',
		'republic of bosnia and herzegovina' => 'BA',
		'republic of botswana' => 'BW',
		'republic of bulgaria' => 'BG',
		'republic of burundi' => 'BI',
		'republic of cabo verde' => 'CV',
		'republic of cameroon' => 'CM',
		'republic of chad' => 'TD',
		'republic of chile' => 'CL',
		'republic of colombia' => 'CO',
		'republic of costa rica' => 'CR',
		'republic of croatia' => 'HR',
		'republic of cuba' => 'CU',
		'republic of cyprus' => 'CY',
		'republic of côte d\'ivoire' => 'CI',
		'republic of djibouti' => 'DJ',
		'republic of ecuador' => 'EC',
		'republic of el salvador' => 'SV',
		'republic of equatorial guinea' => 'GQ',
		'republic of estonia' => 'EE',
		'republic of fiji' => 'FJ',
		'republic of finland' => 'FI',
		'republic of ghana' => 'GH',
		'republic of guatemala' => 'GT',
		'republic of guinea' => 'GN',
		'republic of guinea-bissau' => 'GW',
		'republic of guyana' => 'GY',
		'republic of haiti' => 'HT',
		'republic of honduras' => 'HN',
		'republic of iceland' => 'IS',
		'republic of india' => 'IN',
		'republic of indonesia' => 'ID',
		'republic of iraq' => 'IQ',
		'republic of kazakhstan' => 'KZ',
		'republic of kenya' => 'KE',
		'republic of kiribati' => 'KI',
		'republic of korea' => 'KR',
		'republic of latvia' => 'LV',
		'republic of liberia' => 'LR',
		'republic of lithuania' => 'LT',
		'republic of madagascar' => 'MG',
		'republic of malawi' => 'MW',
		'republic of maldives' => 'MV',
		'republic of mali' => 'ML',
		'republic of malta' => 'MT',
		'republic of mauritius' => 'MU',
		'republic of moldova' => 'MD',
		'republic of mozambique' => 'MZ',
		'republic of myanmar' => 'MM',
		'republic of namibia' => 'NA',
		'republic of nauru' => 'NR',
		'republic of nicaragua' => 'NI',
		'republic of north macedonia' => 'MK',
		'republic of palau' => 'PW',
		'republic of panama' => 'PA',
		'republic of paraguay' => 'PY',
		'republic of peru' => 'PE',
		'republic of poland' => 'PL',
		'republic of san marino' => 'SM',
		'republic of senegal' => 'SN',
		'republic of serbia' => 'RS',
		'republic of seychelles' => 'SC',
		'republic of sierra leone' => 'SL',
		'republic of singapore' => 'SG',
		'republic of slovenia' => 'SI',
		'republic of south africa' => 'ZA',
		'republic of south sudan' => 'SS',
		'republic of suriname' => 'SR',
		'republic of tajikistan' => 'TJ',
		'republic of the congo' => 'CG',
		'republic of the gambia' => 'GM',
		'republic of the marshall islands' => 'MH',
		'republic of the niger' => 'NE',
		'republic of the philippines' => 'PH',
		'republic of the sudan' => 'SD',
		'republic of trinidad and tobago' => 'TT',
		'republic of tunisia' => 'TN',
		'republic of türkiye' => 'TR',
		'republic of uganda' => 'UG',
		'republic of uzbekistan' => 'UZ',
		'republic of vanuatu' => 'VU',
		'republic of yemen' => 'YE',
		'republic of zambia' => 'ZM',
		'republic of zimbabwe' => 'ZW',
		'romania' => 'RO',
		'russia' => 'RU',
		'russian federation' => 'RU',
		'rwanda' => 'RW',
		'rwandese republic' => 'RW',
		'réunion' => 'RE',
		'saint barthélemy' => 'BL',
		'saint helena, ascension and tristan da cunha' => 'SH',
		'saint kitts and nevis' => 'KN',
		'saint lucia' => 'LC',
		'saint martin (french part)' => 'MF',
		'saint pierre and miquelon' => 'PM',
		'saint vincent and the grenadines' => 'VC',
		'samoa' => 'WS',
		'san marino' => 'SM',
		'sao tome and principe' => 'ST',
		'saudi arabia' => 'SA',
		'scotland' => 'GB',
		'senegal' => 'SN',
		'serbia' => 'RS',
		'seychelles' => 'SC',
		'sierra leone' => 'SL',
		'singapore' => 'SG',
		'sint maarten (dutch part)' => 'SX',
		'slovak republic' => 'SK',
		'slovakia' => 'SK',
		'slovenia' => 'SI',
		'socialist republic of viet nam' => 'VN',
		'solomon islands' => 'SB',
		'somalia' => 'SO',
		'south africa' => 'ZA',
		'south georgia and the south sandwich islands' => 'GS',
		'south korea' => 'KR',
		'south sudan' => 'SS',
		'spain' => 'ES',
		'sri lanka' => 'LK',
		'state of israel' => 'IL',
		'state of kuwait' => 'KW',
		'state of qatar' => 'QA',
		'sudan' => 'SD',
		'sultanate of oman' => 'OM',
		'suriname' => 'SR',
		'svalbard and jan mayen' => 'SJ',
		'swaziland' => 'SZ',
		'sweden' => 'SE',
		'swiss confederation' => 'CH',
		'switzerland' => 'CH',
		'syria' => 'SY',
		'syrian arab republic' => 'SY',
		'taiwan' => 'TW',
		'taiwan, province of china' => 'TW',
		'tajikistan' => 'TJ',
		'tanzania' => 'TZ',
		'tanzania, united republic of' => 'TZ',
		'thailand' => 'TH',
		'the netherlands' => 'NL',
		'the state of eritrea' => 'ER',
		'the state of palestine' => 'PS',
		'timor-leste' => 'TL',
		'togo' => 'TG',
		'togolese republic' => 'TG',
		'tokelau' => 'TK',
		'tonga' => 'TO',
		'trinidad and tobago' => 'TT',
		'tunisia' => 'TN',
		'turkey' => 'TR',
		'turkiye' => 'TR',
		'turkmenistan' => 'TM',
		'turks and caicos islands' => 'TC',
		'tuvalu' => 'TV',
		'türkiye' => 'TR',
		'u.k' => 'GB',
		'u.s' => 'US',
		'u.s.a' => 'US',
		'uae' => 'AE',
		'uganda' => 'UG',
		'uk' => 'GB',
		'ukraine' => 'UA',
		'union of the comoros' => 'KM',
		'united arab emirates' => 'AE',
		'united kingdom' => 'GB',
		'united kingdom of great britain and northern ireland' => 'GB',
		'united mexican states' => 'MX',
		'united republic of tanzania' => 'TZ',
		'united states' => 'US',
		'united states minor outlying islands' => 'UM',
		'united states of america' => 'US',
		'uruguay' => 'UY',
		'usa' => 'US',
		'uzbekistan' => 'UZ',
		'vanuatu' => 'VU',
		'vatican' => 'VA',
		'vatican city' => 'VA',
		'venezuela' => 'VE',
		'venezuela, bolivarian republic of' => 'VE',
		'viet nam' => 'VN',
		'vietnam' => 'VN',
		'virgin islands of the united states' => 'VI',
		'virgin islands, british' => 'VG',
		'virgin islands, u.s' => 'VI',
		'wales' => 'GB',
		'wallis and futuna' => 'WF',
		'western sahara' => 'EH',
		'yemen' => 'YE',
		'zambia' => 'ZM',
		'zimbabwe' => 'ZW',
		'åland islands' => 'AX',
	);
	return $map;
}
