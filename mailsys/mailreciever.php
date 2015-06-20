<?php
define('ROOT_DIR', realpath(dirname(__FILE__) . '/../') . '/');
define('MAIL_ENCODING', 'ISO-2022-JP');
define('MD_ENCODING', 'UTF-8');
// 言語（そのうち真面目にローカライズするかも？）
define('ERROR_NOTFOUND_TITLE', "原稿にタイトルの記載が見つかりませんでした。\n");
define('ERROR_NOTFOUND_DATE', "原稿に日付の記載が見つかりませんでした。\n");
define('ERROR_NOTFOUND_DESCRIPTION', "原稿に概要の記載が見つかりませんでした。\n");
define('ERROR_GUIDANCE_RESENDMAIL', 'もう一度原稿を送り直してください');
define('ERROR_UNKNOWN_FORMAT', '未知のメール書式です。管理者に連絡してください。');
define('ERROR_UNKNOWN_ATTACHMENT', '対応していないファイルが添付されています。対応している添付ファイルは、.png, .gif, .jpgのみです');
define('DOC_DATETIME_NAME', '日時');
define('DOC_EXPENCE_NAME', '費用');
define('DOC_CAPACITY_NAME', '定員');

class MailReciever{

  private $charset;
  private $config;
  private $headers;
  private $body;
  private $content;
  private $attaches;
  private $markdown;
  
  public function __construct(){
    require_once("Mail/mimeDecode.php");
    require_once("Mail.php");
    $this->config = @include_once(ROOT_DIR . 'config.php');
    $this->attaches = array();
  }
  
  ///
  /// メールをパースする
  /// 
  /// $text ... メールのテキストデータ
  ///
  public function parseMail($text) {
    $decoder = & new Mail_mimeDecode( $text );
    $struct = $decoder->decode(array(
        'include_bodies' => true,
        'decode_bodies' => true,
        'decode_headers' => true,
      ));
    $this->headers = $struct->headers;
    $this->decodeData($struct);
    $subject = $this->getHeader("subject");
    $this->content = $this->parseMailBodyHeader(array("title" => $subject), $this->body);
    $this->markdown = $this->_createMarkdown($this->content);
  }
  
  ///
  /// Markdownファイルを生成する
  ///
  public function createMarkdown() {
    $dir = $this->getContentDir(); 
    $fn = $this->getUniqueFileName();
    file_put_contents($dir . $fn, mb_convert_encoding($this->markdown, mb_internal_encoding(), MD_ENCODING));
  }

  ///
  /// Webhookでメール受信を通知する
  /// 
  /// $success ... メールのパースが成功した場合はtrue
  ///
  public function sendWebhook($success) {
    $body;
    $subject;
    if($success){
      $body = $this->markdown;
      $subject = $this->getHeader("subject");
    }else{
      $body = sprintf("From:%s\n\n----\n%s\n----", $this->getHeader("from"), 
        empty($this->body) ? "Decode Failed" : $this->body );
      $subject = "E:" . $this->getHeader("subject");
    }
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
    $to = $this->getHeader("from");
    $to = preg_replace_callback("/(.*?)<([^>]+)>/",  function($m){
        return sprintf('"%s" <%s>', mb_encode_mimeheader($m[1], MAIL_ENCODING), $m[2]);
      }, $to);
    $ret = $mail_object->send($to, array("From" => $this->config["mailpost"]["mailbox"]["mailaddr"], "To" => $to), $text);
    if($ret != true){
      throw new Exception($ret->getMessage());
    }
  }

