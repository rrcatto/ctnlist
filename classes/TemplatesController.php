<?php
/*

Module: TemplatesController class
Version: 4.3
Author: Richard Catto
Creation Date: 2017-07-05

*/

class TemplatesController extends Controller {
  protected $fat;
  protected $BaseURL;
  protected $ListName;
  protected $Domain;
  protected $Organisation;

  // holds template message parts with global placeholders merged in
  protected $htmlpart;
  protected $textpart;

  // holds template message parts with both global and subscriber specific placeholders merged in
  // i.e. holds the message parts to send
  public $shtml;
  public $stext;
  public $template;
  public $subscriber, $message;

  // create all necessary tables in constructor
  function __construct(Base $fat, SubscribersController $subscriber, MessagesController $message) {
    $this->fat = $fat;
    $dbPDO = $fat->get('dbPDO');
    $this->BaseURL = $fat->get('BaseURL');
    $this->ListName = $fat->get('ListName');
    $this->Domain = $fat->get('Domain');
    $this->Organisation = $fat->get('Organisation');

    $this->subscriber = $subscriber;
    $this->message = $message;

    $this->template = new TemplatesM($fat);
  }

  public function TemplateCount() {
    return $this->template->templatecount();
  }

  // To display a drop down select box in the edit messages form
  public function CreateTemplateHTMLDropDown($tid) {
    $html = "";
    $this->template->load(null,array('order' => 't_name'));
    if ($tid == "0") {
      $html .= "<option selected value=\"0\">none</option>";
    }
    else {
      $html .= "<option value=\"0\">none</option>";
    }
    while(!$this->template->dry()) {
      $selected = ($tid == $this->template->t_id) ? 'selected' : '';
      $html .= "<option {$selected} value=\"{$this->template->t_id}\">{$this->template->t_name}</option>";
      $this->template->skip();
    }
    return $html;
  }

