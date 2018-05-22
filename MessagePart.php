<?php

/*
 * 
 */

namespace lilicbush\imap;

/**
 * Part of message
 * 
 * @property-read string $partno        This part number
 * @property-read integer $type	Primary body type
 * @property-read string $encoding	Body transfer encoding
 * @property-read string $subtype	MIME subtype
 * @property-read string $description	Content description string
 * @property-read string $id	Identification string
 * @property-read integer $lines	Number of lines
 * @property-read integer $bytes	Number of bytes
 * @property-read string $disposition	Disposition string
 * @property-read \stdClass $dparameters	Corresponding to the parameters on the Content-disposition MIME header.
 * @property-read \stdClass $parameters	Properties.
 * @property-read MessagePart[] $parts	An array of objects identical in structure to the top-level object, each of which corresponds to a MIME body part.
 *
 */
class MessagePart extends Object {

    const DEFAULT_IMAP_CHARSET = 'utf-8';
    //
    const TYPE_TEXT = 0;
    const TYPE_MULTIPART = 1;
    const TYPE_MESSAGE = 2;
    const TYPE_APPLICATION = 3;
    const TYPE_AUDIO = 4;
    const TYPE_IMAGE = 5;
    const TYPE_VIDEO = 6;
    const TYPE_MODEL = 7;
    const TYPE_OTHER = 8;

    public static $types = [
        self::TYPE_TEXT => 'text',
        self::TYPE_MULTIPART => 'multipart',
        self::TYPE_MESSAGE => 'message',
        self::TYPE_APPLICATION => 'application',
        self::TYPE_AUDIO => 'audio',
        self::TYPE_IMAGE => 'image',
        self::TYPE_VIDEO => 'video',
        self::TYPE_MODEL => 'model',
        self::TYPE_OTHER => 'other',
    ];

    /**
     * Link to ImapMessage Object
     * @var ImapMessage
     */
    protected $_message;
    protected $_partno;
    protected $_structure;
    protected $_parts;
    protected $_parameters;
    protected $_dparameters;
    protected $_bodyRAW;

    public function __construct($message, $part = null, $partno = 0, $config = []) {
        parent::__construct($config);
        $this->_message = $message;
        $this->_partno = $partno;
        if ($part !== null) {
            $this->_structure = $part;
        }
    }

    protected function getStructure() {
        if ($this->_structure === null) {
            $this->_structure = imap_fetchstructure($this->_message->getImap()->getStream(), $this->_message->getUid(), FT_UID);
        }
        return $this->_structure;
    }

    protected function convertEncoding($encoding) {
        $name = strtoupper('ENC' . str_replace('-', '', $encoding));
        if (defined($name)) {
            return constant($name);
        }
        return ENCOTHER;
    }
    
    protected function fetchStructureAlternative($head = null, $body = null) {
        $struct = $this->getStructureAlternative($head = null, $body = null);
        if (!empty($struct) && count($this->getStructure()->parts) !== count($struct->parts)) {
            $this->_structure = $struct;
            $this->_parameters = null;
            $this->_dparameters = null;
            return true;
        }
        return false;
    }