  ///
  /// メール文を解析し、コンテントリストに格納する
  ///
  /// $base ... コンテントリストを格納する配列。最初に入れておきたいデータがあれば、ここに指定する。
  /// $text ... メール本文
  /// return ... コンテントリスト
  ///
  protected function parseMailBodyHeader($base, $text) {
    $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $text));
    $content = $base;
    // ヘッダ解析
    while(($line = array_shift($lines)) != ""){
      $nv = explode(":", str_replace("：", ":", $line), 2);
      if(sizeof($nv) >= 2){
        list($name, $value) = $nv;
        switch($name){
          case "日時":
          case "Date":
            list($date, $time) = explode(" ", str_replace("　", " ", $value), 2);
            $content["date"] = trim($date);
            if($time){
              $content["time"] = trim($time);
            }
            break;
          case "費用":
          case "expence":
            $content["expence"] = trim($value);
            break;
          case "定員":
          case "Capacity:":
            $content["capacity"] = trim($value);
            break;
          case "概要":
          case "Summary":
            $content["description"] = trim($value);
            break;
        }
      }
    }
    
    // 署名をカットしつつ、それ以外の文章をbodyに
    $content["body"] = "";
    foreach($lines as $line){
      if(preg_match('/^-{2} ?$/', $line)){
        break;
      }
      $content["body"] .= $line . "\n";
    }
    return $content;
  }

  ///
  /// コンテントリストからMarkdownデータを作成する
  ///
  /// $content ... コンテントリスト
  ///
  protected function _createMarkdown($content) {
    // エラーチェック
    if(!array_key_exists("title", $content) || empty($content["title"])){
      throw new MailParseException(ERROR_NOTFOUND_TITLE . ERROR_GUIDANCE_RESENDMAIL);
    }
    if(!array_key_exists("date", $content)){
      throw new MailParseException(ERROR_NOTFOUND_DATE . ERROR_GUIDANCE_RESENDMAIL);
    }
    if(!array_key_exists("description", $content)){
      throw new MailParseException(ERROR_NOTFOUND_DESCRIPTION . ERROR_GUIDANCE_RESENDMAIL);
    }

    // ドキュメント生成
    $doc = "/*\n";
    $doc .= sprintf("  Title:%s\n", $content["title"]);
    $doc .= sprintf("  Date:%s\n", $content["date"]);
    $doc .= sprintf("  Author:%s\n", preg_replace("/@[^>]+/",  "", $this->getHeader("from"))); // mdファイルに直接アクセスされたときのために、メールアドレスの@以降は削る
    $doc .= sprintf("  Description:%s\n", $content["description"]);
    if(!empty($this->attaches)){
      $doc .= sprintf("  Image:%s\n", implode(",", $this->attaches));
    }
    $doc .= "*/\n";
    if(array_key_exists("time", $content)) {
      $doc .= sprintf("%s:%s %s\n", DOC_DATETIME_NAME, $content["date"], $content["time"]);
    }
    if(array_key_exists("expence", $content)) {
      $doc .= sprintf("%s:%s\n", DOC_EXPENCE_NAME, $content["expence"]);
    }
    if(array_key_exists("capacity", $content)) {
      $doc .= sprintf("%s:%s\n", DOC_CAPACITY_NAME, $content["capacity"]);
    }
    $doc .= "\n";
    $doc .= $content["body"];
    return $doc;
  }
  
  /// 
  /// メールデコード構造体を解析してテキストを抽出する
  ///
  /// $struct ... メールデコード構造体
  ///
  protected function decodeData($struct) {
    if(!empty($struct->parts)){
      foreach($struct->parts as $part){
        $this->decodeData($part);
      }
    }else{
      switch($struct->ctype_primary){
        case "text":
          switch($struct->ctype_secondary){
            case "plain":
              $this->charset = $struct->ctype_parameters['charset'];
              $this->body = trim(mb_convert_encoding( $struct->body, mb_internal_encoding(), $this->charset ));
              break;
            case "html":
              // 無視
              break;
            default:
              break;
          }
          break;
        case "image":
          $this->addAttachmentFile($struct);
          break;
        default:
          break;
      }
    }
  }

  /// 
  /// メールデコード構造体に含まれる添付ファイルを保存する。
  ///
  /// $struct ... メールデコード構造体
  ///
  protected function addAttachmentFile($struct) {
    $dir = $this->getContentDir();
    $fn = $struct->d_parameters['filename'];
    $fn = $this->getUniqueFileName(
      sprintf(".%s%s", substr($fn, 0, strrpos($fn, '.')), 
        $this->fileTypeToExtension($struct->ctype_primary, $struct->ctype_secondary)));
    file_put_contents($dir . $fn, $struct->body);
    $this->attaches[] = $fn;
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

  ///
  /// ユニークなファイル名を取得する。現時点のバージョンではMessage-IDを使用する
  ///
  /// $ext ... 拡張子。省略時は.md
  /// return ... ユニークなファイル名
  ///
  protected function getUniqueFileName($ext = ".md") {
    $fn = $this->getHeader("message-id") . $ext;
    $fn = str_replace(array("\\", "/", ":". "*", "?", "<", ">", "|", " "), "", $fn); // ファイル名に使用できない文字は削除
    return $fn;
  }

  ///
  /// Picoのコンテントディレクトリを取得する
  ///
  /// return ... コンテントディレクトリ
  ///
  protected function getContentDir(){
    $dir = ROOT_DIR . $this->config['content_dir'];
    if(!file_exists($dir)){
      mkdir($dir);
    }
    return $dir;
  }

  ///
  /// ファイルタイプ(ctype_primaryとctype_secondary)から、拡張子を導き出す
  protected function fileTypeToExtension($primarytype, $secondarytype) {
    $type = $primarytype . "/" . $secondarytype;
    $db = array(
      'image/jpeg' => '.jpeg',
      'image/gif' => '.gif',
      'image/png' => '.png',
    );
    $ext;
    if(array_key_exists($type, $db)){
      $ext = $db[$type];
    }else{
      throw new MailParseException(ERROR_UNKNOWN_ATTACHMENT);
    }
    return $ext;
  }
  
  private function getHeader($part) {
    return $this->getMailContent( $this->headers[$part]);
  }
  
  private function getMailContent($text) {
    return mb_convert_encoding( $text, mb_internal_encoding(), $this->charset );
  }
}

class MailParseException extends Exception{}
?>