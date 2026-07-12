
<?php
/*

Module: QueueController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-16

Description:
This class processes the send queue and provides queue handling methods
start must be instantiated before this class is instantiated in order to open a connection t the mysql database

*/

class QueueController extends Controller {
  protected $fat;
  protected $dbPDO;
  protected $BaseURL;

  public $queue;
  public $subscriber, $message, $template, $mailer, $options;

  function __construct(Base $fat, SubscribersController $subscriber, MessagesController $message, TemplatesController $template, mailer $mailer, OptionsController $options) {
    $this->fat = $fat;
    $this->dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');

    $this->subscriber = $subscriber;
    $this->message = $message;
    $this->template = $template;
    $this->mailer = $mailer;
    $this->options = $options;

    $this->queue = new QueueM($fat);
  }

  public function DropTable() {
    return $this->queue->droptable();
  }

  public function QueueCount() {
    return $this->queue->queuecount();
  }

  // display paginated list of queued messages
  public function CreateQueueHTMLList($pageno,$numrows) {
    $html = "";
    if ((int) $this->fat->get('uadmin') < 1) {
      $html .= "<p class=\"{{@pclass}}\">Please login in order to view this page.</p>";
      return $html;
    }
    $totalmatches = $this->queue->count();
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\">There is nothing in the queue.</p>";
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

    $html .= $ff->FF_Th("Message","{{@thclass}}");
    $html .= $ff->FF_Th("Subscriber","{{@thclass}}");
    $html .= $ff->FF_Th("List","{{@thclass}}");
    $html .= $ff->FF_Th("Added","{{@thclass}}");
    $html .= $ff->FF_Th("Last","{{@thclass}}");
    $html .= $ff->FF_Th("MP:SP","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $page = $this->queue->paginate($pageno - 1,$numrows,null,array('order' => 'q_mpriority DESC, q_last_interacted DESC, q_spriority DESC, q_id ASC'));
    foreach ($page['subset'] as $row) {
      $qmuid = $row['q_muid'];
      $qsubject = stripslashes($row['q_subject']);
      $qsub = substr($qsubject,0,80) . '...';
      $qmsg = "<a href=\"{{@BaseURL}}message/{$qmuid}\" data-toggle=\"tooltip\" title=\"{$qsubject}\">{$qsub}</a>";
      $qsuid = $row['q_suid'];
      $qemail = $row['q_email'];
      $qsub = "<a href=\"{{@BaseURL}}unsubscribe/{$qsuid}\">{$qemail}</a>";
      $qlistname = $row['q_listname'];
      $qdateadded = $row['q_date_added'];
      $qlast = $row['q_last_interacted'];
      $qlast = ($qlast == "") ? "-" : $qlast;
      $qmpriority = $row['q_mpriority'];
      $qspriority = $row['q_spriority'];
      $qmsp = "{$qmpriority}:{$qspriority}";

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($qmsg,"");
      $html .= $ff->FF_Td($qsub,"");
      $html .= $ff->FF_Td($qlistname,"");
      $html .= $ff->FF_Td($qdateadded,"");
      $html .= $ff->FF_Td($qlast,"");
      $html .= $ff->FF_Td($qmsp,"");
      $html .= $ff->FF_TrClose();
    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = 'queue';
    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action);
    return $html;
  }

  // called from messages class to queue emails
  public function AddToQueue($muid,$suid,$subject,$email,$listname,$lastinteracted,$mpriority,$spriority) {
    $this->queue->reset();
    $this->queue->q_muid = $muid;
    $this->queue->q_suid = $suid;
    $this->queue->q_subject = $subject;
    $this->queue->q_email = $email;
    $this->queue->q_listname = $listname;
    $this->queue->q_last_interacted = $lastinteracted;
    $this->queue->q_mpriority = (int) $mpriority;
    $this->queue->q_spriority = (int) $spriority;
    $this->queue->save();
    return true;
  }

  // returns the number of emails sent (not the number of queue items processed)
  // MAJOR UPDATE: 2016-07-18 read new config.ini vars, new option table vars, check for quit condition
  // MAJOR UPDATE: 2017-07-07 convert to mapper, use last_interacted for priority
  public function ProcessQueue($muid = '', $totaltosend = 250000) {
    set_time_limit(86400);
    if ($muid == '') {
      $qfilter = null;
    } else {
      $qfilter = array('q_muid = :muid',':muid' => $muid);
    }
    $numsent = 0;
    $msent = 0;
    $mmaxsend = 0;

    $val = $this->options->GetOption("CurrentlySending");
    if ($val == 'Y') return $numsent; // if the queue is already being processed
    $this->options->SetOption("SendQueue","Y");  // if this changes to N later, it is a stop sending signal
    $this->options->SetOption("CurrentlySending","Y"); // sginals that processing of queue is occurring

    // open a connection to an smtp server
    $smtp_servers = $this->fat->get('smtp_servers');

    if (!is_array($smtp_servers) || $smtp_servers === []) {
      $this->options->SetOption("CurrentlySending","N");
      return $numsent;
    }

    $num_smtp_servers = count($smtp_servers);
    $i = 0;
    $success = false;
    $q_batch_size = 600;
    while ($i < $num_smtp_servers) {
      $q_batch_size = max(1, (int) ($smtp_servers[$i]['batchsize'] ?? 600)); // number of queue rows to process in a batch
      $success = $this->mailer->OpenSMTP($smtp_servers[$i]);
      if ($success) break;
      $i++;
    }
    if (!$success) {
      // echo "FAIL: to open SMTP";
      $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
      return $numsent; // could not open an SMTP connection
    }

    // main loop: iteratively sends batches of $q_batch_size emails until queue is empty or totaltosend is reached
    do {
      // set_time_limit(86400);
      $this->queue->load($qfilter,array('order' => 'q_mpriority DESC, q_last_interacted DESC, q_spriority DESC, q_id ASC','limit' => (int) $q_batch_size));
      if ($this->queue->dry()) { // empty queue condition
        $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
        return $numsent; // queue is empty
      }
      // while (!$this->queue->dry()) {
      while ($this->queue->valid()) {
        $val = $this->options->GetOption("SendQueue"); // check to see if the queue processing should stop
        if ($val == 'N') {
          $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
          return $numsent;
        }
        $qmuid = $this->queue->q_muid;
        $qsuid = $this->queue->q_suid;
        // set_time_limit(86400);
        // Retrieve the subscriber to whom we wish to send this message
        $this->subscriber->RetrieveSubscriber($qsuid);
        $subexist = !$this->subscriber->subscriber->dry();
        $unsub = $subexist && ((int) $this->subscriber->subscriber->s_unsubscribe === 1);
        if (!$subexist || ($unsub)) {
          $this->queue->erase();
          $this->queue->skip();
          continue;
        }

        // Retrieve the message we wish to send
        $msg_exist = $this->message->RetrieveMessage($qmuid);
        if (!$msg_exist) {
          $this->queue->erase();
          $this->queue->skip();
          continue;
        } else {
          $msent = $this->message->message->m_sent;
          $mmaxsend = $this->message->message->m_max_send;
          if ($msent >= $mmaxsend) {
            echo "<p class=\"{{@pclass}\"># sent {$msent} is >= max to send {$mmaxsend}</p>";
            break;
          }
        }

        $this->template->MergeTemplate();
        $emails_sent = $this->mailer->SendMessage();

        // If email failed to send, then we close and open the smtp connection and try again
        if ($emails_sent == 0) {
          // echo "<p class=\"{{@pclass}\">email sent: $emails_sent</p>";
          $this->mailer->CloseSMTP();
          $delay = $smtp_servers[$i]['delay'] ?? 10;
          sleep($delay);
          // open a connection to an smtp server
          $i = 0;
          while ($i < $num_smtp_servers) {
            $q_batch_size = max(1, (int) ($smtp_servers[$i]['batchsize'] ?? 600)); // number of queue rows to process in a batch
            $success = $this->mailer->OpenSMTP($smtp_servers[$i]);
            if ($success) break;
            $i++;
          }
          if (!$success) {
            // echo "FAIL: to open SMTP";
            $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
            return $numsent; // could not open an SMTP connection
          }
          $emails_sent = $this->mailer->SendMessage();
        }

        if ($emails_sent >= 1) {
          $sent = (int) $this->message->message->m_sent + 1;
          $this->message->message->m_sent = (int) $sent;
          $this->message->message->save();
          $this->queue->erase();
          $numsent++;
        } elseif ($emails_sent == -1) { // TO email is invalid - should not happen because of prior checks
          $this->queue->erase();
        } elseif ($emails_sent == 0) {
          // echo "FAIL: email not sent for unknown reason";
          $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
          return $numsent;
        }

        // extend the amount of time this script may run for
        // set_time_limit(0);
        $this->queue->skip(); // next record
      }
      $delay = $smtp_servers[$i]['delay'] ?? 10;
      sleep($delay);
    } while (($numsent < $totaltosend) && ($msent < $mmaxsend)); // (true);

    // echo "INFO: queue empty";
    $this->options->SetOption("CurrentlySending","N");  // signals that this process has stopped
    return $numsent;
  }
}
