<?php
require_once('mailreciever.php');

try{
  $reciever = new MailReciever();
  $mail_text = mb_convert_encoding( file_get_contents("php://stdin"), MAIL_ENCODING, "auto" );
  try{
    $reciever->parseMail($mail_text);
    $reciever->createMarkdown();
    $reciever->sendWebhook(true);
  }catch(Exception $me){
    if(get_class($me) == "MailParseException"){
      // メール構文エラーの場合、送り返す
      $reciever->replyMail($me->getMessage());
      $reciever->sendWebhook(false);
    }
    throw $me;
  }
}catch(Exception $e){
  $reciever->sendWebhook_ErrorReport($e);
}