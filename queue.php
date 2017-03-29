<?php
/*

Module: queue class
Author: Richard Catto
Creation Date: 2011-06-12

Description:
This class processes the send queue and provides queue handling methods
start must be instantiated before this class is instantiated in order to open a connection t the mysql database

*/

class queue
{
	protected $fat;
	protected $user;
	protected $dbconn;
	protected $BaseURL;
	
	public $qdateadded;
	public $qdatelasttried;
	public $qhold;
	public $qstatus;
	
	public $queuerows; // number of queue rows process in ProcessQueue()
	public $qerrormsg;
	public $qerrorcode;

	// $dbconn is a mysqli connection object to an open MySQL database
	function __construct($fat) {
		$this->fat = $fat;
		$this->user = $fat->get('user');
		$this->dbconn = $fat->get('dbconn');
		$this->BaseURL = $fat->get('BaseURL');
	}

	function __destruct() {
	}

	// display paginated list of queued messages
	public function CreateQueueHTMLList($pageno,$numrows) {
		$html = "";
		if ($this->user->uadmin == '1') {
			// $html .= "<p><a href=\"{$this->BaseURL}resetrows\">Reset all the queues row statuses</a></p>";
			// $html .= "<p><a href=\"{$this->BaseURL}processqueue\">Process Queue</a></p>";
		} else {
			$html .= "<p>Please login in order to view this page.</p>";
			return $html;
		}

		$sql = "select count(*) from queue";
		$result = $this->dbconn->query($sql);
		$row = $result->fetch_row();
		$totalmatches = $row[0];
		$result->close();
		if ($totalmatches == 0) {
			$html .= "<p>There is nothing in the queue.</p>";
			return $html;
		}

		$lastpage = ceil($totalmatches/$numrows);
		
		$pageno = (int)$pageno;
		if ($pageno > $lastpage) {
			$pageno = $lastpage;
		} elseif ($pageno < 1) {
			$pageno = 1;
		}
		
		$limit = "limit " . ($pageno - 1) * $numrows . ",$numrows";
		
		$sql = "select q_id, q_muid, q_suid, q_dateadded, q_datelasttried, q_hold, q_status, q_priority, m_subject, s_email from queue left join messages on (q_muid = m_uniqid) left join subscribers on (q_suid = s_uniqid) order by q_priority DESC, q_id ASC $limit";
		$result = $this->dbconn->query($sql);

		// $html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Message</th><th>Subscriber</th><th>Date Added</th><th>Date Last Tried</th><th>Hold?</th><th>Status</th><th>Priority</th><th>Delete?</th></tr></thead><tbody>";
      
		$html .= "<table class=\"table table-striped table-bordered\"><thead><tr><th>Message</th><th>Subscriber</th><th>Date Added</th><th>Priority</th></tr></thead><tbody>";

		while ($row = $result->fetch_array()) {
			$qid = $row["q_id"];
			$qmuid = $row["q_muid"];
			$qsuid = $row["q_suid"];
			$qdateadded = $row["q_dateadded"];
			// $qdatelasttried = (is_null($row["q_datelasttried"]) ? "&nbsp;" : $row["q_datelasttried"]);
			// $qhold = ($row["q_hold"] == "1" ? "<a href=\"{$this->BaseURL}unholdrow/{$qid}\">unhold</a>" : "<a href=\"{$this->BaseURL}holdrow/{$qid}\">hold</a>");
			// $qstatus = $row["q_status"];
			$qpriority = $row["q_priority"];
			$msubject = stripslashes($row["m_subject"]);
			$semail = $row["s_email"];
			// $qdelete = "<a href=\"{$this->BaseURL}deletequeuerow/{$qid}\">delete</a>";

			// $html .= "<tr><td><a href=\"{$this->BaseURL}message/{$qmuid}\">$msubject</a></td><td><a href=\"{$this->BaseURL}unsubscribe/{$qsuid}\">$semail</a></td><td>$qdateadded</td><td>$qdatelasttried</td><td>$qhold</td><td>$qstatus</td><td>$qpriority</td><td>$qdelete</td></tr>";
          
			$html .= "<tr><td><a href=\"{$this->BaseURL}message/{$qmuid}\">$msubject</a></td><td><a href=\"{$this->BaseURL}unsubscribe/{$qsuid}\">$semail</a></td><td>$qdateadded</td><td>$qpriority</td></tr>";
		}
		
		$html .= "</tbody></table>";
		
		$result->close();

		$html .= "<p>Page $pageno of $lastpage - $totalmatches records found</p>";

		// add in navigation to browse large results lists.
		$html .= "<ul class=\"pager\">";
		if ($pageno == 1) {
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">First</li>";
		  $html .= "<li class=\"previous disabled\"><a href=\"#\">Previous</li>";
		} else {
		  $prevpage = $pageno - 1;
		  $html .= "<li class=\"previous\"><a href='{$this->BaseURL}queue/1'>First</a></li>";
		  $html .= "<li class=\"previous\"><a href='{$this->BaseURL}queue/$prevpage'>Previous</a></li>";
		}
		if ($pageno == $lastpage) {
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Last</li>";
		  $html .= "<li class=\"next disabled\"><a href=\"#\">Next</li>";
		} else {
		  $nextpage = $pageno + 1;
		  $html .= "<li class=\"next\"><a href='{$this->BaseURL}queue/$lastpage'>Last</a></li>";
		  $html .= "<li class=\"next\"><a href='{$this->BaseURL}queue/$nextpage'>Next</a></li>";
		}
		$html .= "</ul>";

		return $html;
	}