    protected function getStructureAlternative($head = null, $body = null) {
        if ($head === null) {
            if (!$this->partno) {
                $head = imap_fetchheader($this->_message->getImap()->getStream(), $this->_message->getUid(), FT_UID);
            } else {
                return null;
            }
        }
        $headers = $this->parseHeaders($head);
        $struct = new \stdClass();
        $contentType = isset($headers['content-type']) ? $headers['content-type'][0] : 'other';
        $contentType = explode('/', $contentType);
        $contentType = count($contentType) > 1 ? $contentType : array_merge($contentType, [NULL]);
        list($contentType, $subType) = $contentType;
        $type = array_search($contentType, static::$types);
        $struct->type = $type !== false ? $type : self::TYPE_OTHER;
        $struct->ifdescription = false;
        $struct->ifid = false;
        if (isset($headers['content-id'])) {
            $struct->ifid = true;
            $struct->id = $headers['content-id'][0];
        }
        $struct->ifsubtype = $subType !== null;
        $struct->subtype = $subType;
        $struct->ifparameters = true;
        $struct->parameters = [];
        $struct->ifparameters = false;
        if (isset($headers['content-type'])) {
            foreach ($headers['content-type'] as $key => $val) {
                if (!is_numeric($key)) {
                    $struct->ifparameters = true;
                    $param = new \stdClass();
                    $param->attribute = $key;
                    $param->value = $val;
                    $struct->parameters[] = $param;                    
                }
            }
        }
        $struct->encoding = $this->convertEncoding(isset($headers['content-transfer-encoding']) ? $headers['content-transfer-encoding'][0] : 'other');
        $disposition = isset($headers['content-disposition']) ? $headers['content-disposition'][0] : null;
        $struct->ifdisposition = $disposition !== null;
        if ($disposition !== null) {
            $struct->disposition = $disposition;
            $struct->dparameters = [];
            $struct->ifdparameters = false;
            foreach ($headers['content-disposition'] as $key => $val) {
                if (!is_numeric($key)) {
                    $struct->ifdparameters = true;
                    $param = new \stdClass();
                    $param->attribute = $key;
                    $param->value = $val;
                    $struct->dparameters[] = $param;    
                }
            }
        }
        $struct->parts = [];
        if ($struct->type === self::TYPE_MULTIPART) {
            if ($boundary = (isset($headers['content-type']['boundary']) ? $headers['content-type']['boundary'] : null)) {
                $parts = explode('--' . $boundary, $this->getBodyRAW());
                array_shift($parts);
                array_pop($parts);
                foreach ($parts as $part) {
                    list($subhead, $subbody) = array_merge(explode("\r\n\r\n", $part, 2), [null]);
                    $struct->parts[] = $this->getStructureAlternative(trim($subhead), $subbody);
                }
            }
        } else {
            $struct->_body = $body;
            $struct->bytes = strlen($body);
        }
        return $struct;
    }

