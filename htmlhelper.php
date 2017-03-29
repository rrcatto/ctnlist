<?php
/*

Module: htmlhelper class
Author: Richard Catto
Creation Date: 2011-10-22

Description:
functions to out html for forms

*/

class htmlhelper {

	protected $fat;

	function __construct($fat) {
		$this->fat = $fat;
	}

	public function CreateFromHTMLDropDown($from1) {
		$html = "";
		$from_addresses = $this->fat->get('from_addresses');
		// reset($from_addresses);
		$html .= "<option " . ($from1 == "" ? "selected " : "") . "value=\"\"></option>";
		foreach ($from_addresses as $from2) {
			$html .= "<option " . ($from2 == $from1 ? "selected " : "") . "value=\"$from2\">$from2</option>";
		}
		return $html;
	}
	
	public function CreateIncomeHTMLDropDown($income = "") {
		$html = "";
		$html .= "<option " . ($income == "" ? "selected " : "") . "value=\"\"></option>";
		$html .= "<option " . ($income == "R0" ? "selected " : "") . "value=\"R0\">R0</option>";
		$html .= "<option " . ($income == "R1 - R2999" ? "selected " : "") . "value=\"R1 - R2999\">R1 - R2999</option>";
		$html .= "<option " . ($income == "R3000 - R5999" ? "selected " : "") . "value=\"R3000 - R5999\">R3000 - R5999</option>";
		$html .= "<option " . ($income == "R6000 - R11999" ? "selected " : "") . "value=\"R6000 - R11999\">R6000 - R11999</option>";
		$html .= "<option " . ($income == "R12000 - R23999" ? "selected " : "") . "value=\"R12000 - R23999\">R12000 - R23999</option>";
		$html .= "<option " . ($income == "R24000 - R47999" ? "selected " : "") . "value=\"R24000 - R47999\">R24000 - R47999</option>";
		$html .= "<option " . ($income == "R48000 - R95999" ? "selected " : "") . "value=\"R48000 - R95999\">R48000 - R95999</option>";
		$html .= "<option " . ($income == "R96000+" ? "selected " : "") . "value=\"R96000+\">R96000+</option>";
		return $html;
	}

	public function CreateLoanHTMLDropDown($income = "Choose loan amount") {
		$html = "";
		$html .= "<option " . ($income == "Choose loan amount" ? "selected " : "") . "value=\"Choose loan amount\">Choose loan amount</option>";
		$html .= "<option " . ($income == "R0" ? "selected " : "") . "value=\"R0\">R0</option>";
		$html .= "<option " . ($income == "R1 - R49999" ? "selected " : "") . "value=\"R1 - R49999\">R1 - R49 999</option>";
		$html .= "<option " . ($income == "R50000 - R499999" ? "selected " : "") . "value=\"R50000 - R499999\">R50 000 - R499 999</option>";
		$html .= "<option " . ($income == "R500000 - R999999" ? "selected " : "") . "value=\"R500000 - R999999\">R500 000 - R999 999</option>";
		$html .= "<option " . ($income == "R1000000 - R1500000" ? "selected " : "") . "value=\"R1000000 - R1500000\">R1 000 000 - R1 500 000</option>";
		return $html;
	}

	public function CreateGenderHTMLDropDown($gender = "") {
		$html = "";
		$html .= "<option " . ($gender == "" ? "selected " : "") . "value=\"\"></option>";
		$html .= "<option " . ($gender == "Female" ? "selected " : "") . "value=\"Female\">Female</option>";
		$html .= "<option " . ($gender == "Genderqueer" ? "selected " : "") . "value=\"Genderqueer\">Genderqueer</option>";
		$html .= "<option " . ($gender == "Hermaphrodite" ? "selected " : "") . "value=\"Hermaphrodite\">Hermaphrodite</option>";
		$html .= "<option " . ($gender == "Legal entity" ? "selected " : "") . "value=\"Legal entity\">Legal entity</option>";
		$html .= "<option " . ($gender == "Male" ? "selected " : "") . "value=\"Male\">Male</option>";
		$html .= "<option " . ($gender == "Trans man" ? "selected " : "") . "value=\"Trans man\">Trans man</option>";
		$html .= "<option " . ($gender == "Trans woman" ? "selected " : "") . "value=\"Trans woman\">Trans woman</option>";
		$html .= "<option " . ($gender == "Transgender" ? "selected " : "") . "value=\"Transgender\">Transgender</option>";
		$html .= "<option " . ($gender == "Transexual" ? "selected " : "") . "value=\"Transexual\">Transexual</option>";
		$html .= "<option " . ($gender == "Tranvestite" ? "selected " : "") . "value=\"Tranvestite\">Tranvestite</option>";
		return $html;
	}

