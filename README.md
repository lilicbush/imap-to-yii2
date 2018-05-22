Imap agent
==========
Imap agent component for Yii2

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist lilicbush/imap-to-yii2 "*"
```

or add

```
"lilicbush/imap-to-yii2": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :

```php
$imap = new ImapAgent;
$imap->type = 'imap/ssl/novalidate-cert';
$imap->server = 'imap.mail.ru';
$imap->port = 993;
$imap->user = 'mailbox@mail.ru';
$imap->password = 'password';
        
foreach ($imap->messages as $message) {
     $message->move($folder);
     //$message->delete();
}
 
$imap->close();
```