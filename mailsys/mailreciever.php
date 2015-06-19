<?php
define('ROOT_DIR', realpath(dirname(__FILE__) . '/../') . '/');
define('MAIL_ENCODING', 'ISO-2022-JP');
// 言語（そのうち真面目にローカライズするかも？）
define('ERROR_NOTFOUND_TITLE', "原稿にタイトルの記載が見つかりませんでした。\n");
define('ERROR_NOTFOUND_DATE', "原稿に日付の記載が見つかりませんでした。\n");
define('ERROR_NOTFOUND_DESCRIPTION', "原稿に概要の記載が見つかりませんでした。\n");
define('ERROR_GUIDANCE_RESENDMAIL', 'もう一度原稿を送り直してください');
define('DOC_DATETIME_NAME', '日時');
define('DOC_EXPENCE_NAME', '費用');
define('DOC_CAPACITY_NAME', '定員');

class MailReciever{

  private $config;
  private $recipients;
  private $headers;
  private $body;
  
  public function __construct(){
    require_once("Mail/mimeDecode.php");
    require_once("Mail.php");
    $this->config = @include_once(ROOT_DIR . 'config.php');
  }
  
  ///
  /// メールをパースする
  /// 
  /// $text ... メールのテキストデータ
  ///
  public function parseMail($text) {
    $decoder = & new Mail_mimeDecode( $text );
    $parts = $decoder->getSendArray();
    list($recipients, $headers, $body) = $parts;
    $this->recipients = $recipients;
    $this->headers = $headers;
    $this->body = trim(mb_convert_encoding( $body, "UTF-8", "JIS" ));
  }
  
  ///
  /// Markdownファイルを生成する
  ///
  public function createMarkdown() {
    $subject = $this->getHeader("Subject");
    $body = $this->body;
    $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $body));
    $header = array("title" => $subject);
    // ヘッダ解析
    while(($line = array_shift($lines)) != ""){
      $nv = explode(":", str_replace("：", ":", $line), 2);
      if(sizeof($nv) >= 2){
        list($name, $value) = $nv;
        switch($name){
          case "日時":
          case "Date":
            list($date, $time) = explode(" ", str_replace("　", " ", $value), 2);
            $header["date"] = trim($date);
            if($time){
              $header["time"] = trim($time);
            }
            break;
          case "費用":
          case "expence":
            $header["expence"] = trim($value);
            break;
          case "定員":
          case "Capacity:":
            $header["capacity"] = trim($value);
            break;
          case "概要":
          case "Summary":
            $header["description"] = trim($value);
            break;
        }
      }
    }

    // エラーチェック
    if(!array_key_exists("title", $header)){
      throw new MailParseException(ERROR_NOTFOUND_TITLE . ERROR_GUIDANCE_RESENDMAIL);
    }
    if(!array_key_exists("date", $header)){
      throw new MailParseException(ERROR_NOTFOUND_DATE . ERROR_GUIDANCE_RESENDMAIL);
    }
    if(!array_key_exists("description", $header)){
      throw new MailParseException(ERROR_NOTFOUND_DESCRIPTION . ERROR_GUIDANCE_RESENDMAIL);
    }
    
    // ドキュメント生成
    $doc = "/*\n";
    $doc .= sprintf("  Title:%s\n", $header["title"]);
    $doc .= sprintf("  Date:%s\n", $header["date"]);
    $doc .= sprintf("  Description:%s\n", $header["description"]);
    $doc .= "*/\n";
    if(array_key_exists("time", $header)) {
      $doc .= sprintf("%s:%s %s\n", DOC_DATETIME_NAME, $header["date"], $header["time"]);
    }
    if(array_key_exists("expence", $header)) {
      $doc .= sprintf("%s:%s\n", DOC_EXPENCE_NAME, $header["expence"]);
    }
    if(array_key_exists("capacity", $header)) {
      $doc .= sprintf("%s:%s\n", DOC_CAPACITY_NAME, $header["capacity"]);
    }
    $doc .= "\n";
    $doc .= implode("\n", $lines);
    
    // 保存
    $fn = $this->getHeader("Message-id") . ".md";
    $fn = str_replace(array("\\", "/", ":". "*", "?", "<", ">", "|", " "), "", $fn);
    $dir = ROOT_DIR . $this->config['content_dir'];
    if(!file_exists($dir)){
      mkdir($dir);
    }
    file_put_contents($dir . $fn, $doc);
  }

  ///
  /// Webhookでメール受信を通知する
  /// 
  /// $success ... メールのパースが成功した場合はtrue
  ///
  public function sendWebhook($success) {
    $body = sprintf("From:%s\n\n----\n%s\n----", $this->getHeader("From"), $this->body);
    $subject = (!$success ? "Parse Error:" : "Parse Success:") . $this->getHeader("Subject");
    $icon = $success ? ":email:" : ":x:";
    $this->_sendWebhook($body, $subject, $icon);
  }
  
  ///
  /// エラーレポートをWebhookで送信する
  /// 
  /// $error ... エラーオブジェクト
  ///
  public function sendWebhook_ErrorReport($error) {
    $body = $error->getTraceAsString();
    $subject = $error->getMessage();
    $icon = ":interrobang:";
    $this->_sendWebhook($body, $subject, $icon);
  }

  ///
  /// メール送信者にメールを返信する
  ///
  /// $text ... メール本文
  ///
  public function replyMail($text) {
    $mail_options = array(
      'host'      => $this->config["mailpost"]["mailbox"]["sendhost"],
      'port'      => $this->config["mailpost"]["mailbox"]["sendport"],
      'auth'      => true,
      'username'  => $this->config["mailpost"]["mailbox"]["username"],
      'password'  => $this->config["mailpost"]["mailbox"]["password"],
    );
   
    $mail_object =& Mail::factory("SMTP", $mail_options); 
    $to = $this->getHeader("From");
    $to = preg_replace_callback("/(.*?)<([^>]+)>/",  function($m){
        return sprintf('"%s" <%s>', mb_encode_mimeheader($m[1], MAIL_ENCODING), $m[2]);
      }, $to);
    $ret = $mail_object->send($to, array("From" => $this->config["mailpost"]["mailbox"]["mailaddr"], "To" => $to), $text);
    if($ret != true){
      throw new Exception($ret->getMessage());
    }
  }
  
  ///
  /// Webhookに送信を行う内部関数
  ///
  /// $text ... 送信文
  /// $name ... 送信者名
  /// $icon ... アイコン
  ///
 protected function _sendWebhook($text, $name, $icon = ":email") {
    $hookaddr = $this->config["mailpost"]["hookurl"];
    if($hookaddr){
      $payload = array(
            "text" => $text,
            "username" => $name,
            "icon_emoji" => $icon,
          );

      // curl
      $curl = curl_init($hookaddr);
      try{
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array());
        curl_setopt($curl, CURLOPT_POSTFIELDS, array('payload' => json_encode($payload)));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($curl);
        $err = curl_error($curl);
        if($err) throw new Exception($err);
        if($res != "ok") throw new Exception($res);
      }catch(Exception $e){
        throw $e;
      }
      curl_close($curl);
    }
  }
  
  private function getHeader($part) {
    return $this->getMailContent( $this->headers[$part]);
  }
  
  private function getMailContent($text) {
    return mb_decode_mimeheader( $text );
  }
}

class MailParseException extends Exception{}
?>