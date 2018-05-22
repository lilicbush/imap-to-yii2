<?php


namespace lilicbush\imap;

/**
 * Description of ImapMessage
 *
 * @property-read ImapAgent $imap ImapAgent Object
 *
 * @property-read integer $type Return type of message
 * @property-read string $body Return body of message in "html" format. If you want to get result in other format - use <code>getBody($type);</code>
 * @property-read string $date When was it sent
 * @property-read string $udate When was it sent in unix timestamp
 * @property-read string $subject the mails subject
 * @property-read string $from who sent it
 * @property-read string $to recipient
 * @property-read string $id Mail-ID
 * @property-read string $references is a reference to this mail id
 * @property-read string $in_reply_to is a reply to this mail id
 * @property-read integer $size size in bytes
 * @property-read integer $uid UID the mail has in the mailbox
 * @property-read integer $msgno mail sequence number in the mailbox
 * @property-read boolean $recent this mail is flagged as recent
 * @property-read boolean $flagged this mail is flagged
 * @property-read boolean $answered this mail is flagged as answered
 * @property-read boolean $deleted this mail is flagged for deletion
 * @property-read boolean $seen this mail is flagged as already read
 * @property-read boolean $draft this mail is flagged as being a draft
 * @property-read \stdClass $headerInfo Header of the message
 *
 * @property-read ImapFile[] $attachments Attachments of message.
 * @property-read ImapFile[] $content Message content as ImapAttachment
 *
 */
class ImapMessage extends MessagePart
{

    protected $_imap;
    protected $_uid;
    protected $_overview;
    protected $_headerInfo;
    protected $_subject;
    protected $_body;
    protected $_attachments;
    protected $_content;

    public function __construct(ImapAgent $imap, &$overview, $config = [])
    {
        $this->_imap = $imap;
        if ($overview instanceof \stdClass) {
            $this->_uid = $overview->uid;
            $this->_overview = $overview;
        } else {
            $this->_uid = $overview;
        }
        parent::__construct($this);
    }

    /**
     * Fetch mail overview
     *
     * Returns object describing mail header. The object will only define a property if it exists. The possible properties are:
     * <ul>
     *  <li>subject - the mails subject</li>
     *  <li>from - who sent it</li>
     *  <li>to - recipient</li>
     *  <li>date - when was it sent</li>
     *  <li>message_id - Mail-ID</li>
     *  <li>references - is a reference to this mail id</li>
     *  <li>in_reply_to - is a reply to this mail id</li>
     *  <li>size - size in bytes</li>
     *  <li>uid - UID the mail has in the mailbox</li>
     *  <li>msgno - mail sequence number in the mailbox</li>
     *  <li>recent - this mail is flagged as recent</li>
     *  <li>flagged - this mail is flagged</li>
     *  <li>answered - this mail is flagged as answered</li>
     *  <li>deleted - this mail is flagged for deletion</li>
     *  <li>seen - this mail is flagged as already read</li>
     *  <li>draft - this mail is flagged as being a draft</li>
     * </ul>
     * @return \stdClass Message header object
     */
    protected function getOverview()
    {
        if ($this->_overview === null) {
            $overviews = imap_fetch_overview($this->getImap()->getStream(), $this->getUid(), FT_UID);
            $this->_overview = array_shift($overviews);
        }
        return $this->_overview;
    }

    public function getHeaderInfo()
    {
        if ($this->_headerInfo === null) {
            $this->_headerInfo = imap_headerinfo($this->getImap()->getStream(), $this->getMsgno());
        }
        return $this->_headerInfo;
    }

    public function getSubject()
    {
        if ($this->_subject === null) {
            $this->_subject = $this->decodeMime(isset($this->getOverview()->subject) ? $this->getOverview()->subject : '');
        }
        return $this->_subject;
    }