	// Queue methods

	// set the hold flag to 1 or 0
	// returns true if the update succeeded, false if not
	public function SetHoldFlag($qid,$value) {
		$sql ="update queue set q_hold = \"$value\" where q_id = \"$qid\"";
		return $this->dbconn->query($sql);
	}

	// called from messages class to queue emails
	// returns true if added
	public function AddToQueue($muid,$suid,$priority) {
		// $sql = "insert into queue (q_muid, q_suid, q_dateadded, q_priority) values (\"$muid\",\"$suid\",now(),\"$priority\")";
        $sql = "insert into queue (q_muid, q_suid, q_priority) values (\"$muid\",\"$suid\",\"$priority\")";
		return $this->dbconn->query($sql);
	}

	// delete the row from the queue
	public function DeleteRow($qid) {
		$sql = "delete from queue where q_id = \"$qid\"";
		return $this->dbconn->query($sql);
	}
	
	// mark this row as failed to sen
	public function FailRow($qid) {
		// $sql = "update queue set q_datelasttried = now(), q_status = \"f\" where q_id = \"$qid\"";
        $sql = "update queue set q_status = \"f\" where q_id = \"$qid\"";
		return $this->dbconn->query($sql);
	}

	public function ResetRowStatus($qid) {
		$sql = "update queue set q_status = \"-\" where q_id = \"$qid\"";
		return $this->dbconn->query($sql);
	}

	public function ResetAllRowsStatus() {
		$sql = "update queue set q_status = \"-\"";
		return $this->dbconn->query($sql);
	}
	
