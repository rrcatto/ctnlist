<?php
/*

Module: htmlhelper class
Version 4.3
Author: Richard Catto
Creation Date: 2011-10-22

*/

class htmlhelper
{

  protected $fat;
  protected $BaseURL;

  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->BaseURL = $fat->get('BaseURL');
  }

  public function paginate($page,$action,$querystring = '') {
    $html = '';
    $pageno = (int) $page['pos'] + 1;
    $lastpage = (int) $page['count'];
    $totalmatches = (int) $page['total'];
    if ($pageno > $lastpage) {
      $pageno = $lastpage;
    } elseif ($pageno < 1) {
      $pageno = 1;
    }
    $html .= "<div class=\"d-flex flex-row justify-content-between\">";
    if ($lastpage == 1) {
      $pgbtn = "1 page";
    } else {
      $pgbtn = "{$pageno} of {$lastpage} pages";
    }
    $html .= "<div class=\"p-2\"><button class=\"btn btn-primary\">{$pgbtn}</button></div>";
    $html .= "<div class=\"p-2\">";
    if ($lastpage == 1) {
      $html .= "&nbsp;";
    } else {
      $html .= "<nav aria-label=\"Page navigation\"><ul class=\"pagination\">";
      $pprevpage = max($pageno - 2,1);
      $prevpage = $pageno - 1;
      $nextpage = $pageno + 1;
      $nnextpage = min($pageno + 2,$lastpage);
      if ($pageno == 1) {
        $html .= "<li class=\"page-item disabled\"><a class=\"page-link\" href=\"#\">|&lt;</a></li>";
        $html .= "<li class=\"page-item disabled\"><a class=\"page-link\" href=\"#\">&laquo;</a></li>";
        $html .= "<li class=\"page-item active\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$pageno}{$querystring}\">{$pageno}</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$nextpage}{$querystring}\">{$nextpage}</a></li>";
        if ($nextpage <> $nnextpage) {
          $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$nnextpage}{$querystring}\">{$nnextpage}</a></li>";
        }
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$nextpage}{$querystring}\">&raquo;</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$lastpage}{$querystring}\">&gt;|</a></li>";
      } elseif ($pageno == $lastpage) {
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/1{$querystring}\">|&lt;</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$prevpage}{$querystring}\">&laquo;</a></li>";
        if ($prevpage <> $pprevpage) {
          $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$pprevpage}{$querystring}\">{$pprevpage}</a></li>";
        }
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$prevpage}{$querystring}\">{$prevpage}</a></li>";
        $html .= "<li class=\"page-item active\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$pageno}{$querystring}\">{$pageno}</a></li>";
        $html .= "<li class=\"page-item disabled\"><a class=\"page-link\" href=\"#\">&raquo;</a></li>";
        $html .= "<li class=\"page-item disabled\"><a class=\"page-link\" href=\"#\">&gt;|</a></li>";
      } else {
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/1{$querystring}\">|&lt;</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$prevpage}{$querystring}\">&laquo;</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$prevpage}{$querystring}\">{$prevpage}</a></li>";
        $html .= "<li class=\"page-item active\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$pageno}{$querystring}\">{$pageno}</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$nextpage}{$querystring}\">{$nextpage}</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$nextpage}{$querystring}\">&raquo;</a></li>";
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{{@BaseURL}}{$action}/{$lastpage}{$querystring}\">&gt;|</a></li>";
      }
      $html .= "</ul></nav>";
    }
    $html .= "</div>";
    if ($totalmatches == 1) {
      $rcdbtn = "1 record";
    } else {
      $rcdbtn = "{$totalmatches} records";
    }
    $html .= "<div class=\"p-2\"><button class=\"btn btn-primary\">{$rcdbtn}</button></div></div>";
    return $html;
  }

    // deprecated - using a text input for this
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

    // not in use
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

    // not in use
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

    // not in use
    public function CreateVenueHTMLDropDown($venue = "") {
        $html = "";
        $html .= "<option " . ($venue == "" ? "selected " : "") . "value=\"\">Select Venue</option>";
        $html .= "<option " . ($venue == "Johannesburg" ? "selected " : "") . "value=\"Johannesburg\">Johannesburg</option>";
        $html .= "<option " . ($venue == "Cape Town" ? "selected " : "") . "value=\"Cape Town\">Cape Town</option>";
        $html .= "<option " . ($venue == "Durban" ? "selected " : "") . "value=\"Durban\">Durban</option>";
        $html .= "<option " . ($venue == "Port Elizabeth" ? "selected " : "") . "value=\"Port Elizabeth\">Port Elizabeth</option>";
        $html .= "<option " . ($venue == "Other City" ? "selected " : "") . "value=\"Other City\">Other City</option>";
        $html .= "<option " . ($venue == "On Site" ? "selected " : "") . "value=\"On Site\">On Site</option>";
        return $html;
    }
}