    /**
     *
     * @param MessagePart $part
     */
    protected function fetchBody($part = null, $force = false)
    {
        if ($part === null) {
            if (!$force && $this->_body !== null) {
                return;
            }
            $part = $this;
            $this->_attachments = null;
            $this->_content = null;
        }
        if ($part->getType() === self::TYPE_MULTIPART) {
            foreach ($part->getParts() as $subpart) {
                if ($subpart instanceof ImapFile) {
                    if ($subpart->isAttachment) {
                        $this->_attachments[] = $subpart;
                    } else {
                        $this->_content[] = $subpart;
                    }
                    continue;
                }
                switch ($subpart->getType()) {
                    case self::TYPE_TEXT:
                        $this->_body[strtolower($subpart->subtype)][] = $subpart;
                        break;
                    case self::TYPE_MULTIPART:
                        $this->fetchBody($subpart);
                        break;
                    default :

                        break;
                }
            }
        } else {
            $this->_body[strtolower($this->subtype)][] = $this;
        }
    }

    /**
     *
     * @param string $type May be "html" or "plain", if required format not found - function returns plain text
     * @return string
     */
    public function getBody($type = 'html')
    {
        $this->fetchBody();
        if (empty($this->_body)) {
            return null;
        }
        if (isset($this->_body[strtolower($type)])) {
            return $this->implodeBody('', $this->_body[strtolower($type)]);
        }
        $result = $this->implodeBody("\r\n<br/>", $this->_body);
        if (strtolower($type) === 'html') {
            $result = str_replace(["\r\n", "\n"], '<br/>', $result);
        }
        return $result;
    }

    protected function implodeBody($glue = '', $body)
    {
        if (is_array($body)) {
            $result = [];
            foreach ($body as $part) {
                $result[] = $this->implodeBody($glue, $part);
            }
            return implode($glue, $result);
        } else if ($body instanceof MessagePart) {
            $charset = $body->getParameters('charset');
            return ($charset === null || $charset === $this->getImap()->serverCharset) ? $body->getData() : mb_convert_encoding($body->getData(), $this->getImap()->serverCharset, $charset);
        }
        return $body;
    }

    public function getFrom()
    {
        return $this->decodeMime($this->getOverview()->from);
    }

    public function getTo()
    {
        return $this->decodeMime($this->getOverview()->to);
    }

    /**
     *
     * @return ImapFile[]
     */
    public function getContent()
    {
        $this->fetchBody();
        return $this->_content !== null ? $this->_content : [];
    }

    /**
     *
     * @return ImapFile[]
     */
    public function getAttachments()
    {
        $this->fetchBody();
        return $this->_attachments !== null ? $this->_attachments : [];
    }

    public function delete()
    {
        imap_delete($this->getImap()->getStream(), $this->getUid(), FT_UID);
    }

    public function getImap()
    {
        return $this->_imap;
    }

    public function getUid()
    {
        return $this->_uid;
    }

    public function getId()
    {
        return $this->getOverview()->message_id;
    }

    public function getDate()
    {
        return $this->getOverview()->date;
    }

    public function getUdate()
    {
        return $this->getOverview()->udate;
    }

    public function getSeen()
    {
        return $this->getOverview()->seen;
    }

    public function getRecent()
    {
        return $this->getOverview()->recent;
    }

    public function getFlagged()
    {
        return $this->getOverview()->flagged;
    }

    public function getAnswered()
    {
        return $this->getOverview()->answered;
    }

    public function getDeleted()
    {
        return $this->getOverview()->deleted;
    }

    public function getDraft()
    {
        return $this->getOverview()->draft;
    }

    public function getReferences()
    {
        return $this->getOverview()->references;
    }

    public function getIn_reply_to()
    {
        return $this->getOverview()->in_reply_to;
    }

    public function getSize()
    {
        return $this->getOverview()->size;
    }

    public function getMsgno()
    {
        return $this->getOverview()->msgno;
    }


    public function move($folder)
    {
        imap_mail_move($this->getImap()->getStream(), $this->getUid(), $folder);
        imap_expunge($this->getImap()->getStream());

        //imap_delete($this->getImap()->getStream(), $this->getUid(), FT_UID);
    }

}