	public function CreateProvinceHTMLDropDown($province = "") {
		$html = "";
		// $html .= "<option disabled selected value=\"\">Province</option>";
		$html .= "<option " . ($province == "" ? "selected" : "") . "value=\"\"></option>";
		$html .= "<option " . ($province == "Western Cape" ? "selected " : "") . "value=\"Western Cape\">Western Cape</option>";
		$html .= "<option " . ($province == "Gauteng" ? "selected " : "") . "value=\"Gauteng\">Gauteng</option>";
		$html .= "<option " . ($province == "KwaZulu-Natal" ? "selected " : "") . "value=\"KwaZulu-Natal\">KwaZulu-Natal</option>";
		$html .= "<option " . ($province == "Eastern Cape" ? "selected " : "") . "value=\"Eastern Cape\">Eastern Cape</option>";
		$html .= "<option " . ($province == "Free State" ? "selected " : "") . "value=\"Free State\">Free State</option>";
		$html .= "<option " . ($province == "Limpopo" ? "selected " : "") . "value=\"Limpopo\">Limpopo</option>";
		$html .= "<option " . ($province == "Mpumalanga" ? "selected " : "") . "value=\"Mpumalanga\">Mpumalanga</option>";
		$html .= "<option " . ($province == "North-West" ? "selected " : "") . "value=\"North-West\">North-West</option>";
		$html .= "<option " . ($province == "Northern Cape" ? "selected " : "") . "value=\"Northern Cape\">Northern Cape</option>";		
		$html .= "<option " . ($province == "Outside South Africa" ? "selected " : "") . "value=\"Outside South Africa\">Outside South Africa</option>";
		return $html;
	}

