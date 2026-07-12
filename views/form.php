<?php

$ff = new formfield;

// javascript variable
$ckjs = "<script>";
$ckjs .= "CKEDITOR.config.height = {{@textareaheight}};";
$ckjs .= "CKEDITOR.config.width = {{@textareawidth}};";
$ckjs .= "CKEDITOR.config.font_names = 'Arial/Arial, Helvetica, sans-serif;' + 'Calibri/Calibri, sans-serif;' + 'Comic Sans MS/Comic Sans MS, cursive;' + 'Courier New/Courier New, Courier, monospace;' + 'Georgia/Georgia, serif;' + 'Lucida Sans Unicode/Lucida Sans Unicode, Lucida Grande, sans-serif;' + 'Tahoma/Tahoma, Geneva, sans-serif;' + 'Times New Roman/Times New Roman, Times, serif;' + 'Trebuchet MS/Trebuchet MS, Helvetica, sans-serif;' + 'Verdana/Verdana, Geneva, sans-serif;';";
$ckjs .= "CKEDITOR.replace( 'm_html' );";
$ckjs .= "</script>";

$html .= $ff->FF_FormOpen("messageform","{{@BaseURL}}message","POST");
$html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
$html .= $ff->FF_Legend($legend);

$html .= $ff->FF_hidden("m_uniqid",$muid);

$html .= $ff->FF_DivOpen("{{@rowclass}}");

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_DropDown("m_t_id",$this->template->CreateTemplateHTMLDropDown($this->message->m_t_id),"{{@selectclass}}");
$html .= $ff->FF_Label("Message Template","m_t_id","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_input("m_from_name","text",$this->message->m_from_name," required","{{@inputclass}}");
$html .= $ff->FF_Label("From Name","m_from_name","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_input("m_from_address","text",$this->message->m_from_address," required","{{@inputclass}}");
$html .= $ff->FF_Label("From Email Address","m_from_address","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_DropDown("m_listname",$this->subscriber->CreateListHTMLDropDown($this->message->m_listname),"{{@selectclass}}");
$html .= $ff->FF_Label("Send to List","m_listname","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_input("m_priority","number",(int) $this->message->m_priority," required","{{@inputclass}}");
$html .= $ff->FF_Label("Message Priority","m_priority","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_input("m_max_send","number",(int) $this->message->m_max_send," required","{{@inputclass}}");
$html .= $ff->FF_Label("Maximum emails to send","m_max_send","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass12}}");
$html .= $ff->FF_input("m_subject","text",htmlspecialchars($this->message->m_subject)," required","{{@inputclass}}");
$html .= $ff->FF_Label("Message Subject","m_subject","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass12}}");
$html .= $ff->FF_textarea("m_html",$this->message->m_html,$ckjs,"{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
$html .= $ff->FF_Label("HTML part","m_html","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass12}}");
$html .= $ff->FF_textarea("m_text",$this->message->m_text,"","{{@textareaclass}}","{{@textareawidth}}","{{@textareaheight}}");
$html .= $ff->FF_Label("Text part","m_text","{{@labelclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivOpen("{{@columnclass6}}");
$html .= $ff->FF_Button("submit","Save Message","{{@buttonclass}}");
$html .= $ff->FF_DivClose();

$html .= $ff->FF_DivClose();

$html .= $ff->FF_FieldsetClose();
$html .= $ff->FF_FormClose();
