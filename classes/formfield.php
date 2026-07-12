<?php
/*

Module: formfield class
Version 1.0
Author: Richard Catto
Creation Date: 2020.12.30 17h50
Description:
methods return html tags to generate form fields
added methods to generate tables
added div methods
default classes are bootstrap 5 compatible

*/

class formfield {

  function __construct() {
  }

  // Form methods

  // ADD if necessary:
  // enctype= application/x-www-form-urlencoded [default] | multipart/form-data [file upload] | text/plain [special chars not encoded]
  // enctype must have method="POST"
  // attributes: autocomplete | novalidate
  // more info: https://www.w3schools.com/html/html_forms_attributes.asp
  public function FF_FormOpen($fname,$faction,$fmethod,$fclass = "") {
    $fclass = ($fclass == "") ? "" : " class=\"{$fclass}\"";
    $html = "<FORM{$fclass} name=\"{$fname}\" ACTION=\"{$faction}\" METHOD=\"{$fmethod}\" role=\"form\">";
    return $html;
  }

  public function FF_FormClose() {
    return "</FORM>";
  }

  public function FF_FieldsetOpen($fclass = "") {
    $fclass = ($fclass == "") ? "" : " class=\"{$fclass}\"";
    $html = "<FIELDSET{$fclass}>";
    return $html;
  }

  public function FF_FieldsetClose() {
    return "</FIELDSET>";
  }

  public function FF_Legend($legname = "") {
    $html = ($legname == "") ? "" : "<LEGEND>{$legname}</LEGEND>";
    return $html;
  }

  public function FF_hidden($hname,$hvalue) {
    $html = "<input name=\"{$hname}\" type=\"hidden\" value=\"{$hvalue}\">";
    return $html;
  }

  // alt: form-check-label
  public function FF_Label($lname,$fname,$lclass = "col-form-label") {
    $lclass = ($lclass == "") ? "" : " class=\"{$lclass}\"";
    $html = "<label{$lclass} for=\"{$fname}\">{$lname}</label>";
    return $html;
  }

  public function FF_DropDown($ddname,$ddoptions,$ddclass = "form-select") {
    $ddclass = ($ddclass == "") ? "" : " class=\"{$ddclass}\"";
    $html = "<select{$ddclass} name=\"{$ddname}\">{$ddoptions}</select>";
    return $html;
  }

  public function FF_input($iname,$itype,$ivalue = "",$istatus = "",$iclass = "form-control",$pholder = "") {
    $iclass = ($iclass == "") ? "" : " class=\"{$iclass}\"";
    $html = "<input{$iclass} type=\"{$itype}\" name=\"{$iname}\" placeholder=\"{$pholder}\" value=\"{$ivalue}\"{$istatus}>";
    return $html;
	}

  public function FF_textarea($tname,$tvalue = "",$tjs = "",$tclass = "form-control",$tawidth = "1275",$taheight = "900",$pholder = "") {
    $tclass = ($tclass == "") ? "" : " class=\"{$tclass}\"";
    $tastyle = "style=\"width: {$tawidth}px; height: {$taheight}px;\"";
    $html = "<textarea{$tclass} {$tastyle} id=\"{$tname}\" placeholder=\"{$pholder}\" name=\"{$tname}\">{$tvalue}</textarea>";
    $html .= $tjs;
    return $html;
  }

  public function FF_radio($rname,$rid,$rvalue,$rchecked,$rclass = "form-check-input") {
    $rclass = ($rclass == "") ? "" : " class=\"{$rclass}\"";
    $html = "<input{$rclass} type=\"radio\" name=\"{$rname}\" id=\"{$rid}\" value=\"{$rvalue}\"{$rchecked}>";
    return $html;
  }

  public function FF_checkbox($rname,$rid,$rvalue,$rchecked,$rclass = "form-check-input") {
    $rclass = ($rclass == "") ? "" : " class=\"{$rclass}\"";
    $html = "<input{$rclass} type=\"checkbox\" name=\"{$rname}\" id=\"{$rid}\" value=\"{$rvalue}\"{$rchecked}>";
    return $html;
  }

  public function FF_Button($btype,$blabel,$bclass = "btn btn-primary") {
    $bclass = ($bclass == "") ? "" : " class=\"{$bclass}\"";
    $html = "<button{$bclass} type=\"{$btype}\">{$blabel}</button>";
    return $html;
  }

  // Table methods

  public function FF_TableOpen($tclass = "") {
    $tclass = ($tclass == "") ? "" : " class=\"{$tclass}\"";
    $html = "<table{$tclass}>";
    return $html;
  }

  public function FF_TableClose() {
    return "</table>";
  }

  public function FF_TheadOpen($thclass = "") {
    $thclass = ($thclass == "") ? "" : " class=\"{$thclass}\"";
    $html = "<thead{$thclass}>";
    return $html;
  }

  public function FF_TheadClose() {
    return "</thead>";
  }

  public function FF_TrOpen($trclass = "") {
    $trclass = ($trclass == "") ? "" : " class=\"{$trclass}\"";
    $html = "<tr{$trclass}>";
    return $html;
  }

  public function FF_TrClose() {
    return "</tr>";
  }

  public function FF_TbodyOpen($tbclass = "") {
    $tbclass = ($tbclass == "") ? "" : " class=\"{$tbclass}\"";
    $html = "<tbody{$tbclass}>";
    return $html;
  }

  public function FF_TbodyClose() {
    return "</tbody>";
  }

  public function FF_Th($thlabel,$thclass = "",$thattr = "") {
    $thclass = ($thclass == "") ? "" : " class=\"{$thclass}\"";
    $html = "<th{$thclass}{$thattr}>{$thlabel}</th>";
    return $html;
  }

  public function FF_Td($tdlabel,$tdclass = "",$tdattr = "") {
    $tdclass = ($tdclass == "") ? "" : " class=\"{$tdclass}\"";
    $html = "<td{$tdclass}{$tdattr}>{$tdlabel}</td>";
    return $html;
  }

  // Div methods

  public function FF_DivOpen($dclass = "",$id = "") {
    $did = ($id == "") ? "" : " id=\"{$id}\"";
    $dclass = ($dclass == "") ? "" : " class=\"{$dclass}\"";
    $html = "<div{$dclass}{$did}>";
    return $html;
  }

  public function FF_DivClose() {
    return "</div>";
  }

  // Generic DropDown methods

  public function CreateGenderHTMLDropDown($gender = "") {
    $html = "";
    $html .= "<option " . ($gender == "" ? "selected " : "") . "value=\"\"></option>";
    $html .= "<option " . ($gender == "Female" ? "selected " : "") . "value=\"Female\">Female</option>";
    $html .= "<option " . ($gender == "Genderqueer" ? "selected " : "") . "value=\"Genderqueer\">Genderqueer</option>";
    $html .= "<option " . ($gender == "Hermaphrodite" ? "selected " : "") . "value=\"Hermaphrodite\">Hermaphrodite</option>";
    $html .= "<option " . ($gender == "Intersex" ? "selected " : "") . "value=\"Intersex\">Intersex</option>";
    $html .= "<option " . ($gender == "Legal entity" ? "selected " : "") . "value=\"Legal entity\">Legal entity</option>";
    $html .= "<option " . ($gender == "Male" ? "selected " : "") . "value=\"Male\">Male</option>";
    $html .= "<option " . ($gender == "Non-binary" ? "selected " : "") . "value=\"Non-binary\">Non-binary</option>";
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
    $html .= "<option " . ($province == "" ? "selected" : "") . "value=\"\">Select Province</option>";
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

  public function CreateCountryHTMLDropDown($country1 = '') {
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