	public function CreateCountryHTMLDropDown($country1 = "") {
		$html = "";
		$country = [
		  "Afghanistan",
		  "Albania",
		  "Algeria",
		  "Andorra",
		  "Angola",
		  "Anguilla",
		  "Antarctica",
		  "Antigua and Barbuda",
		  "Argentina",
		  "Armenia",
		  "Aruba",
		  "Australia",
		  "Austria",
		  "Azerbaijan",
		  "Bahrain",
		  "Bangladesh",
		  "Barbados",
		  "Belarus",
		  "Belgium",
		  "Belize",
		  "Benin",
		  "Bermuda",
		  "Bhutan",
		  "Bolivia",
		  "Bosnia and Herzegovina",
		  "Botswana",
		  "Brazil",
		  "British Virgin Islands",
		  "Bulgaria",
		  "Burkina Faso",
		  "Burma",
		  "Burundi",
		  "Cambodia",
		  "Cameroon",
		  "Canada",
		  "Cape Verde",
		  "Cayman Islands",
		  "Central African Republic",
		  "Chad",
		  "Chile",
		  "China",
		  "Christmas Island",
		  "Colombia",
		  "Comoros",
		  "Cook Islands",
		  "Costa Rica",
		  "Cote D' Ivoire",
		  "Croatia",
		  "Cuba",
		  "Cyprus",
		  "Czech Republic",
		  "Democratic Republic of The Congo",
		  "Denmark",
		  "Djibouti",
		  "Dominica",
		  "Dominican Republic",
		  "Ecuador",
		  "Egypt",
		  "El Salvador",
		  "Equatorial Guinea",
		  "Eritrea",
		  "Estonia",
		  "Ethiopia",
		  "Falkland Islands",
		  "Fiji",
		  "Finland",
		  "Former Yugoslav Rep. of Macedonia",
		  "France",
		  "French Guiana",
		  "French Polynesia",
		  "Gabon",
		  "Georgia",
		  "Germany",
		  "Ghana",
		  "Gibraltar",
		  "Greece",
		  "Greenland",
		  "Grenada",
		  "Guadeloupe",
		  "Guatemala",
		  "Guinea",
		  "Guinea-bissau",
		  "Guyana",
		  "Haiti",
		  "Honduras",
		  "Hungary",
		  "Iceland",
		  "India",
		  "Indonesia",
		  "Iran",
		  "Iraq",
		  "Ireland",
		  "Isle of Man",
		  "Israel",
		  "Italy",
		  "Jamaica",
		  "Jordan",
		  "Kazakhstan",
		  "Kenya",
		  "Kiribati",
		  "Kuwait",
		  "Kyrgyzstan",
		  "Laos",
		  "Latvia",
		  "Lebanon",
		  "Lesotho",
		  "Liberia",
		  "Libya",
		  "Liechtenstein",
		  "Lithuania",
		  "Luxembourg",
		  "Madagascar",
		  "Malawi",
		  "Malaysia",
		  "Maldives",
		  "Mali",
		  "Malta",
		  "Martinique",
		  "Mauritania",
		  "Mauritius",
		  "Mayotte",
		  "Mexico",
		  "Moldova",
		  "Monaco",
		  "Morocco",
		  "Mozambique",
		  "Namibia",
		  "Nauru",
		  "Nepal",
		  "Netherlands",
		  "Netherlands Antilles",
		  "New Caledonia",
		  "New Zealand",
		  "Nicaragua",
		  "Niger",
		  "Nigeria",
		  "Norway",
		  "Oman",
		  "Pakistan",
		  "Panama",
		  "Papua New Guinea",
		  "Paraguay",
		  "Peru",
		  "Philippines",
		  "Poland",
		  "Portugal",
		  "Qatar",
		  "Republic of The Congo",
		  "Reunion",
		  "Romania",
		  "Russia",
		  "Rwanda",
		  "S. Georgia and S. Sandwich Islands",
		  "Saint Lucia",
		  "San Marino",
		  "Sao Tome and Principe",
		  "Saudi Arabia",
		  "Senegal",
		  "Seychelles",
		  "Sierra Leone",
		  "Slovakia",
		  "Slovenia",
		  "Solomon Islands",
		  "Somalia",
		  "South Africa",
		  "Spain",
		  "Sri Lanka",
		  "St. Helena",
		  "St. Kitts and Nevis",
		  "Sudan",
		  "Suriname",
		  "Swaziland",
		  "Sweden",
		  "Switzerland",
		  "Syria",
		  "Taiwan",
		  "Tajikistan",
		  "Thailand",
		  "The Bahamas",
		  "The Gambia",
		  "Togo",
		  "Tonga",
		  "Trinidad and Tobago",
		  "Tunisia",
		  "Turkey",
		  "Turkmenistan",
		  "Turks and Caicos Islands",
		  "Tuvalu",
		  "Uganda",
		  "Ukraine",
		  "United Arab Emirates",
		  "United Kingdo",
		  "United Kingdom",
		  "United Republic of Tanzania",
		  "United States",
		  "Uruguay",
		  "Uzbekistan",
		  "Vanuatu",
		  "Venezuela",
		  "Vietnam",
		  "West Bank",
		  "Western Sahara",
		  "Yemen",
		  "Yugoslavia",
		  "Zambia",
		  "Zimbabwe"
		];
		
		$html .= "<option " . ($country1 == "" ? "selected " : "") . "value=\"\"></option>";
		
		foreach ($country as $country2) {
			$html .= "<option " . ($country2 == $country1 ? "selected " : "") . "value=\"$country2\">$country2</option>";
		}
		return $html;
	}
}
?>