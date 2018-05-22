<?php

namespace lilicbush\imap;

/**
 * Attachment
 *
 * 
 * @property-read string $mimetype
 * @property-read string $filename
 * @property-read integer $size
 * @property-read string $title
 * @property-read boolean $isAttachment
 */
class ImapFile extends MessagePart {

    public function getMimetype() {
        return strtolower(static::$types[$this->getType()] . '/' . $this->getSubtype());
    }
    
    public function getFilename(){

        $filename = $this->getDparameters('filename');
        $filetitle = $this->getTitle();

        return $filename === null ? (($filetitle === null ? time() : $filetitle) . '.' . $this->getSubtype()) : $filename;
    }
    
    public function getSize() {
        return $this->getBytes();
    }
    
    public function getTitle() {
        return $this->getParameters('name');
    }
    
    public function getIsAttachment() {
        return $this->getDisposition() === 'attachment';
    }

}