    protected function parseHeaders($head) {
        $data = $head;
        $parsed = [];
        $blocks = preg_split('/\n\n/', $data);
        $lines = array();
        $matches = array();
        foreach ($blocks as $i => $block) {
            $parsed[$i] = array();
            $lines = preg_split('/\n(([\w.-]+)\: *((.*\n\s+.+)+|(.*(?:\n))|(.*))?)/', $block, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($lines as $line) {
                $param_matches = [];
                if (preg_match('/^\n?([\w.-]+)\: *((.*\n\s+.+)+|(.*(?:\n))|(.*))?$/', $line, $matches) && preg_match_all('/\s*(([\w.-]+)=(?:\"([^\"]+)\"|([^;]+))|[^;]+)(?:;|$)/', preg_replace('/\r\n[ \t]*/', '', trim($matches[2])), $param_matches, PREG_SET_ORDER)) {
                    foreach ($param_matches as $match) {
                        $param_key = strtolower($matches[1]);
                        if (isset($match[2])) {
                            if (isset($match[4])) {
                                $parsed[$i][$param_key][strtolower($match[2])] = $match[4];
                            } else {
                                $parsed[$i][$param_key][strtolower($match[2])] = $match[3];
                            }
                        } else {
                            $parsed[$i][$param_key][] = $match[1];
                        }
                    }
                }
            }
        }
        return count($parsed) ? $parsed[0] : [];
    }

    public function getPartno() {
        return $this->_partno;
    }

    /**
     * 
     * @return MessagePart[]
     */
    public function getParts() {
        if ($this->_parts === null) {
            $this->_parts = [];
            $struct = $this->getStructure();
            if (isset($struct->parts) && is_array($struct->parts)) {
                if (count($struct->parts) < 2 && $this->fetchStructureAlternative()) {
                    // Альтернативный вариант получения структуры сообщения
                    \Yii::warning('attachment mistrust #' . $this->_message->getUid() . ': ' . $this->_message->getFrom() . ' - ' . $this->_message->getSubject());
                    $struct = $this->getStructure();
                }
                foreach ($struct->parts as $partId => $nextPart) {
                    if ($this->getProperty('type', $nextPart) === self::TYPE_MULTIPART || ($this->getProperty('type', $nextPart) === self::TYPE_TEXT && $this->getProperty('disposition', $nextPart) !== 'attachment')) {
                        $this->_parts[] = new MessagePart($this->_message, $nextPart, (!$this->_partno ? '' : ($this->_partno . '.')) . ($partId + 1));
                    } else {
                        $this->_parts[] = new ImapFile($this->_message, $nextPart, (!$this->_partno ? '' : ($this->_partno . '.')) . ($partId + 1));
                    }
                }
                $this->_structure->parts = null;
            }
        }
        return $this->_parts;
    }

    public function getBodyRAW() {
        if ($this->_bodyRAW === null) {
            if ($this->_structure instanceof \stdClass && isset($this->_structure->_body)) {
                $this->_bodyRAW = $this->_structure->_body;
                unset($this->_structure->_body);
            } else {
                if (!$this->getPartno()) {
                    $this->_bodyRAW = imap_body($this->_message->getImap()->getStream(), $this->_message->getUid(), FT_UID | $this->_message->getImap()->getMarkAsSeen(false));
                } else {
                    $this->_bodyRAW = imap_fetchbody($this->_message->getImap()->getStream(), $this->_message->getUid(), $this->getPartno(), FT_UID | $this->_message->getImap()->getMarkAsSeen(false));
                }
            }
        }
        return $this->_bodyRAW;
    }

    public function setBodyRAW($body) {
        $this->_bodyRAW = $body;
    }

    /**
     * Get part data
     * @return string
     */
    public function getData() {
        return $this->decodeData($this->getBodyRAW(), $this->getEncoding());
    }

    protected function decodeData($data, $encoding = 0) {
        switch ($encoding) {
            case ENC7BIT:
            case ENC8BIT:
                $data = imap_utf8($data);
                break;
            case ENCBINARY:
                $data = imap_binary($data);
                break;
            case ENCBASE64:
                $data = base64_decode($data);
                break;
            case ENCQUOTEDPRINTABLE:
                $data = quoted_printable_decode($data);
                break;
            default:
                break;
        }
        return $data;
    }

    protected function getProperty($method, $object = null) {
        if ($object === null) {
            $object = $this->getStructure();
        }
        $method = explode('::', $method);
        if (is_array($method)) {
            $method = array_pop($method);
        } else {
            return null;
        }
        $prop = strtolower(str_replace('get', '', $method));
        $ifprop = 'if' . $prop;
        if ((!property_exists($object, $ifprop) || $object->$ifprop) && isset($object->$prop)) {
            return $object->$prop;
        }
        return null;
    }

    public function getId() {
        return $this->getProperty(__METHOD__);
    }

    public function getType() {
        return $this->getProperty(__METHOD__);
    }

    public function getEncoding() {
        return $this->getProperty(__METHOD__);
    }

    public function getSubtype() {
        return $this->getProperty(__METHOD__);
    }

    public function getDescription() {
        return $this->getProperty(__METHOD__);
    }

    public function getLines() {
        return $this->getProperty(__METHOD__);
    }

    public function getBytes() {
        return $this->getProperty(__METHOD__);
    }

    public function getDisposition() {
        return $this->getProperty(__METHOD__);
    }

    protected function parseParams($params) {
        $arr = [];
        if (!empty($params)) {
            foreach ($params as $param) {
                if (!isset($param->attribute)) {
                    continue;
                }
                $arr[$param->attribute] = isset($param->value) ? $this->decodeMime($param->value) : null;
            }
        }
        return $arr;
    }

    protected function decodeMime($text) {
        $list = imap_mime_header_decode($text);
        $result = '';
        foreach ($list as $data) {
            $charset = isset($data->charset) ? $data->charset : 'default';
            $result.= mb_convert_encoding($data->text, $this->_message->getImap()->serverCharset, $charset == 'default' ? self::DEFAULT_IMAP_CHARSET : $charset);
        }
        return $result;
    }

    public function getParameters($param = null) {
        if (!isset($this->_parameters)) {
            $val = $this->parseParams($this->getProperty(__METHOD__));
            $this->_parameters = &$val;
        }
        if ($param !== null) {
            if (isset($this->_parameters[$param])) {
                return $this->_parameters[$param];
            }
            return null;
        }
        return $this->_parameters;
    }

    public function getDparameters($param = null) {
        if (!isset($this->_dparameters)) {
            $val = $this->parseParams($this->getProperty(__METHOD__));
            $this->_dparameters = &$val;
        }
        if ($param !== null) {
            if (isset($this->_dparameters[$param])) {
                return $this->_dparameters[$param];
            }
            return null;
        }
        return $this->_dparameters;
    }

}
