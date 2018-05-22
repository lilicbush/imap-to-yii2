<?php

namespace lilicbush\imap;

use HttpException;

/**
 * Example:
 * 
 * <code>
 * $imap = new ImapAgent;
 * $imap->type = 'imap/ssl/novalidate-cert';
 * $imap->server = 'imap.mail.ru';
 * $imap->port = 993;
 * $imap->user = 'mailbox@mail.ru';
 * $imap->password = 'password';
 *         
 * foreach ($imap->messages as $message) {
 *      $message->move($folder);
 *      //$message->delete();
 * }
 * 
 * $imap->close();
 * 
 * </code>
 *
 * 
 * @property-read ImapMessage[] $messages Return array of ImapMessge class
 * @property-read integer $count
 * @property-read resource $stream      Mailbox stream
 * @property string $user               Username
 * @property string $password           Password
 * @property string $server             Host connect to server<br>example: mail.example.com
 * @property integer $port              Port connect to server<br>example: 110, 993, 995
 * @property string $type               Type connect to server<br>example: pop3, pop3/ssl, imap/ssl/novalidate-cert
 * @property string $folder             Imap folder name, in default it is 'INBOX'
 * @property-read array $folders        Imap folders list
 * @property boolean $markAsSeen        Mark message as seen when getting message body
 */
class ImapAgent extends Component {

    protected $_server;
    protected $_port = 110;
    protected $_type = 'pop3';
    protected $_options = 0;
    protected $_folder = 'INBOX';
    //
    public $_user;
    public $_password;

    /**
     * Ping everytime when getting stream
     * @var boolean 
     */
    public $pingStream = false;
    public $serverCharset = 'UTF-8';
    //
    protected $_search = 'ALL';
    protected $_sort;
    protected $_sortReverse = true;
    protected $_markAsSeen = true;
    private $_count;

    /*
     * @var $_messages[] ImapMessage;
     */
    private $_messages;
    private $_folders;

    /**
     * mailbox stream
     * @var resource
     */
    protected $_stream;
    private $_init = false;

    public function init() {
        if (!extension_loaded("imap"))
            throw new HttpException(500, 'Could not load extension "imap". Please install extension.');
        $this->_init = true;
    }

    public function __construct($config = []) {
        parent::__construct($config);
        $this->_sort = SORTDATE;
    }

    public function __destruct() {
        $this->close();
    }

    public function getStream() {
        if ($this->_stream !== null && (!is_resource($this->_stream) || ($this->pingStream && !imap_ping($this->_stream)))) {
            $this->disconnect();
        }
        if ($this->_stream === null) {
            $this->connect();
        }

        return $this->_stream;
    }

    protected function getImapDSN() {
        return '{' . $this->getServer() . ':' . $this->getPort() . '/' . $this->getType() . '}';
    }

    protected function connect() {
        if (!$this->_init) {
            $this->init();
        }
        $this->_stream = @imap_open($this->getImapDSN() . $this->getFolder(), $this->getUser(), $this->getPassword(), $this->getOptions());
        if (!$this->_stream) {
            if ($error = imap_last_error()) {
                throw new HttpException(500, $error);
            } else {
                throw new HttpException(500, 'Couldn\'t open stream  ' . $this->getServer() . ':' . $this->getPort() . '.');
            }
        }
//        imap_gc($this->_stream, IMAP_GC_ELT | IMAP_GC_ENV | IMAP_GC_TEXTS);
    }

    protected function isConnected($ping = false) {
        return $this->_stream && is_resource($this->_stream) && (!$ping || imap_ping($this->_stream));
    }

    protected function disconnect() {
        if ($this->isConnected()) {
            imap_close($this->_stream, CL_EXPUNGE);
        }
        $this->_stream = null;
        $this->_messages = null;
        $this->_count = null;
        $this->_folders = null;
    }

    /**
     * Close IMAP connection
     */
    public function close() {
        $this->disconnect();
    }

    /**
     * Gets folders list
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return array listing the folders
     */
    public function getFolders() {
        if (!isset($this->_folders)) {
            $folders = imap_getmailboxes($this->getStream(), $this->getImapDSN(), "*");
            foreach ($folders as $folder) {
                $name = $folder->name;
                $name = str_replace($this->getImapDSN(), "", $name);
                $this->_folders[$name] = mb_convert_encoding($name, $this->serverCharset, 'utf7-imap');
            }
        }
        return $this->_folders;
    }

