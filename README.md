# LocalContributionManager

地域拠点の情報を収集し、一覧するためのサイト向けシステムです。Picoをベースとしているため比較的容易にセットアップ・実行が出来ます。

## インストール

### 必要なモジュール

メール受信時にメールを解析して動作をするために、以下のライブラリを使用しています。

 * Mail(Pear)
 * Mail_MIMEDECODE(Pear)

レンタルサーバなどで利用する場合、Pearが利用できるかどうか確認してください。そのうちPearなし版も検討したいですが、未定です。

Pearで上記コマンドを利用可能にする場合、次のようにします。

    $ pear install Mail
    $ pear install Mail_mimeDecode
    
なお、さくらインターネットでは最初から入っているPearが古い・かつ変更不能ですので、独自にPearをインストールする必要があります。
以下を参考にPearをインストールしなおします。

    $ curl -sS http://pear.php.net/go-pear > ~/go-pear.php
    $ php ~/go-pear.php
    $ mv ~/pear/php.ini-gopear ~/www/php.ini
    
なお、メール解析スクリプト実行時には、上記php.iniが読み込まれない場合があるようなので、textreciever.phpなどで独自にインクルードパスを設定する必要があります。
    set_include_path(".:/home/[アカウント名]/pear/share/pear");

### メール受信・解析エンジン

※ さくらインターネット以外の動作は検証していません。

.mailfilterを使用します。

    $ vi ~/MailBox/[メールアカウント名]/.mailfilter
      to "| /usr/local/bin/php -q /home/[アカウント名]/www/[システムの設置ディレクトリ]/mailsys/textreciever.php"
      exit
    $ chmod 600 ~/MailBox/[メールアカウント名]/.mailfilter
    $ chmod 744 ~/www/[システムの設置ディレクトリ]/mailsys/textreciever.php
    
### Pico

Picoの利用自体は本家Picoと同様です。

    $ curl -sS https://getcomposer.org/installer | php
    $ php composer.phar install

動作確認程度には、以下のコマンドも使えます

    $ php -S 0.0.0.0:8080 ./

## Wiki
詳しい情報は、[Pico Wiki](https://github.com/picocms/Pico/wiki)をご覧ください。こちらのWikiにはセットアップに関する情報などを記載します。
