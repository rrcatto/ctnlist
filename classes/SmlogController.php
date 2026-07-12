<?php
/*

Module: SmlogController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-09

*/

class SmlogController extends Controller {
  public $dbPDO;
  protected $BaseURL;
  public $smlog;
  public $fat;

  function __construct(Base $fat) {
    $this->fat = $fat;
    $this->dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');
    $this->smlog = new SmlogM($fat);
  }

  public function setSubscriber(SubscribersController $subscriber) {
    $this->smlog->setSubscriber($subscriber);
  }

  public function setMessage(MessagesController $message) {
    $this->smlog->SetMessage($message);
  }

  // shows a paginated list of every subscriber who viewed a particular message
  public function CreateMessageReadsHTMLList($muid = '',$ssemail = '',$pageno = 1,$numrows= 25) {
    $html = "";
    if ($muid == '') {
      $html .= "<p>No such message.</p>";
      return $html;
    }
    if ($this->fat->get('uadmin') == '1') {

      $ff = new formfield;

      $html .= $ff->FF_FormOpen("messageviewsform","{{@BaseURL}}message-views/{$muid}","GET");
      $html .= $ff->FF_DivOpen("{{@rowclass}}");

      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= $ff->FF_input("e","search",$ssemail,"","{{@inputclass}}");
      $html .= $ff->FF_Label("Email address","e","{{@labelclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivOpen("{{@columnclass6}}");
      $html .= $ff->FF_Button("submit","Search Message Reads","{{@buttonclass}}");
      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_DivClose();

      $html .= $ff->FF_FormClose();
    } else {
      $html .= "<p>Access denied</p>";
      return $html;
    }
    if ($ssemail == '') {
      $filter = array("`sml_muid` = :muid and `sml_reads` > '0'", ':muid' => $muid);
    } else {
      $filter = array("`sml_muid` = :muid and `sml_reads` > '0' and `sml_email` like :email", ':muid' => $muid, ':email' => "%{$ssemail}%");
    }
    $totalmatches = $this->smlog->count($filter);
    if ($totalmatches == 0) {
      if ($ssemail == '') {
        $html .= "<p>No-one has read this message yet.</p>";
      } else {
        $html .= "<p>No email like that has read this message yet.</p>";
      }
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

    $html .= $ff->FF_Th("Email","{{@thclass}}");
    $html .= $ff->FF_Th("Listname","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-envelope-open-o\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("Read","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-heart-o\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("Last <i class=\"fa fa-heart-o\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("<i class=\"fa fa-thumbs-o-down\" aria-hidden=\"true\"></i>","{{@thclass}}");
    $html .= $ff->FF_Th("Last <i class=\"fa fa-thumbs-o-down\" aria-hidden=\"true\"></i>","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    if ($ssemail == '') {
      $filter = array("`sml_muid` = :muid and `sml_reads` > '0'", ':muid' => $muid);
    } else {
      $filter = array("`sml_muid` = :muid and `sml_reads` > '0' and `sml_email` like :email", ':muid' => $muid, ':email' => "%{$ssemail}%");
    }
    $page = $this->smlog->paginate($pageno - 1,$numrows,$filter,array('order' => 'sml_last_read DESC'));
    foreach ($page['subset'] as $row) {
      $semail = $row['sml_email'];
      $listname = $row['sml_listname'];
      $reads = $row['sml_reads'];
      $lastread = $row['sml_last_read'];
      $likes = $row['sml_likes'];
      $lastlike = $row['sml_last_like'];
      $dislikes = $row['sml_dislikes'];
      $lastdislike = $row['sml_last_dislike'];

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($semail,"");
      $html .= $ff->FF_Td($listname,"");
      $html .= $ff->FF_Td($reads,"");
      $html .= $ff->FF_Td($lastread,"");
      $html .= $ff->FF_Td($likes,"");
      $html .= $ff->FF_Td($lastlike,"");
      $html .= $ff->FF_Td($dislikes,"");
      $html .= $ff->FF_Td($lastdislike,"");
      $html .= $ff->FF_TrClose();
    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = "message-views/{$muid}";
    $qsemail = urlencode($ssemail);
    $querystring = '';
    if ($ssemail == '') {
      $querystring = '';
    } else {
      $querystring = "?e={$qsemail}";
    }

    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action,$querystring);
    return $html;
  }

  public function readLog($suid,$muid) {
    return $this->smlog->read($suid,$muid);
  }

  // Model takes care of creating record
  public function logMsgQueued($suid,$muid) {
    $this->readLog($suid,$muid);
  }

  public function delMsg($muid) {
    $this->smlog->deletemsg($muid);
  }

  public function logMsgSent($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $this->smlog->sml_date_sent = date("Y-m-d H:i:s");
      $this->smlog->save();
    }
  }

  public function logRead($d) {
    $reads = $this->smlog->sml_reads + 1;
    $this->smlog->sml_reads = (int) $reads;
    $this->smlog->sml_last_read = $d;
  }

  //
  public function logMsgRead($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  public function logMsgLike($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $likes = $this->smlog->sml_likes + 1;
      $this->smlog->sml_likes = (int) $likes;
      $this->smlog->sml_last_like = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  public function logMsgDislike($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $dislikes = $this->smlog->sml_dislikes + 1;
      $this->smlog->sml_dislikes = (int) $dislikes;
      $this->smlog->sml_last_dislike = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgUpdate($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $updates = $this->smlog->sml_updates + 1;
      $this->smlog->sml_updates = (int) $updates;
      $this->smlog->sml_last_update = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgConfirm($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $confirms = $this->smlog->sml_confirms + 1;
      $this->smlog->sml_confirms = (int) $confirms;
      $this->smlog->sml_confirmed_at = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgForward($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $forwards = $this->smlog->sml_forwards + 1;
      $this->smlog->sml_forwards = (int) $forwards;
      $this->smlog->sml_last_forwarded = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgBooking($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $bookings = $this->smlog->sml_bookings + 1;
      $this->smlog->sml_bookings = (int) $bookings;
      $this->smlog->sml_last_booking = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgSub($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $this->smlog->sml_subscribe = (int) 1;
      $this->smlog->sml_subscribed_at = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  //
  public function logMsgUnsub($suid,$muid) {
    if ($this->readLog($suid,$muid)) {
      $d = date("Y-m-d H:i:s");
      $this->smlog->sml_unsubscribe = (int) 1;
      $this->smlog->sml_unsubscribed_at = $d;
      $this->logRead($d);
      $this->smlog->save();
    }
  }

  // stats functions
  // ucount = unique count, total = total number
  public function numberOfReads($muid) {
    $sql = "select count(*) as ucount, sum(`sml_reads`) as total from `smlog` where `sml_muid` = :muid and `sml_reads` > '0'";
    $opt = array(':muid' => $muid);
    $rows = $this->dbPDO->exec($sql,$opt);
    $row = $rows[0];
    $counts = array('ucount' => $row['ucount'], 'total' => $row['total']);
    return $counts;
  }

  public function numberOfUpdates($muid) {
    $sql = "select count(*) as ucount, sum(`sml_updates`) as total from `smlog` where `sml_muid` = :muid and `sml_updates` > '0'";
    $opt = array(':muid' => $muid);
    $rows = $this->dbPDO->exec($sql,$opt);
    $row = $rows[0];
    $counts = array('ucount' => $row['ucount'], 'total' => $row['total']);
    return $counts;
  }

  public function numberOfConfirms($muid) {
    $sql = "select count(*) as ucount, sum(`sml_confirms`) as total from `smlog` where `sml_muid` = :muid and `sml_confirms` > '0'";
    $opt = array(':muid' => $muid);
    $rows = $this->dbPDO->exec($sql,$opt);
    $row = $rows[0];
    $counts = array('ucount' => $row['ucount'], 'total' => $row['total']);
    return $counts;
  }

  public function numberOfForwards($muid) {
    $sql = "select count(*) as ucount, sum(`sml_forwards`) as total from `smlog` where `sml_muid` = :muid and `sml_forwards` > '0'";
    $opt = array(':muid' => $muid);
    $rows = $this->dbPDO->exec($sql,$opt);
    $row = $rows[0];
    $counts = array('ucount' => $row['ucount'], 'total' => $row['total']);
    return $counts;
  }

  public function numberOfBookings($muid) {
    $sql = "select count(*) as ucount, sum(`sml_bookings`) as total from `smlog` where `sml_muid` = :muid and `sml_bookings` > '0'";
    $opt = array(':muid' => $muid);
    $rows = $this->dbPDO->exec($sql,$opt);
    $row = $rows[0];
    $counts = array('ucount' => $row['ucount'], 'total' => $row['total']);
    return $counts;
  }

  public function numberOfQueued($muid) {
    return $this->smlog->count(array("`sml_muid` = :muid", ':muid' => $muid));
  }

  public function numberOfSent($muid) {
    return $this->smlog->count(array("`sml_muid` = :muid and `sml_date_sent` is not null", ':muid' => $muid));
  }

  public function numberOfSubscribes($muid) {
    return $this->smlog->count(array("`sml_muid` = :muid and `sml_subscribe` = '1'", ':muid' => $muid));
  }

  public function numberOfUnsubscribes($muid) {
    return $this->smlog->count(array("`sml_muid` = :muid and `sml_unsubscribe` = '1'", ':muid' => $muid));
  }
}