    /**
     * 
     * @param string $folder Folder name in utf7-imap
     * @return string
     */
    public function getFolderName($folder = null) {
        if ($folder === null) {
            $folder = $this->getFolder();
        }
        if (isset($this->getFolders()[$folder])) {
            return $this->getFolders()[$folder];
        }
        return null;
    }

    public function getCount() {
        if ($this->_count === null) {
            $this->_count = imap_num_msg($this->getStream());
        }
        return $this->_count;
    }

    /**
     * 
     * @param integer $offset
     * @param integer $limit
     * @param boolean $lazy_loading Lazy loading for message headers
     * @return ImapMessage[] Array of ImapMessage class
     */
    public function getMessages($offset = 0, $limit = null, $lazy_loading = true) {
        if ($this->_messages === null) {
            $this->_messages = [];

            $list = $this->getMsgIDs($offset, $limit);
            if (!$lazy_loading) {
                $headers = imap_fetch_overview($this->getStream(), implode(',', $list), FT_UID);
                if (is_array($headers)) {
                    if ($this->getSortReverse()) {
                        krsort($headers);
                    }
                    foreach ($headers as $header) {
                        $this->_messages[] = new ImapMessage($this, $header);
                        unset($header);
                    }
                }
                unset($headers);
            } else {
                foreach ($list as $uid) {
                    $this->_messages[] = new ImapMessage($this, $uid);
                }
            }
        }
        return $this->_messages;
    }

    /**
     * Return one message object
     * @param integer $msgID Message UID
     * @return ImapMessage|null
     */
    public function getMessage($msgID) {
        $headers = imap_fetch_overview($this->getStream(), $msgID, FT_UID);
        if (is_array($headers)) {
            foreach ($headers as $header) {
                return new ImapMessage($this, $header);
            }
        }
        return null;
    }

    protected function getMsgIDs($offset, $limit) {
        $list = imap_sort($this->getStream(), $this->getSort(), $this->getSortReverse() ? 1 : 0, SE_UID | SE_NOPREFETCH, $this->getSearch(), $this->serverCharset);
        $list = array_slice($list, $offset, $limit);
        return !is_array($list) ? [] : $list;
    }

    public function getServer() {
        return $this->_server;
    }

    public function setServer($val) {
        $this->_server = $val;
        $this->disconnect();
    }

    public function getPort() {
        return $this->_port;
    }

    public function setPort($val) {
        $this->_port = $val;
        $this->disconnect();
    }

    public function getType() {
        return $this->_type;
    }

    public function setType($val) {
        $this->_type = $val;
        $this->disconnect();
    }

    public function getOptions() {
        return $this->_options;
    }

    public function setOptions($val) {
        $this->_options = $val;
        $this->disconnect();
    }

    public function getUser() {
        return $this->_user;
    }

    public function setUser($val) {
        $this->_user = $val;
        $this->disconnect();
    }

    public function getPassword() {
        return $this->_password;
    }

    public function setPassword($val) {
        $this->_password = $val;
        $this->disconnect();
    }

    public function getFolder() {
        return $this->_folder;
    }

    public function setFolder($val) {
        if ($this->getFolder() === $val) {
            return true;
        }
        $this->_folder = $val;
        if ($this->isConnected(true)) {
            return imap_subscribe($this->getStream(), $this->getImapDSN() . $this->_folder);
        } else {
            $this->disconnect();
        }
        return true;
    }

    public function getSearch() {
        return $this->_search;
    }

    public function setSearch($_search) {
        $this->_search = $_search;
        $this->_messages = null;
    }

    public function getSort() {
        return $this->_sort;
    }

    public function getSortReverse() {
        return $this->_sortReverse;
    }

    public function setSort($sort) {
        $this->_sort = $sort;
        $this->_messages = null;
    }

    public function setSortReverse($sortReverse) {
        $this->_sortReverse = $sortReverse;
        $this->_messages = null;
    }

    public function getMarkAsSeen($asBool = true) {
        if (!$asBool) {
            return $this->_markAsSeen ? 0 : FT_PEEK;
        }
        return $this->_markAsSeen;
    }

    public function setMarkAsSeen($val) {
        $this->_markAsSeen = (bool) $val;
    }

}
