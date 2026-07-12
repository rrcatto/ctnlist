<?php
/*

Module: ArchivesController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-04

*/

class ArchivesController extends Controller {
  protected $fat;
  protected $BaseURL;

  // archive variables
  public $archive;
  public $message, $template;

  function __construct(Base $fat, MessagesController $message, TemplatesController $template) {
    $this->fat = $fat;
    $this->BaseURL = $fat->get('BaseURL');

    $this->message = $message;
    $this->template = $template;

    $this->archive = new ArchivesM($fat);
  }

  // create a browseable list of available archives
  public function CreateArchivesHTMLList($pageno = 1,$numrows = 25) {
    $html = "";
    $totalmatches = $this->archive->count();
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\"There are no archives.</p>";
      return $html;
    }
    $lastpage = ceil($totalmatches/$numrows);
    $pageno = (int) $pageno;
    if ($pageno > $lastpage) {
      $pageno = $lastpage;
    } elseif ($pageno < 1) {
      $pageno = 1;
    }
    /*
    if ($this->fat->get('uadmin') == 1) {
      $html .= "<table class=\"table table-striped table-hover table-bordered\"><thead class=\"table-info\"><tr><th>Subject</th><th>Created On</th><th>Viewed</th></tr></thead><tbody>";
    } else {
      $html .= "<table class=\"table table-striped table-bordered\"><thead class=\"table-info\"><tr><th>Subject</th><th>Created On</th></tr></thead><tbody>";
    }
    */

    $ff = new formfield;
    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Archived message","{{@thclass}}");
    $html .= $ff->FF_Th("Created","{{@thclass}}");
    $html .= $ff->FF_Th("Views","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $page = $this->archive->paginate($pageno - 1,$numrows,null,array('order' => 'a_id DESC'));
    foreach ($page['subset'] as $row) {
      $aid = $row["a_id"];
      $asubject = substr(stripslashes($row['a_subject']),0,80) . '...';
      $amsg = "<a href=\"{{@BaseURL}}archive/{$aid}\">{$asubject}</a>";
      $adatecreated = $row['a_datecreated'];
      $aviewed = $row['a_viewed'];
      /*
      if ($this->fat->get('uadmin') == 1) {
        $html .= "<tr class=\"bg-light\"><td><a href=\"{{@BaseURL}}archive/{$aid}\">{$asubject}</a></td><td>{$adatecreated}</td><td>{$aviewed}</td></tr>";
      } else {
        $html .= "<tr class=\"bg-light\"><td><a href=\"{{@BaseURL}}archive/{$aid}\">{$asubject}</a></td><td>{$adatecreated}</td></tr>";
      }
      */

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($amsg,"");
      $html .= $ff->FF_Td($adatecreated,"");
      $html .= $ff->FF_Td($aviewed,"");
      $html .= $ff->FF_TrClose();

    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = 'archives';
    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action);
    return $html;
  }

  // Create a new entry in archives. This is called once per message, the first time it is sent to the list
  // An archive is not editable, so changes made after it being sent are not reflected in the archive
  // $msubject,$mhtml
  public function CreateArchive() {
    if ($this->fat->get('archive') == 0) return 0;
    $this->archive->reset();
    $this->archive->a_subject = $this->message->message->m_subject;
    $this->archive->a_datecreated = date("Y-m-d H:i:s");
    $this->archive->save();

    // get the a_id (the insert_id property contains the last auto generated id)
    $aid = $this->archive->_id;
    $this->message->message->m_a_id = $aid;
    // $msg->message->save(); gets saved when this returns

    $this->template->MergeTemplate(true); // $nosubscriber = true
    $this->archive->a_html = $this->template->shtml;
    $this->archive->save();
    return $aid;
  }

  public function ShowArchive($aid,$suid = '',$muid = '') {
    $html = "";
    if ($this->fat->get('uloggedin')) {
      if ($suid == '') $suid = $this->fat->get('SESSION.suid');
      $html .= "<form name=\"forwardarchiveform\" action=\"{{@BaseURL}}forward-archive\" method=\"post\" role=\"form\">";
      $html .= "<input name=\"aid\" type=\"hidden\" value=\"{$aid}\">";
      $html .= "<input name=\"suid\" type=\"hidden\" value=\"{$suid}\">";
      $html .= "<input name=\"muid\" type=\"hidden\" value=\"{$muid}\">";
      $html .= "<fieldset class=\"form-group\"><legend>Forward Archive Message</legend>";

      $textarea_text = array(
        0 => array("bemail","Forward to these emails"),
      );

      foreach($textarea_text as $icontrol) {
        $html .= "<div class=\"form-row\">";
        $html .= "<div class=\"form-group col-md-6\">";
        $html .= "<label class=\"col-form-label p-2 text-white bg-info\" for=\"{$icontrol[0]}\">{$icontrol[1]}</label>";
        $html .= "<textarea class=\"form-control\" rows=\"5\" cols=\"90\" name=\"{$icontrol[0]}\"></textarea>";
        $html .= "</div></div>";
      }

      $html .= "<div class=\"form-row\">";
      $html .= "<div class=\"form-group col-md-6\">";
      $html .= "<button class=\"btn btn-info\" type=\"submit\">Forward Message</button>";
      $html .= "</div></div>";
      $html .= "</fieldset></form>";
    }

    $this->archive->load(array('a_id = :aid', ':aid' => $aid));

    if (!$this->archive->dry()) {
      $html .= "<div class=\row\"><p class=\"{{@pclass}\">Date created: {$this->archive->a_datecreated}</p></div>";
      $asubject = stripslashes($this->archive->a_subject);
      $this->fat->set('title',"Archive - {$asubject}");
      $html .= "<div class=\row\"><p class=\"{{@pclass}\">Subject: {$asubject}</p></div>";
      $html .= $this->archive->a_html;

      // increment a_viewed
      $aviewed = $this->archive->a_viewed + 1;
      $this->archive->a_viewed = $aviewed;
      $this->archive->save();
    } else {
      $html .= "<p class=\"{{@pclass}\">Archive does not exist.</p>";
    }
    return $html;
  }
}