	// returns the number of emails sent (not the number of queue items processed)
    // MAJOR UPDATE: 2016-07-18 read new config.ini vars, new option table vars, check for quit condition
	public function ProcessQueue($totaltosend = 100000) {
		set_time_limit(0);
		$numsent = 0;

        $options = $this->fat->get('options');
        
        $val = $options->GetOption("CurrentlySending");  // derive this from sendlog?
        if ($val == 'Y') return $numsent; // if the queue is already being processed
        
        $options->SetOption("SendQueue","Y");  // if this changes to N later, it is a stop sending signal
        $options->SetOption("CurrentlySending","Y"); // sginals that processing of queue is occurring
        
		$mailer = $this->fat->get('mailer');
		$template = $this->fat->get('templates');
		$message = $this->fat->get('messages');
		$subscriber = $this->fat->get('subscribers');

        // open a connection to an smtp server 
		$smtp_servers = $this->fat->get('smtp_servers');
        
		$num_smtp_servers = count($smtp_servers);
		$i = 0;
		while ($i < $num_smtp_servers) {
            $q_batch_size = $smtp_servers[$i]['batchsize'] ?? 600; // number of queue rows to process in a batch
			$success = $mailer->OpenSMTP($smtp_servers[$i++]);
			if ($success) break;
		}
		if (!$success) {
			// echo "FAIL: to open SMTP";
            $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
			return $numsent; // could not open an SMTP connection
		}
		// main loop: iteratively sends batches of $q_batch_size emails, until nothing is sent indicating that the queue is empty
		do {
			set_time_limit(0);
			// sql to find out how many unique messages are waiting to be delivered - not currently used
			// $sql = "select distinct q_muid from queue where (q_hold = \"0\") and (q_status = \"-\")";

			// send a batch of $q_batch_size emails from the queue
			$sql = "select q_id, q_muid, q_suid from queue where (q_hold = \"0\") and (q_status = \"-\") order by q_priority DESC, q_id ASC limit $q_batch_size";
			$result = $this->dbconn->query($sql);
			if ($this->dbconn->affected_rows == 0) {
				// echo "INFO: queue is empty";
                $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
				return $numsent; // queue is empty
			}
			while ($row = $result->fetch_array()) {
                $val = $options->GetOption("SendQueue"); // check to see if the queue processing should stop
                if ($val == 'N') {
                    $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
				    return $numsent; // queue is empty
                }
                
				$qid = $row['q_id'];
				$qmuid = $row['q_muid'];
				$qsuid = $row['q_suid'];
				set_time_limit(0);
				// Retrieve the subscriber to whom we wish to send this message
				$subexist = $subscriber->Read($qsuid);
				if (!$subexist) {
					$this->DeleteRow($qid);
					continue; // skip to next email to send
				} elseif ($subscriber->sunsubscribe == "1") {
					$this->DeleteRow($qid);
					continue;
				}

				// Retrieve the message we wish to send
				$msgexist = $message->RetrieveMessage($qmuid);
				if (!$msgexist) {
					$this->FailRow($qid); // this could happen if the admin deleted the message (using phpmyadmin etc.) but did not clear the queue of that message
					continue;
				}

				$template->RetrieveTemplate($message->mtid);
				$template->MergeTemplate($message->mhtml,$message->mtext,$message->maid);
				$template->MergeSubscriberDetail($qsuid,$message->mid,$subscriber->sfname,$subscriber->slname,$subscriber->semailsleft);

				$sname = trim($subscriber->sfname . ' ' . $subscriber->slname);

				$emails_sent = $mailer->SendMessage($qsuid,$message->mid,"M",$message->mfrom,$subscriber->semail,$sname,$message->msubject,$template->shtml,$template->stext,false);

				// If email failed to send, then we close and open the smtp connection and try again
				if ($emails_sent == 0) {
                    // echo "<p>email sent: $emails_sent</p>";
					$mailer->CloseSMTP();
					sleep(20); // wait 60 seconds
					// open a connection to an smtp server 
					$i = 0;
					while ($i < $num_smtp_servers) {
                        $q_batch_size = $smtp_servers[$i]['batchsize'] ?? 600; // number of queue rows to process in a batch
						$success = $mailer->OpenSMTP($smtp_servers[$i++]);
						if ($success) break;
					}
					if (!$success) {
						// echo "FAIL: to open SMTP";
                        $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
						return $numsent; // could not open an SMTP connection
					}
					$emails_sent = $mailer->SendMessage($qsuid,$message->mid,"M",$message->mfrom,$subscriber->semail,$sname,$message->msubject,$template->shtml,$template->stext,false);
				}
				
				if ($emails_sent >= 1) {
					$this->DeleteRow($qid);
					$numsent++;
				} elseif ($emails_sent == -1) { // TO email is invalid - should not happen because of prior checks
					$this->FailRow($qid);
				} elseif ($emails_sent == 0) { 
					// echo "FAIL: email not sent for unknown reason";
                    $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
					return $numsent;
				}
						
				// extend the amount of time this script may run for
				set_time_limit(0);
			}
			$result->close();
            $delay = $smtp_servers[$i]['delay'] ?? 10;
            sleep($delay);
		} while ($numsent < $totaltosend); // (true);
			
		// echo "INFO: queue empty";
        $options->SetOption("CurrentlySending","N");  // signals that this process has stopped
		return $numsent;
	}
}