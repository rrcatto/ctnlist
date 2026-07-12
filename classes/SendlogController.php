<?php
/*

Module: SendlogController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-12

*/

class SendlogController extends Controller
{

  protected $fat;
  protected $dbPDO;
  protected $BaseURL;

  public $sendlog;

	// Uses the application database connection provided by F3.
  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');

    $this->sendlog = new SendlogM($fat);
  }

  public function SendlogCount() {
    // return $this->sendlog->sendlogcount();
    return $this->sendlog->recentmonthcount();
  }

  public function logSend($muid,$msgtype,$email,$listname,$subject) {
    $this->sendlog->reset();
    $this->sendlog->sl_muid = $muid;
    $this->sendlog->sl_type = $msgtype;
    $this->sendlog->sl_email = $email;
	$this->sendlog->sl_listname = $listname;
    $this->sendlog->sl_subject = $subject;
    $this->sendlog->save();
    return true;
  }

  public function ShowGroupedStats() {
    $html = "";

    $sql = "SELECT sl_type, count(*) AS sl_total FROM sendlog GROUP BY sl_type";
    $result = $this->dbPDO->exec($sql);
    $html .= "<div class=\"row p-1\"><div class=\"col\">";
    $html .= "<button class=\"btn btn-primary btn-sm\">0000/00</button>";
    foreach ($result as $row) {
      // $html .= "</div><div class=\"col col-auto\">";
      $sltype = $row['sl_type'];
      $sltotal = $row['sl_total'];
      // $html .= " <button class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></button>";
      $html .= "<a href=\"{$this->BaseURL}sendlog?t={$sltype}\" class=\"btn btn-primary btn-sm mx-1\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></a>";
    }
    $html .= "</div></div>";

    $sql = "SELECT EXTRACT(YEAR FROM sl_datesent) AS sl_year, EXTRACT(MONTH FROM sl_datesent) AS sl_month, sl_type, count(*) AS sl_total FROM sendlog GROUP BY EXTRACT(YEAR FROM sl_datesent), EXTRACT(MONTH FROM sl_datesent), sl_type ORDER BY sl_year, sl_month, sl_type";
    $result = $this->dbPDO->exec($sql);
    $month = '';
    $rw = 0;
    foreach ($result as $row) {
      $slyear = $row['sl_year'];
      $slmonth = $row['sl_month'];
      $slmonth = sprintf("%02d",$slmonth);
      $sltype = $row['sl_type'];
      $sltotal = $row['sl_total'];
      if ($month <> $slmonth) {
        if ($rw > 0) {
          $html .= "</div></div>";
        }
        $html .= "<div class=\"row p-1\"><div class=\"col\">";
        $html .= "<button class=\"btn btn-primary btn-sm\">{$slyear}/{$slmonth}</button>";
        $month = $slmonth;
        $rw++;
      }
      // $html .= "</div><div class=\"col col-auto\">";
      // $html .= " <button class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-secondary\">{$sltotal}</span></button>";
      $html .= "<a href=\"{$this->BaseURL}sendlog?t={$sltype}\" class=\"btn btn-primary btn-sm mx-1\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></a>";
      // $dstring = sprintf("<p class=\"{{@pclass}\"><small>%04d-%02d %s: %d</small></p>",$slyear,$slmonth,$sltype,$sltotal);
      // $html .= $dstring;
    }
    $html .= "</div></div>";
    return $html;
  }

  public function ShowStats() {
    $html = "";
    $sql = "SELECT sl_type, count(*) AS sl_total FROM sendlog GROUP BY sl_type";
    $result = $this->dbPDO->exec($sql);
    $html .= "<div class=\"row\"><div class=\"col\">";
    $html .= "<button class=\"btn btn-secondary btn-sm\">A</button>";
    foreach ($result as $row) {
      $sltype = $row['sl_type'];
      $sltotal = $row['sl_total'];
      // $html .= " <button class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></button>";
      $html .= "<a href=\"{$this->BaseURL}sendlog?t={$sltype}\" class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></a>";
    }
    $html .= "</div></div>";
    $curr_year = date("Y");
    $curr_month = date("m");
    $sql = "SELECT sl_type, count(*) AS sl_total FROM sendlog WHERE EXTRACT(YEAR FROM sl_datesent) = :cyear AND EXTRACT(MONTH FROM sl_datesent) = :cmonth GROUP BY sl_type ORDER BY sl_type";
    $args = array(':cyear' => $curr_year,':cmonth' => $curr_month);
    $result = $this->dbPDO->exec($sql,$args);
    $html .= "<div class=\"row\"><div class=\"col\">";
    $html .= "<button class=\"btn btn-secondary btn-sm\">C</button>";
    foreach ($result as $row) {
      $sltype = $row['sl_type'];
      $sltotal = $row['sl_total'];
      // $html .= " <button class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></button>";
      $html .= "<a href=\"{$this->BaseURL}sendlog?t={$sltype}\" class=\"btn btn-secondary btn-sm\">{$sltype} <span class=\"badge badge-primary\">{$sltotal}</span></a>";
    }
    $html .= "</div></div>";
    return $html;
  }

  public function CreateSltypeHTMLDropDown($sltype = '') {
    $html = "";
    $html .= "<option " . ($sltype == "" ? "selected " : "") . "value=\"\">All Types</option>";
    $html .= "<option " . ($sltype == "CONFIRM" ? "selected " : "") . "value=\"CONFIRM\">CONFIRM</option>";
    $html .= "<option " . ($sltype == "CONTACT" ? "selected " : "") . "value=\"CONTACT\">CONTACT</option>";
    $html .= "<option " . ($sltype == "FORWARD-MESSAGE" ? "selected " : "") . "value=\"FORWARD-MESSAGE\">FORWARD-MESSAGE</option>";
    $html .= "<option " . ($sltype == "MESSAGE" ? "selected " : "") . "value=\"MESSAGE\">MESSAGE</option>";
    $html .= "<option " . ($sltype == "SUBSCRIBE" ? "selected " : "") . "value=\"SUBSCRIBE\">SUBSCRIBE</option>";
    $html .= "<option " . ($sltype == "UNSUBSCRIBE" ? "selected " : "") . "value=\"UNSUBSCRIBE\">UNSUBSCRIBE</option>";
    $html .= "<option " . ($sltype == "UPDATE-PROFILE" ? "selected " : "") . "value=\"UPDATE-PROFILE\">UPDATE-PROFILE</option>";
    $html .= "<option " . ($sltype == "UPDATE-USER" ? "selected " : "") . "value=\"UPDATE-USER\">UPDATE-USER</option>";
    return $html;
  }

  public function CreateSendlogHTMLList($ssemail = '',$stype = '',$pageno = 1,$numrows = 25) {
    $html = "";
    if ($this->fat->get('uadmin') == 1) {
      $html .= $this->ShowGroupedStats();
      // $html .= $this->ShowStats();

      $ff = new formfield;

      $html .= $ff->FF_FormOpen("sendlogform","{{@BaseURL}}sendlog","GET");
      $html .= $ff->FF_DivOpen("{{@rowclass}}");

      $html .= $ff->FF_DivOpen("{{@columnclass4}}");
      $html .= $ff->FF_input("e","search",$ssemail,"","{{@inputclass}}");
      $html .= $ff->FF_Label("Email address","e","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass4}}");
      $html .= $ff->FF_DropDown("t",$this->CreateSltypeHTMLDropDown($stype),"{{@selectclass}}");
      $html .= $ff->FF_Label("Message Type","t","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass4}}");
      $html .= $ff->FF_Button("submit","Search Sendlog","{{@buttonclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_FormClose();
    } else {
      $html .= "<p class=\"{{@pclass}}\">Access denied</p>";
      return $html;
    }
    $filter = array('sl_type like :sltype and LOWER(sl_email) like LOWER(:email)', ':sltype' => "{$stype}%", ':email' => "%{$ssemail}%");
    $totalmatches = $this->sendlog->count($filter);
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\">No emails match that search.</p>";
      return $html;
    }
    $lastpage = ceil($totalmatches/$numrows);
    $pageno = (int) $pageno;
    if ($pageno > $lastpage) {
      $pageno = $lastpage;
    } elseif ($pageno < 1) {
      $pageno = 1;
    }

    $ff = new formfield;
    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Sent","{{@thclass}}");
    $html .= $ff->FF_Th("Type","{{@thclass}}");
    $html .= $ff->FF_Th("Email","{{@thclass}}");
    $html .= $ff->FF_Th("Listname","{{@thclass}}");
    $html .= $ff->FF_Th("Subject","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $filter = array('sl_type like :sltype and LOWER(sl_email) like LOWER(:email)', ':sltype' => "{$stype}%", ':email' => "%{$ssemail}%");
    $page = $this->sendlog->paginate($pageno - 1,$numrows,$filter,array('order' => 'sl_datesent DESC'));
    foreach ($page['subset'] as $row) {
      $sldatesent = $row["sl_datesent"];
      $sltype = $row["sl_type"];
      $slemail = $row["sl_email"];
      $sllistname = $row['sl_listname'];
      $slsubject = stripslashes($row['sl_subject']);
      $slsub = substr($slsubject,0,60) . '...';
      $slmsg = "<span data-toggle=\"tooltip\" title=\"{$slsubject}\">{$slsub}</span>";

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($sldatesent,"");
      $html .= $ff->FF_Td($sltype,"");
      $html .= $ff->FF_Td($slemail,"");
      $html .= $ff->FF_Td($sllistname,"");
      $html .= $ff->FF_Td($slmsg,"");
      $html .= $ff->FF_TrClose();
    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = 'sendlog';
    $qsemail = urlencode($ssemail);
    $qsltype = urlencode($stype);
    $querystring = '';
    if ($ssemail == '') {
      if ($stype == '') {
        $querystring = '';
      } else {
        $querystring = "?t={$qsltype}";
      }
    } else {
      if ($stype == '') {
        $querystring = "?e={$qsemail}";
      } else {
        $querystring = "?e={$qsemail}&t={$qsltype}";
      }
    }

    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action,$querystring);
    return $html;
  }
}