<?php

namespace App\Legacy;

use Base;

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

}