  // To display a paginated list of templates defined in the system
  public function CreateTemplatesHTMLList($pageno = 1,$numrows = 25,$saved = false) {
    $html = "";
    if ((int) $this->fat->get('uadmin') < 1) {
      $html .= "<p class=\"{{@pclass}}\">Access denied.</p>";
      return $html;
    }
    if ($saved) {
      $html .= "<div class=\"alert col-md-2 text-center alert-info alert-dismissible fade show\" role=\"alert\"><button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button><strong>Template saved!</strong></div>";
    }
    $totalmatches = $this->template->count();
    if ($totalmatches == 0) {
      $html .= "<p class=\"{{@pclass}}\">There are no templates.</p>";
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

    $html .= $ff->FF_Th("Template <i class=\"fa fa-pencil\" aria-hidden=\"true\"></i>","{{@thclass}}");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $page = $this->template->paginate($pageno - 1,$numrows,null,array('order' => 't_name'));
    foreach ($page['subset'] as $row) {
      $tid = $row['t_id'];
      $tname = $row['t_name'];
      $tname = "<a href=\"{{@BaseURL}}template/{$tid}\">{$tname}</a>";

      $html .= $ff->FF_TrOpen("");
      $html .= $ff->FF_Td($tname,"");
      $html .= $ff->FF_TrClose();

    }
    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    $action = 'templates';
    $hh = new htmlhelper($this->fat);
    $html .= $hh->paginate($page,$action);
    return $html;
  }

  public function CreateTemplateHTMLform($tid = 0) {
    $html = "";
    if ($this->fat->get('uadmin') <> '1') {
      $html .= "<p class=\"{{@pclass}\">Access denied</p>";
      return $html;
    }
    if ($tid == 0) {
      $tname = "";
      $thtml = "";
      $ttext = "";
      $legend = 'Create Template';
    } elseif (!$this->RetrieveTemplate($tid)) {
      $html .= "<p class=\"{{@pclass}\">Template does not exist</p>";
      return $html;
    } else {
      $tname = $this->template->t_name;
      $thtml = $this->template->t_html;
      $ttext = $this->template->t_text;
      $legend = 'Edit Template';
    }

    $ff = new formfield;

    /*
    $ckjs = "<script type=\"text/javascript\">";
    $ckjs .="CKEDITOR.config.font_names = 'Arial/Arial, Helvetica, sans-serif;' + 'Calibri/Calibri, sans-serif;' + 'Comic Sans MS/Comic Sans MS, cursive;' + 'Courier New/Courier New, Courier, monospace;' + 'Georgia/Georgia, serif;' + 'Lucida Sans Unicode/Lucida Sans Unicode, Lucida Grande, sans-serif;' + 'Tahoma/Tahoma, Geneva, sans-serif;' + 'Times New Roman/Times New Roman, Times, serif;' + 'Trebuchet MS/Trebuchet MS, Helvetica, sans-serif;' + 'Verdana/Verdana, Geneva, sans-serif;';";
    $ckjs .= "CKEDITOR.replace('t_html',{ width : '1175px', height : '900px' });";
    $ckjs .= "</script>";

    $ckjs = "<script>";
    $ckjs .= "CKEDITOR.config.height = {{@textareaheight}};";
    $ckjs .= "CKEDITOR.config.width = {{@textareawidth}};";
    $ckjs .= "CKEDITOR.config.font_names = 'Arial/Arial, Helvetica, sans-serif;' + 'Calibri/Calibri, sans-serif;' + 'Comic Sans MS/Comic Sans MS, cursive;' + 'Courier New/Courier New, Courier, monospace;' + 'Georgia/Georgia, serif;' + 'Lucida Sans Unicode/Lucida Sans Unicode, Lucida Grande, sans-serif;' + 'Tahoma/Tahoma, Geneva, sans-serif;' + 'Times New Roman/Times New Roman, Times, serif;' + 'Trebuchet MS/Trebuchet MS, Helvetica, sans-serif;' + 'Verdana/Verdana, Geneva, sans-serif;';";
    $ckjs .= "CKEDITOR.replace( 't_html' );";
    $ckjs .= "</script>";
    */
    $ckjs = "";

    $html .= $ff->FF_FormOpen("templateform","{{@BaseURL}}template","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend($legend);

    $html .= $ff->FF_hidden("t_id",$tid);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("t_name","text",$tname," required","{{@inputclass}}");
    $html .= $ff->FF_Label("Template Name","t_name","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $tmp_content = htmlspecialchars($thtml ?? '',ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    // $tmp_content = $thtml;
    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("mt_html",$tmp_content,$ckjs,"{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
    $html .= $ff->FF_Label("HTML part","t_html","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_textarea("t_text",$ttext,"","{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
    $html .= $ff->FF_Label("Text part","t_text","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_Button("submit","Save Template","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();

    return $html;
  }

  public function RetrieveTemplate($tid) {
    return $this->template->read($tid);
  }

  // check $valid for existing template
  public function save() {
    $html = "";
    $tid = $this->fat->get('POST.t_id');
    if ($tid == 0) {
      $this->template->reset();
      $html .= "<p class=\"{{@pclass}\">Template created</p>";
    } else {
      $valid = $this->RetrieveTemplate($tid);
      $html .= "<p class=\"{{@pclass}\">Template saved</p>";
    }
    $this->template->t_name = $this->fat->get('POST.t_name');
    $this->template->t_html = $this->fat->get('POST.mt_html');
    $this->template->t_text = $this->fat->get('POST.t_text');
    $this->template->save();
    return $html;
  }

  // use to create a template from a file
  public function CreateTemplate($tname,$hfname,$tfname) {
    $fhcontents = addslashes(file_get_contents($hfname));
    $ftcontents = addslashes(file_get_contents($tfname));
    $this->template->reset();
    $this->template->t_name = $tname;
    $this->template->t_html = $fhcontents;
    $this->template->t_text = $ftcontents;
    $this->template->save();
  }

  // merge both merge methods - store in model
  public function MergeTemplate($nosubscriber = false) {
    if ($nosubscriber) {
      $suid = '';
      $fname = '';
      $lname = '';
      $emailsleft = (int) 0;
    } else {
      $suid = $this->subscriber->subscriber->s_uniqid;
      $fname = $this->subscriber->subscriber->s_fname;
      $lname = $this->subscriber->subscriber->s_lname;
      $emailsleft = $this->subscriber->subscriber->s_emailsleft;
    }

    $muid = $this->message->message->m_uniqid;
    $tid = $this->message->message->m_t_id;
    $mhtml = $this->message->message->m_html;
    $mtext = $this->message->message->m_text;
    $maid = $this->message->message->m_a_id;

    $valid = $this->RetrieveTemplate($tid);
    if (!$valid) { // no template exists, so just use the message parts
      $this->htmlpart = $mhtml;
      $this->textpart = $mtext;
    } else { // a template exists, so start with the template parts
      $this->htmlpart = str_ireplace("{content}",$mhtml,$this->template->t_html);
      $this->textpart = str_ireplace("{content}",$mtext,$this->template->t_text);
    }

    if ($this->fat->exists('AdvertiseURL')) {
      $AdvertiseURL = $this->fat->get('AdvertiseURL');
      $this->htmlpart = str_ireplace("{advertise}","<a href=\"{$AdvertiseURL}\">ADVERTISE</a>",$this->htmlpart);
      $this->textpart = str_ireplace("{advertise}",$AdvertiseURL,$this->textpart);
    }

    if ($this->fat->exists('FacebookPageURL')) {
      $FacebookPageURL = $this->fat->get('FacebookPageURL');
      $this->htmlpart = str_ireplace("{facebook}","<a href=\"{$FacebookPageURL}\">FACEBOOK</a>",$this->htmlpart);
      $this->textpart = str_ireplace("{facebook}",$FacebookPageURL,$this->textpart);
    }

    if ($this->fat->exists('TwitterURL')) {
      $TwitterURL = $this->fat->get('TwitterURL');
      $this->htmlpart = str_ireplace("{twitter}","<a href=\"{$TwitterURL}\">TWITTER</a>",$this->htmlpart);
      $this->textpart = str_ireplace("{twitter}",$TwitterURL,$this->textpart);
    }

    if ($this->fat->exists('StoreURL')) {
      $StoreURL = $this->fat->get('StoreURL');
      $this->htmlpart = str_ireplace("{STORE}","<a href=\"{$StoreURL}\">STORE</a>",$this->htmlpart);
      $this->textpart = str_ireplace("{STORE}",$StoreURL,$this->textpart);
    }

    $this->htmlpart = str_ireplace("{subscribe}","<a href=\"{$this->BaseURL}subscribe?m={$muid}\">SUBSCRIBE</a>",$this->htmlpart);
    $this->textpart = str_ireplace("{subscribe}","{$this->BaseURL}subscribe?m={$muid}",$this->textpart);

    $this->htmlpart = str_ireplace("{listname}",$this->ListName,$this->htmlpart);
    $this->textpart = str_ireplace("{listname}",$this->ListName,$this->textpart);

    $this->htmlpart = str_ireplace("{domain}",$this->Domain,$this->htmlpart);
    $this->textpart = str_ireplace("{domain}",$this->Domain,$this->textpart);

    $this->htmlpart = str_ireplace("{organisation}",$this->Organisation,$this->htmlpart);
    $this->textpart = str_ireplace("{organisation}",$this->Organisation,$this->textpart);

    if ($nosubscriber) {
      $this->shtml = str_ireplace("{archive}","<a href=\"{$this->BaseURL}archive/{$maid}\">ARCHIVE</a>",$this->htmlpart);
      $this->stext = str_ireplace("{archive}","{$this->BaseURL}archive/{$maid}",$this->textpart);

      $this->shtml = str_ireplace("{unsubscribe}","UNSUBSCRIBE",$this->shtml);
      $this->stext = str_ireplace("{unsubscribe}","UNSUBSCRIBE",$this->stext);

      $this->shtml = str_ireplace("{forward}","FORWARD",$this->shtml);
      $this->stext = str_ireplace("{forward}","FORWARD",$this->stext);

      $this->shtml = str_ireplace("{preferences}","UPDATE",$this->shtml);
      $this->stext = str_ireplace("{preferences}","UPDATE",$this->stext);

      $this->htmlpart = str_ireplace("{booking}","BOOKING FORM",$this->shtml);
      $this->textpart = str_ireplace("{booking}","BOOKING FORM",$this->stext);

      $this->htmlpart = str_ireplace("{contact}","CONTACT FORM",$this->shtml);
      $this->textpart = str_ireplace("{contact}","CONTACT FORM",$this->stext);

      $this->htmlpart = str_ireplace("{lms-booking}","LMS COURSES",$this->shtml);
      $this->textpart = str_ireplace("{lms-booking}","LMS COURSES",$this->stext);

      $this->shtml = str_ireplace("{firstname}","",$this->shtml);
      $this->stext = str_ireplace("{firstname}","",$this->stext);

      $this->shtml = str_ireplace("{lastname}","",$this->shtml);
      $this->stext = str_ireplace("{lastname}","",$this->stext);

      $this->shtml = str_ireplace("{subscription}","",$this->shtml);
      $this->stext = str_ireplace("{subscription}","",$this->stext);

      $this->shtml = str_ireplace("{emailsleft}","",$this->shtml);
      $this->stext = str_ireplace("{emailsleft}","",$this->stext);

      $this->shtml = str_ireplace("{confirm}","OPT IN",$this->shtml);
      $this->stext = str_ireplace("{confirm}","OPT IN",$this->stext);

      $this->shtml = str_ireplace("{like}","YES",$this->shtml);
      $this->stext = str_ireplace("{like}","YES",$this->stext);

      $this->shtml = str_ireplace("{dislike}","NO",$this->shtml);
      $this->stext = str_ireplace("{dislike}","NO",$this->stext);

      $this->shtml = str_ireplace("{usertrack}","",$this->shtml);

      $this->shtml = str_ireplace("{muid}",'',$this->shtml);
      $this->stext = str_ireplace("{muid}",'',$this->stext);

      $this->shtml = str_ireplace("{suid}",'',$this->shtml);
      $this->stext = str_ireplace("{suid}",'',$this->stext);

    } else {
      if ($emailsleft <= $this->fat->get('SubscriptionConfirmLevel')) {
        $submsg = $this->fat->get('SubscriptionConfirmMessage');
      } else {
        $submsg = $this->fat->get('SubscriptionMessage');
      }

      $this->shtml = str_ireplace("{subscription}",$submsg,$this->htmlpart);
      $this->stext = str_ireplace("{subscription}",$submsg,$this->textpart);

      $this->shtml = str_ireplace("{archive}","<a href=\"{$this->BaseURL}archive/{$maid}/{$suid}/{$muid}\">ARCHIVE</a>",$this->shtml);
      $this->stext = str_ireplace("{archive}","{$this->BaseURL}archive/{$maid}/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{unsubscribe}","<a href=\"{$this->BaseURL}unsubscribe/{$suid}/{$muid}\">UNSUBSCRIBE</a>",$this->shtml);
      $this->stext = str_ireplace("{unsubscribe}","{$this->BaseURL}unsubscribe/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{forward}","<a href=\"{$this->BaseURL}forward/{$suid}/{$muid}\">FORWARD</a>",$this->shtml);
      $this->stext = str_ireplace("{forward}","{$this->BaseURL}forward/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{preferences}","<a href=\"{$this->BaseURL}subscribe/{$suid}/{$muid}\">UPDATE</a>",$this->shtml);
      $this->stext = str_ireplace("{preferences}","{$this->BaseURL}subscribe/{$suid}/{$muid}",$this->stext);

      if ($this->fat->exists('BookingURL')) {
        $BookingURL = $this->fat->get('BookingURL');
        // replace variables in the BookingURL
        $burl = str_ireplace("{BaseURL}",$this->BaseURL,$BookingURL);
        $burl = str_ireplace("{suid}",$suid,$burl);
        $burl = str_ireplace("{muid}",$muid,$burl);

        $this->shtml = str_ireplace("{booking}","<a href=\"{$burl}\">BOOKING FORM</a>",$this->shtml);
        $this->stext = str_ireplace("{booking}","{$burl}",$this->stext);
      }

      if ($this->fat->exists('ContactURL')) {
        $ContactURL = $this->fat->get('ContactURL');
        // replace variables in the ContactURL
        $curl = str_ireplace("{BaseURL}",$this->BaseURL,$ContactURL);
        $curl = str_ireplace("{suid}",$suid,$curl);
        $curl = str_ireplace("{muid}",$muid,$curl);

        $this->shtml = str_ireplace("{contact}","<a href=\"{$curl}\">CONTACT FORM</a>",$this->shtml);
        $this->stext = str_ireplace("{contact}","{$curl}",$this->stext);
      }

      $this->shtml = str_ireplace("{firstname}",$fname,$this->shtml);
      $this->stext = str_ireplace("{firstname}",$fname,$this->stext);

      $this->shtml = str_ireplace("{lastname}",$lname,$this->shtml);
      $this->stext = str_ireplace("{lastname}",$lname,$this->stext);

      $this->shtml = str_ireplace("{emailsleft}",$emailsleft,$this->shtml);
      $this->stext = str_ireplace("{emailsleft}",$emailsleft,$this->stext);

      $this->shtml = str_ireplace("{confirm}","<a href=\"{$this->BaseURL}confirm/{$suid}/{$muid}\">YES</a>",$this->shtml);
      $this->stext = str_ireplace("{confirm}","{$this->BaseURL}confirm/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{like}","<a href=\"{$this->BaseURL}like/{$suid}/{$muid}\">YES</a>",$this->shtml);
      $this->stext = str_ireplace("{like}","{$this->BaseURL}like/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{dislike}","<a href=\"{$this->BaseURL}dislike/{$suid}/{$muid}\">NO</a>",$this->shtml);
      $this->stext = str_ireplace("{dislike}","{$this->BaseURL}dislike/{$suid}/{$muid}",$this->stext);

      $this->shtml = str_ireplace("{usertrack}","<img src=\"{$this->BaseURL}ut/{$suid}/{$muid}\" width=\"0\" height=\"0\">",$this->shtml);

      $this->shtml = str_ireplace("{baseurl}",$this->BaseURL,$this->shtml);
      $this->stext = str_ireplace("{baseurl}",$this->BaseURL,$this->stext);

      $this->shtml = str_ireplace("{muid}",$muid,$this->shtml);
      $this->stext = str_ireplace("{muid}",$muid,$this->stext);

      $this->shtml = str_ireplace("{suid}",$suid,$this->shtml);
      $this->stext = str_ireplace("{suid}",$suid,$this->stext);
    }
  }
}
