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

  /**
   * Create an archive for an arbitrary message model during advanced queueing.
   * The original controller mapper is restored immediately after the legacy
   * archive/template workflow has run.
   */
  public function CreateArchiveForMessage(MessagesM $model): int {
    if ($this->fat->get('archive') == 0) return 0;
    $original = $this->message->message;
    try {
      $this->message->message = $model;
      return (int) $this->CreateArchive();
    } finally {
      $this->message->message = $original;
    }
  }

  public function ShowArchive($aid,$suid = '',$muid = '') {
    $html = '';

    // Preserve the v5 archive-forward workflow. Authentication/ACL is checked
    // by the route and CSRF protects the state-changing submission.
    if ((bool) $this->fat->get('uloggedin')) {
      if ($suid === '') {
        $suid = (string) $this->fat->get('SESSION.suid');
      }
      $html .= '<form name="forwardarchiveform" action="{{@BaseURL}}forward-archive" method="post" role="form" class="card card-body mb-4">';
      $html .= Csrf::field($this->fat);
      $html .= '<input name="aid" type="hidden" value="' . (int) $aid . '">';
      $html .= '<input name="subscriber_token" type="hidden" value="' . htmlspecialchars((string) $suid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
      $html .= '<input name="muid" type="hidden" value="' . htmlspecialchars((string) $muid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
      $html .= '<h2 class="h5">Forward Archive Message</h2>';
      $html .= '<label class="form-label" for="bemail">Forward to these emails</label>';
      $html .= '<textarea class="form-control mb-3" rows="5" name="bemail" id="bemail"></textarea>';
      $html .= '<button class="btn btn-primary" type="submit">Forward Message</button></form>';
    }

    $this->archive->load(array('a_id = :aid', ':aid' => (int) $aid));
    if ($this->archive->dry()) {
      $this->fat->error(404);
    }

    $subject = stripslashes((string) $this->archive->a_subject);
    $this->fat->set('title', 'Archive - ' . $subject);
    $html .= '<p class="{{@pclass}}">Date created: '
      . htmlspecialchars((string) $this->archive->a_datecreated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
      . '</p>';
    $html .= '<p class="{{@pclass}}">Subject: '
      . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
      . '</p>';
    $html .= (string) $this->archive->a_html;

    $this->archive->a_viewed = (int) $this->archive->a_viewed + 1;
    $this->archive->save();
    return $html;
  }

}