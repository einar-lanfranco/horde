<?php
/**
 * The IMP_Compose:: class contains functions related to generating
 * outgoing mail messages.
 *
 * $Horde: imp/lib/Compose.php,v 1.402 2008/11/09 07:38:45 slusarz Exp $
 *
 * Copyright 2002-2008 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package IMP
 */
class IMP_Compose
{
    /* The virtual path to use for VFS data. */
    const VFS_ATTACH_PATH = '.horde/imp/compose';

    /* The virtual path to save linked attachments. */
    const VFS_LINK_ATTACH_PATH = '.horde/imp/attachments';

    /* The virtual path to save drafts. */
    const VFS_DRAFTS_PATH = '.horde/imp/drafts';

    /**
     * The cached attachment data.
     *
     * @var array
     */
    protected $_cache = array();

    /**
     * For findBody, the MIME ID of the "body" part.
     *
     * @var string
     */
    protected $_mimeid = null;

    /**
     * The "cached" charset of the body MIME part.
     *
     * @var string
     */
    protected $_bodyCharset;

    /**
     * The aggregate size of all attachments (in bytes).
     *
     * @var integer
     */
    protected $_size = 0;

    /**
     * Whether the user's PGP public key should be attached to outgoing
     * messages.
     *
     * @var boolean
     */
    protected $_pgpAttachPubkey = false;

    /**
     * Whether the user's vCard should be attached to outgoing messages.
     *
     * @var boolean
     */
    protected $_attachVCard = false;

    /**
     * Whether attachments should be linked.
     *
     * @var boolean
     */
    protected $_linkAttach = false;

    /**
     * The UID of the last draft saved via saveDraft().
     *
     * @var integer
     */
    protected $_draftIdx;

    /**
     * In findBody(), indicate we want to return a text/html part.
     *
     * @var boolean
     */
    protected $_findhtml = false;

    /**
     * Internal ID for attachments.
     *
     * @var integer
     */
    protected $_atcid = 0;

    /**
     * The cache ID used to store object in session.
     *
     * @var string
     */
    protected $_cacheid;

    /**
     * Are we resuming a message?
     *
     * @var boolean
     */
    protected $_resume = false;

    /**
     * Attempts to return a reference to a concrete IMP_Compose instance.
     *
     * If a IMP_Cacheid object exists with the given cacheid, recreate that
     * that object.  Else, create a new instance.
     *
     * This method must be invoked as:<pre>
     *   $imp_compose = &IMP_Compose::singleton([$cacheid]);
     * </pre>
     *
     * @param string $cacheid  The cache ID string.
     *
     * @return IMP_Compose  The IMP_Compose object or null.
     */
    static public function &singleton($cacheid = null)
    {
        static $instance = array();

        if (!is_null($cacheid)) {
            if (!isset($instance[$cacheid])) {
                $cacheSess = &Horde_SessionObjects::singleton();
                $instance[$cacheid] = $cacheSess->query($cacheid);
                if (!empty($instance[$cacheid])) {
                    $cacheSess->setPruneFlag($cacheid, true);
                }
            }
        }

        if (is_null($cacheid) || empty($instance[$cacheid])) {
            $cacheid = uniqid(mt_rand());
            $instance[$cacheid] = new IMP_Compose();
        }

        $instance[$cacheid]->_cacheid = $cacheid;

        return $instance[$cacheid];
    }

    /**
     * Store a serialized version of ourself in the current session on
     * shutdown.
     */
    function __destruct()
    {
        if (!empty($this->_atcid)) {
            $cacheSess = &Horde_SessionObjects::singleton();
            $cacheSess->overwrite($this->_cacheid, $this, false);
        }
    }

    /**
     * Saves a message to the draft folder.
     *
     * @param array $header    List of message headers.
     * @param mixed $message   Either the message text (string) or a
     *                         Horde_Mime_Message object that contains the
     *                         text to send.
     * @param string $charset  The charset that was used for the headers.
     * @param boolean $html    Whether this is an HTML message.
     *
     * @return mixed  Notification text on success, PEAR_Error on error.
     */
    public function saveDraft($headers, $message, $charset, $html)
    {
        $drafts_folder = IMP::folderPref($GLOBALS['prefs']->getValue('drafts_folder'), true);
        if (empty($drafts_folder)) {
            return PEAR::raiseError(_("Saving the draft failed. No draft folder specified."));
        }

        $body = $this->_saveDraftMsg($headers, $message, $charset, $html, true);
        if (is_a($body, 'PEAR_Error')) {
            return $body;
        }

        return $this->_saveDraftServer($body, $drafts_folder);
    }

    /**
     * Prepare the draft message.
     *
     * @param array $headers    List of message headers.
     * @param mixed $message    Either the message text (string) or a
     *                          Horde_Mime_Message object that contains the
     *                          text to send.
     * @param string $charset   The charset that was used for the headers.
     * @param boolean $html     Whether this is an HTML message.
     * @param boolean $session  Do we have an active session?
     *
     * @return mixed  PEAR_Error on error, the body text on success.
     */
    protected function _saveDraftMsg($headers, $message, $charset, $html,
                                     $session)
    {
        $mime = new Horde_Mime_Message();

        /* Set up the base message now. */
        $body = $this->getMessageBody($message, $charset, $html, false, !$session);
        if (is_a($body, 'PEAR_Error')) {
            return $body;
        }

        $mime->addPart($body);
        $body = $mime->toString();

        /* Initalize a header object for the draft. */
        $draft_headers = new Horde_Mime_Headers();

        $draft_headers->addHeader('Date', date('r'));
        if (!empty($headers['from'])) {
            $draft_headers->addHeader('From', Horde_Mime::encode($headers['from'], $charset));
        }
        foreach (array('to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc') as $k => $v) {
            if (!empty($headers[$k])) {
                $addr = $headers[$k];
                if ($session) {
                    $addr = Horde_Mime::encodeAddress($this->formatAddr($addr), $charset, $_SESSION['imp']['maildomain']);
                    if (is_a($addr, 'PEAR_Error')) {
                        return PEAR::raiseError(sprintf(_("Saving the draft failed. The %s header contains an invalid e-mail address: %s."), $k, $addr->getMessage()));
                    }
                }
                $draft_headers->addHeader($v, $addr);
            }
        }

        if (!empty($headers['subject'])) {
            $draft_headers->addHeader('Subject', Horde_Mime::encode($headers['subject'], $charset));
        }

        if (isset($mime)) {
            $draft_headers = $mime->addMIMEHeaders($draft_headers);
        }

        /* Need to add Message-ID so we can use it in the index search. */
        $draft_headers->addMessageIdHeader();

        return $draft_headers->toString() . $body;
    }

    /**
     * Save a draft message on the IMAP server.
     *
     * @param string $data         The text of the draft message.
     * @param string $drafts_mbox  The mailbox to save the message to
     *                             (UTF7-IMAP).
     * @return string  Status string.
     */
    protected function _saveDraftServer($data, $drafts_mbox)
    {
        $imp_folder = &IMP_Folder::singleton();

        /* Check for access to drafts folder. */
        if (!$imp_folder->exists($drafts_mbox) &&
            !$imp_folder->create($drafts_mbox, $GLOBALS['prefs']->getValue('subscribe'))) {
            return PEAR::raiseError(_("Saving the draft failed. Could not create a drafts folder."));
        }

        $append_flags = array('\\draft');
        if (!$GLOBALS['prefs']->getValue('unseen_drafts')) {
            $append_flags[] = '\\seen';
        }

        /* Get the message ID. */
        $headers = Horde_Mime_Headers::parseHeaders($data);

        /* Add the message to the mailbox. */
        try {
            $ids = $GLOBALS['imp_imap']->ob->append($drafts_mbox, array(array('data' => $data, 'flags' => $append_flags, 'messageid' => $headers->getValue('message-id'))));
            $this->_draftIdx = reset($ids);
            return sprintf(_("The draft has been saved to the \"%s\" folder."), IMP::displayFolder($drafts_mbox));
        } catch (Horde_Imap_Client_Exception $e) {
            $GLOBALS['imp_imap']->logException($e);
            return _("The draft was not successfully saved.");
        }
    }

    /**
     * Returns the UID of the last message saved via saveDraft().
     *
     * @return integer  An IMAP UID.
     */
    public function saveDraftIndex()
    {
        return $this->_draftIdx;
    }

    /**
     * Resumes a previously saved draft message.
     *
     * @param string $index  The IMAP message mailbox/index. The index should
     *                       be in IMP::parseIndicesList() format #1.
     *
     * @return array  PEAR_Error on error, or an array with the following keys:
     * <pre>
     * 'msg' -- The message text.
     * 'mode' -- 'html' or 'text'.
     * 'header' -- A list of headers to add to the outgoing message.
     * 'identity' -- The identity used to create the message.
     * </pre>
     */
    public function resumeDraft($index)
    {
        if (!$index) {
            return PEAR::raiseError(_("Invalid message, cannot resume draft."));
        }

        $imp_contents = &IMP_Contents::singleton($index);
        if (is_a($imp_contents, 'PEAR_Error')) {
            return $imp_contents;
        }

        $this->_resume = true;

        $alt_part = $body_part = null;
        $mode = 'text';
        $mime_message = $imp_contents->rebuildMessage();

        // Search for multipart/alternative parts at either ID 0 or 1
        $type_map = $mime_message->contentTypeMap();
        $alt_key = array_search('multipart/alternative', $type_map);
        if ($alt_key === 0 || $alt_key === 1) {
            $alt_part = $mime_message->getPart($alt_key);
        }

        if (!empty($alt_part) && $GLOBALS['browser']->hasFeature('rte')) {
            $html_key = array_search('text/html', $alt_part->contentTypeMap());
            if ($html_key !== false) {
                $body_part = $alt_part->getPart($html_key);
                $message = String::convertCharset($body_part->transferDecode(), $body_part->getCharset());
                $mode = 'html';
            }
        }

        if ($body_part === null) {
            // _rebuildMsgText() does necessary charset conversion.
            $message = $this->_rebuildMsgText($imp_contents);
            $body_part = $mime_message->getPart($this->_mimeid);
        }

        $result = $this->attachFilesFromMessage($imp_contents);
        if (!empty($result)) {
            foreach ($result as $val) {
                $GLOBALS['notification']->push($val, 'horde.error');
            }
        }

        // Remove any other multipart/alternative parts that have been added
        // as attachments.
        if (!empty($alt_part)) {
            $alt_id = $body_part->getInformation('alternative');
            if ($body_part->getInformation('alternative') == $alt_key) {
                $alt_map_ids = array_keys($alt_part->contentTypeMap());
                foreach ($this->getAttachments() as $key => $val) {
                    if (in_array($val->getMIMEId(), $alt_map_ids)) {
                        $this->deleteAttachment($key);
                    }
                }
            }
        }

        if (($mode == 'html') && ($body_part->getType() != 'text/html')) {
            $message = $this->text2html($message);
        }

        $identity_id = null;
        $imp_headers = $imp_contents->getHeaderOb();
        if (($fromaddr = $imp_headers->getValue('from'))) {
            require_once 'Horde/Identity.php';
            $identity = &Identity::singleton(array('imp', 'imp'));
            $identity_id = $identity->getMatchingIdentity($fromaddr);
        }

        $header = array(
            'to' => Horde_Mime_Address::addrArray2String($imp_headers->getOb('to')),
            'cc' => Horde_Mime_Address::addrArray2String($imp_headers->getOb('cc')),
            'bcc' => Horde_Mime_Address::addrArray2String($imp_headers->getOb('bcc')),
            'subject' => $imp_headers->getValue('subject')
        );

        list($this->_draftIdx,) = explode(IMP::IDX_SEP, $index);

        $this->_resume = false;

        return array('msg' => $message, 'mode' => $mode, 'header' => $header, 'identity' => $identity_id);
    }

    /**
     * Gets the message body and sets up the MIME parts.
     *
     * @param string $message    The raw message body.
     * @param string $charset    The charset to use.
     * @param boolean $html      Whether this is an HTML message.
     * @param string $final_msg  Whether this is a message which will be
     *                           sent out.
     * @param boolean $noattach  Don't add attachment information.
     *
     * @return Horde_Mime_Part  The body as a MIME object, or PEAR_Error on
     *                          error.
     */
    public function getMessageBody($message, $charset, $html,
                                   $final_msg = true, $noattach = false)
    {
        $message = String::convertCharset($message, NLS::getCharset(), $charset);

        if ($html) {
            $message_html = $message;
            require_once 'Horde/Text/Filter.php';
            $message = Text_Filter::filter($message, 'html2text', array('wrap' => false, 'charset' => $charset));
        }

        /* Get trailer message (if any). */
        $trailer = null;
        if ($final_msg && $GLOBALS['conf']['msg']['append_trailer']) {
            $trailer_file = null;
            if (empty($GLOBALS['conf']['vhosts'])) {
                if (is_readable(IMP_BASE . '/config/trailer.txt')) {
                    $trailer_file = IMP_BASE . '/config/trailer.txt';
                }
            } elseif (is_readable(IMP_BASE . '/config/trailer-'
                                  . $GLOBALS['conf']['server']['name'] . '.txt')) {
                $trailer_file = IMP_BASE . '/config/trailer-'
                    . $GLOBALS['conf']['server']['name'] . '.txt';
            }

            if (!empty($trailer_file)) {
                require_once 'Horde/Text/Filter.php';
                $trailer = Text_Filter::filter(
                    "\n" . file_get_contents($trailer_file), 'environment');
                /* If there is a user defined function, call it with the
                 * current trailer as an argument. */
                if (!empty($GLOBALS['conf']['hooks']['trailer'])) {
                    $trailer = Horde::callHook('_imp_hook_trailer',
                                               array($trailer), 'imp');
                }
            }
        }

        /* Set up the body part now. */
        $textBody = new Horde_Mime_Part('text/plain');
        $textBody->setContents($textBody->replaceEOL($message));
        $textBody->setCharset($charset);
        if ($trailer !== null) {
            $textBody->appendContents($trailer);
        }

        /* Determine whether or not to send a multipart/alternative
         * message with an HTML part. */
        if ($html) {
            $htmlBody = new Horde_Mime_Part('text/html', $message_html, null, 'inline');
            if ($trailer !== null) {
                $htmlBody->appendContents($this->text2html($trailer));
            }
            /* Run tidy on the HTML, if available. */
            if ($tidy_config = IMP::getTidyConfig($htmlBody->getBytes())) {
                $tidy = tidy_parse_string(String::convertCharset($htmlBody->getContents(), $charset, 'UTF-8'), $tidy_config, 'utf8');
                $tidy->cleanRepair();
                $htmlBody->setContents(String::convertCharset(tidy_get_output($tidy), 'UTF-8', $charset));
            }

            $basepart = new Horde_Mime_Part('multipart/alternative');
            $textBody->setDescription(String::convertCharset(_("Plaintext Version of Message"), NLS::getCharset(), $charset));
            $basepart->addPart($textBody);
            $htmlBody->setCharset($charset);
            $htmlBody->setDescription(String::convertCharset(_("HTML Version of Message"), NLS::getCharset(), $charset));

            if ($final_msg) {
                /* Any image links will be downloaded and appended to the
                 * message body. */
                $htmlBody = $this->convertToMultipartRelated($htmlBody);
            }
            $basepart->addPart($htmlBody);
        } else {
            /* Send in flowed format. */
            require_once 'Text/Flowed.php';
            $flowed = new Text_Flowed($textBody->getContents(), $charset);
            $flowed->setDelSp(true);
            $textBody->setContentTypeParameter('DelSp', 'Yes');
            $textBody->setContents($flowed->toFlowed());
            $textBody->setContentTypeParameter('format', 'flowed');
            $basepart = $textBody;
        }

        /* Instantiate IMP_PGP object if we're appending a PGP signature. */
        if ($this->_pgpAttachPubkey) {
            require_once IMP_BASE . '/lib/Crypt/PGP.php';
            $imp_pgp = new IMP_PGP();
        }

        /* Add attachments now. */
        if (is_a($this->_attachVCard, 'PEAR_Error')) {
            return $this->_attachVCard;
        }
        if (!$noattach && $this->numberOfAttachments()) {
            if (($this->_linkAttach &&
                 $GLOBALS['conf']['compose']['link_attachments']) ||
                !empty($GLOBALS['conf']['compose']['link_all_attachments'])) {
                $body = $this->linkAttachments(Horde::applicationUrl('attachment.php', true), $basepart, Auth::getAuth());
                if (is_a($body, 'PEAR_Error')) {
                    return $body;
                }

                if ($this->_pgpAttachPubkey || $this->_attachVCard) {
                    $new_body = new Horde_Mime_Part('multipart/mixed');
                    $new_body->addPart($body);
                    $new_body->addPart($imp_pgp->publicKeyMIMEPart());
                    if ($this->_pgpAttachPubkey) {
                        $new_body->addPart($imp_pgp->publicKeyMIMEPart());
                    }
                    if ($this->_attachVCard) {
                        $new_body->addPart($this->_attachVCard);
                    }
                    $body = $new_body;
                }
            } else {
                $body = new Horde_Mime_Part('multipart/mixed');
                $body->addPart($basepart);
                foreach ($this->getAttachments() as $part) {
                    /* Store the data inside the current part. */
                    $this->_buildPartData($part);

                    /* Add to the base part. */
                    $body->addPart($part);
                }

                if ($this->_pgpAttachPubkey) {
                    $body->addPart($imp_pgp->publicKeyMIMEPart());
                }
                if ($this->_attachVCard) {
                    $body->addPart($this->_attachVCard);
                }
            }
        } elseif ($this->_pgpAttachPubkey || $this->_attachVCard) {
            $body = new Horde_Mime_Part('multipart/mixed');
            $body->addPart($basepart);
            if ($this->_pgpAttachPubkey) {
                $body->addPart($imp_pgp->publicKeyMIMEPart());
            }
            if ($this->_attachVCard) {
                $body->addPart($this->_attachVCard);
            }
        } else {
            $body = $basepart;
        }

        return $body;
    }

    /**
     * Builds and sends a MIME message.
     *
     * @param string $message  The message body.
     * @param array $header    List of message headers.
     * @param string $charset  The sending charset.
     * @param boolean $html    Whether this is an HTML message.
     * @param array $opts      An array of options w/the following keys:
     * <pre>
     * 'save_sent' = (bool) Save sent mail?
     * 'sent_folder' = (string) The sent-mail folder (UTF7-IMAP).
     * 'save_attachments' = (bool) Save attachments with the message?
     * 'reply_type' = (string) What kind of reply this is (reply or forward).
     * 'reply_index' = (string) The IMAP message mailbox/index of the message
     *                 we are replying to. The index should be in
     *                 IMP::parseIndicesList() format #1.
     * 'encrypt' => (integer) A flag whether to encrypt or sign the message.
     *              One of IMP::PGP_ENCRYPT, IMP::PGP_SIGNENC,
     *              IMP::SMIME_ENCRYPT, or IMP::SMIME_SIGNENC.
     * 'priority' => (integer) The message priority from 1 to 5.
     * 'readreceipt' => (bool) Add return receipt headers?
     * 'useragent' => (string) The User-Agent string to use.
     * </pre>
     *
     * @return boolean  Whether the sent message has been saved in the
     *                  sent-mail folder, or a PEAR_Error on failure.
     */
    public function buildAndSendMessage($message, $header, $charset, $html,
                                 $opts = array())
    {
        global $conf, $notification, $prefs, $registry;

        /* We need at least one recipient & RFC 2822 requires that no 8-bit
         * characters can be in the address fields. */
        $recip = $this->recipientList($header);
        if (is_a($recip, 'PEAR_Error')) {
            return $recip;
        }
        $header = array_merge($header, $recip['header']);

        $barefrom = Horde_IMAP_Address::bareAddress($header['from'], $_SESSION['imp']['maildomain']);
        $recipients = implode(', ', $recip['list']);

        /* Set up the base message now. */
        $body = $this->getMessageBody($message, $charset, $html);
        if (is_a($body, 'PEAR_Error')) {
            return $body;
        }

        $encrypt = empty($opts['encrypt']) ? 0 : $opts['encrypt'];

        /* Prepare the array of messages to send out.  May be more
         * than one if we are encrypting for multiple recipients or
         * are storing an encrypted message locally. */
        $messagesToSend = array();

        /* Do encryption. */
        if (!empty($encrypt) &&
            $prefs->getValue('use_pgp') &&
            !empty($conf['utils']['gnupg']) &&
            in_array($encrypt, array(IMP::PGP_ENCRYPT, IMP::PGP_SIGNENC, IMP::PGP_SYM_ENCRYPT, IMP::PGP_SYM_SIGNENC))) {
            require_once IMP_BASE .'/lib/Crypt/PGP.php';
            $imp_pgp = new IMP_PGP();
        }

        if (!empty($encrypt) &&
            $prefs->getValue('use_smime') &&
            in_array($encrypt, array(IMP::SMIME_ENCRYPT, IMP::SMIME_SIGNENC))) {
            /* Must encrypt & send the message one recipient at a time. */
            foreach ($recip['list'] as $val) {
                $res = $this->_createMimeMessage(array($val), $body, $encrypt);
                if (is_a($res, 'PEAR_Error')) {
                    return $res;
                }
                $messagesToSend[] = $res;
            }

            /* Must target the encryption for the sender before saving message
             * in sent-mail. */
            $messageToSave = $this->_createMimeMessage(array($header['from']), $body, $encrypt);
            if (is_a($messageToSave, 'PEAR_Error')) {
                return $messageToSave;
            }
        } else {
            /* Can send in clear-text all at once, or PGP can encrypt
             * multiple addresses in the same message. */
            $res = $this->_createMimeMessage($recip['list'], $body, $encrypt, $barefrom);
            if (is_a($res, 'PEAR_Error')) {
                return $res;
            }
            $messagesToSend[] = $messageToSave = $res;
        }

        /* Initalize a header object for the outgoing message. */
        $msg_headers = new Horde_Mime_Headers();
        if (empty($opts['useragent'])) {
            require_once IMP_BASE . '/lib/version.php';
            $msg_headers->setUserAgent('Internet Messaging Program (IMP) ' . IMP_VERSION);
        } else {
            $msg_headers->setUserAgent($opts['useragent']);
        }

        /* Add a Received header for the hop from browser to server. */
        $msg_headers->addReceivedHeader();
        $msg_headers->addMessageIdHeader();

        /* Add the X-Priority header, if requested. This appears here since
         * this is the "general" location that other mail clients insert this
         * header. */
        if (!empty($opts['priority'])) {
            $priority = $opts['priority'];
            switch ($priority) {
            case 1:
                $priority .= ' (Highest)';
                break;

            case 2:
                $priority .= ' (High)';
                break;

            case 3:
                $priority .= ' (Normal)';
                break;

            case 4:
                $priority .= ' (Low)';
                break;

            case 5:
                $priority .= ' (Lowest)';
                break;
            }
            $msg_headers->addHeader('X-Priority', $priority);
        }

        $msg_headers->addHeader('Date', date('r'));

        /* Add Return Receipt Headers. */
        if (!empty($opts['readreceipt']) &&
            $conf['compose']['allow_receipts']) {
            $mdn = new Horde_Mime_MDN();
            $mdn->addMDNRequestHeaders($msg_headers, $barefrom);
        }

        $browser_charset = NLS::getCharset();

        $msg_headers->addHeader('From', String::convertCharset($header['from'], $browser_charset, $charset));

        if (!empty($header['replyto']) &&
            ($header['replyto'] != $barefrom)) {
            $msg_headers->addHeader('Reply-to', String::convertCharset($header['replyto'], $browser_charset, $charset));
        }
        if (!empty($header['to'])) {
            $msg_headers->addHeader('To', String::convertCharset($header['to'], $browser_charset, $charset));
        } elseif (empty($header['to']) && empty($header['cc'])) {
            $msg_headers->addHeader('To', 'undisclosed-recipients:;');
        }
        if (!empty($header['cc'])) {
            $msg_headers->addHeader('Cc', String::convertCharset($header['cc'], $browser_charset, $charset));
        }
        $msg_headers->addHeader('Subject', String::convertCharset($header['subject'], $browser_charset, $charset));

        /* Add necessary headers for replies. */
        if (!empty($opts['reply_type']) && ($opts['reply_type'] == 'reply')) {
            if (!empty($header['references'])) {
                $msg_headers->addHeader('References', implode(' ', preg_split('|\s+|', trim($header['references']))));
            }
            if (!empty($header['in_reply_to'])) {
                $msg_headers->addHeader('In-Reply-To', $header['in_reply_to']);
            }
        }

        /* Send the messages out now. */
        foreach ($messagesToSend as $val) {
            $headers = &Util::cloneObject($msg_headers);
            $headers->addMIMEHeaders($val['msg']);
            $res = $this->sendMessage($val['to'], $headers, $val['msg'], $charset);
            if (is_a($res, 'PEAR_Error')) {
                /* Unsuccessful send. */
                Horde::logMessage($res->getMessage(), __FILE__, __LINE__, PEAR_LOG_ERR);
                return PEAR::raiseError(sprintf(_("There was an error sending your message: %s"), $res->getMessage()));
            }

            /* Store history information. */
            if ($conf['sentmail']['driver'] != 'none') {
                $sentmail = IMP_Sentmail::factory();
                $sentmail->log(empty($opts['reply_type']) ? 'new' : $opts['reply_type'], $headers->getValue('message-id'), $val['recipients'], !is_a($res, 'PEAR_Error'));
            }
        }

        $sent_saved = true;

        /* Log the reply. */
        if (!empty($opts['reply_type']) && !empty($header['in_reply_to'])) {
            if (!empty($conf['maillog']['use_maillog'])) {
                IMP_Maillog::log($opts['reply_type'], $header['in_reply_to'], $recipients);
            }
        }

        if (!empty($opts['reply_index']) &&
            !empty($opts['reply_type']) &&
            ($opts['reply_type'] == 'reply')) {
            /* Make sure to set the IMAP reply flag and unset any
             * 'flagged' flag. */
            $imp_message = &IMP_Message::singleton();
            $idx_array = array($opts['reply_index']);
            $imp_message->flag(array('answered'), $idx_array);
            $imp_message->flag(array('flagged'), $idx_array, false);
        }

        $entry = sprintf("%s Message sent to %s from %s", $_SERVER['REMOTE_ADDR'], $recipients, $_SESSION['imp']['uniquser']);
        Horde::logMessage($entry, __FILE__, __LINE__, PEAR_LOG_INFO);

        /* Should we save this message in the sent mail folder? */
        if (!empty($opts['sent_folder']) &&
            ((!$prefs->isLocked('save_sent_mail') && !empty($opts['save_sent'])) ||
             ($prefs->isLocked('save_sent_mail') &&
              $prefs->getValue('save_sent_mail')))) {

            $mime_message = $messageToSave['msg'];
            $msg_headers = $mime_message->addMIMEHeaders($msg_headers);

            /* Keep Bcc: headers on saved messages. */
            if (!empty($header['bcc'])) {
                $msg_headers->addHeader('Bcc', $header['bcc']);
            }

            /* Loop through the envelope and add headers. */
            $headerArray = $mime_message->encode($msg_headers->toArray(), $charset);
            foreach ($headerArray as $key => $value) {
                $msg_headers->addHeader($key, $value);
            }
            $fcc = $msg_headers->toString();

            /* Strip attachments if requested. */
            $save_attach = $prefs->getValue('save_attachments');
            if ($save_attach == 'never' ||
                (strpos($save_attach, 'prompt') === 0 &&
                 empty($opts['save_attachments']))) {
                foreach (array_keys($this->getAttachments()) as $i) {
                    $i++;
                    $oldPart = $mime_message->getPart($i);
                    if ($oldPart !== false) {
                        $replace_part = new Horde_Mime_Part('text/plain');
                        $replace_part->setCharset($charset);
                        $replace_part->setContents('[' . _("Attachment stripped: Original attachment type") . ': "' . $oldPart->getType() . '", ' . _("name") . ': "' . $oldPart->getName(true, true) . '"]', '8bit');
                        $mime_message->alterPart($i, $replace_part);
                    }
                }
            }

            /* Add the body text to the message string. */
            $fcc .= $mime_message->toString();

            $imp_folder = &IMP_Folder::singleton();

            if (!$imp_folder->exists($opts['sent_folder'])) {
                $imp_folder->create($opts['sent_folder'], $prefs->getValue('subscribe'));
            }

            try {
                $GLOBALS['imp_imap']->ob->append(String::convertCharset($opts['sent_folder'], NLS::getCharset(), 'UTF-8'), array(array('data' => $fcc, 'flags' => array('\\seen'))));
            } catch (Horde_Imap_Client_Exception $e) {
                $GLOBALS['imp_imap']->logException($e);
                $notification->push(sprintf(_("Message sent successfully, but not saved to %s"), IMP::displayFolder($opts['sent_folder'])));
                $sent_saved = false;
            }
        }

        /* Delete the attachment data. */
        $this->deleteAllAttachments();

        /* Save recipients to address book? */
        $this->_saveRecipients($recipients);

        /* Call post-sent hook. */
        if (!empty($conf['hooks']['postsent'])) {
            Horde::callHook('_imp_hook_postsent',
                            array($messageToSave['msg'], $msg_headers),
                            'imp', null);
        }

        return $sent_saved;
    }

    /**
     * Sends a message.
     *
     * @param string $email          The e-mail list to send to.
     * @param IMP_Headers &$headers  The IMP_Headers object holding this
     *                               message's headers.
     * @param mixed &$message        Either the message text (string) or a
     *                               Horde_Mime_Message object that contains
     *                               the text to send.
     * @param string $charset        The charset that was used for the headers.
     *
     * @return mixed  True on success, PEAR_Error on error.
     */
    public function sendMessage($email, &$headers, &$message, $charset)
    {
        global $conf;

        /* Properly encode the addresses we're sending to. */
        $email = Horde_Mime::encodeAddress($email, null, $_SESSION['imp']['maildomain']);
        if (is_a($email, 'PEAR_Error')) {
            return $email;
        }

        /* Validate the recipient addresses. */
        $result = Horde_Mime_Address::parseAddressList($email, array('defserver' => $_SESSION['imp']['maildomain'], 'validate' => true));
        if (empty($result)) {
            return $result;
        }

        $timelimit = IMP::hasPermission('max_timelimit');
        if ($timelimit !== true) {
            if ($conf['sentmail']['driver'] == 'none') {
                Horde::logMessage('The permission for the maximum number of recipients per time period has been enabled, but no backend for the sent-mail logging has been configured for IMP.', __FILE__, __LINE__, PEAR_LOG_ERR);
                return PEAR::raiseError(_("The system is not properly configured. A detailed error description has been logged for the administrator."));
            }
            $sentmail = IMP_Sentmail::factory();
            $recipients = $sentmail->numberOfRecipients($conf['sentmail']['params']['limit_period'], true);
            foreach ($result as $address) {
                if (isset($address['groupname'])) {
                    $recipients += count($address['addresses']);
                } else {
                    ++$recipients;
                }
            }
            if ($recipients > $timelimit) {
                $message = @htmlspecialchars(sprintf(_("You are not allowed to send messages to more than %d recipients within %d hours."), $timelimit, $conf['sentmail']['params']['limit_period']), ENT_COMPAT, NLS::getCharset());
                if (!empty($conf['hooks']['permsdenied'])) {
                    $message = Horde::callHook('_perms_hook_denied', array('imp:max_timelimit'), 'horde', $message);
                }
                return PEAR::raiseError($message);
            }
        }

        /* Add the site headers. */
        $this->addSiteHeaders($headers);

        /* If $message is a string, we need to get a Horde_Mime_Message object
         * to encode the headers. */
        if (is_string($message)) {
            $mime_message = Horde_Mime_Message::parseMessage($message);
        }

        /* We don't actually want to alter the contents of the $conf['mailer']
         * array, so we make a copy of the current settings. We will apply our
         * modifications (if any) to the copy, instead. */
        $params = $conf['mailer']['params'];

        /* Force the SMTP host and port value to the current SMTP server if
         * one has been selected for this connection. */
        if (!empty($_SESSION['imp']['smtp'])) {
            $params = array_merge($params, $_SESSION['imp']['smtp']);
        }

        /* If SMTP authentication has been requested, use either the username
         * and password provided in the configuration or populate the username
         * and password fields based on the current values for the user. Note
         * that we assume that the username and password values from the
         * current IMAP / POP3 connection are valid for SMTP authentication as
         * well. */
        if (!empty($params['auth']) && empty($params['username'])) {
            $params['username'] = $_SESSION['imp']['user'];
            $params['password'] = Secret::read(IMP::getAuthKey(), $_SESSION['imp']['pass']);
        }

        return $mailer->send($email, $headerArray, $conf['mailer']['type'], $params);
    }

    /**
     * Adds any site-specific headers defined in config/header.php to the
     * header object.
     *
     * @param Horde_Mime_Headers $headers  The Horde_Mime_Headers object.
     */
    public function addSiteHeaders(&$headers)
    {
        /* Add the 'User-Agent' header. */
        $headers->addUserAgentHeader();

        /* Tack on any site-specific headers. */
        $result = Horde::loadConfiguration('header.php', '_header');
        if (is_a($result, 'PEAR_Error')) {
            $result = array();
        }

        foreach ($result as $key => $val) {
            $headers->addHeader(trim($key), trim($val));
        }
    }

    /**
     * Save the recipients done in a sendMessage().
     *
     * @param string $recipients  The list of recipients.
     */
    protected function _saveRecipients($recipients)
    {
        global $notification, $prefs, $registry;

        if (!$prefs->getValue('save_recipients') ||
            !$registry->hasMethod('contacts/import') ||
            !$registry->hasMethod('contacts/search')) {
            return;
        }

        $abook = $prefs->getValue('add_source');
        if (empty($abook)) {
            return;
        }

        $r_array = Horde_Mime::encodeAddress($recipients, null, $_SESSION['imp']['maildomain']);
        if (!is_a($r_array, 'PEAR_Error')) {
            $r_array = Horde_Mime_Address::parseAddressList($r_array, array('validate' => true));
        }
        if (empty($r_array)) {
            $notification->push(_("Could not save recipients."));
            return;
        }

        /* Filter out anyone that matches an email address already
         * in the address book. */
        $emails = array();
        foreach ($r_array as $recipient) {
            $emails[] = $recipient['mailbox'] . '@' . $recipient['host'];
        }
        $results = $registry->call('contacts/search', array($emails, array($abook), array($abook => array('email'))));

        foreach ($r_array as $recipient) {
            /* Skip email addresses that already exist in the add_source. */
            if (isset($results[$recipient['mailbox'] . '@' . $recipient['host']]) &&
                count($results[$recipient['mailbox'] . '@' . $recipient['host']])) {
                continue;
            }

            /* Remove surrounding quotes and make sure that $name
             * is non-empty. */
            $name = '';
            if (isset($recipient->personal)) {
                $name = trim($recipient->personal);
                if (preg_match('/^(["\']).*\1$/', $name)) {
                    $name = substr($name, 1, -1);
                }
            }
            if (empty($name)) {
                $name = $recipient->mailbox;
            }
            $name = Horde_Mime::decode($name);

            $result = $registry->call('contacts/import', array(array('name' => $name, 'email' => $recipient->mailbox . '@' . $recipient->host),
                                                               'array', $abook));
            if (is_a($result, 'PEAR_Error')) {
                if ($result->getCode() == 'horde.error') {
                    $notification->push($result, $result->getCode());
                }
            } else {
                $notification->push(sprintf(_("Entry \"%s\" was successfully added to the address book"), $name), 'horde.success');
            }
        }
    }

    /**
     * Cleans up and returns the recipient list. Encodes all e-mail addresses
     * with IDN domains.
     *
     * @param array $hdr       An array of MIME headers.  Recipients will be
     *                         extracted from the 'to', 'cc', and 'bcc'
     *                         entries.
     * @param boolean $exceed  Test if user has exceeded the allowed
     *                         number of recipients?
     *
     * @return array  PEAR_Error on error, or an array with the following
     *                entries:
     * <pre>
     * 'list' - An array of recipient addresses.
     * 'header' - An array containing the cleaned up 'to', 'cc', and 'bcc'
     *            header strings.
     * </pre>
     */
    public function recipientList($hdr, $exceed = true)
    {
        $addrlist = $header = array();

        foreach (array('to', 'cc', 'bcc') as $key) {
            if (!isset($hdr[$key])) {
                continue;
            }

            $arr = array_filter(array_map('trim', Horde_Mime_Address::explode($hdr[$key], ',;')));
            $tmp = array();

            foreach ($arr as $email) {
                if (empty($email)) {
                    continue;
                }

                $obs = Horde_Mime_Address::parseAddressList($email);
                if (empty($obs)) {
                    return PEAR::raiseError(sprintf(_("Invalid e-mail address: %s."), $email));
                }

                foreach ($obs as $ob) {
                    if (isset($ob['groupname'])) {
                        $group_addresses = array();
                        foreach ($ob['addresses'] as $ad) {
                            if (Horde_Mime::is8bit($ad['mailbox'])) {
                                return PEAR::raiseError(sprintf(_("Invalid character in e-mail address: %s."), $email));
                            }

                            // Make sure we have a valid host.
                            $host = trim($ad['host']);
                            if (empty($host)) {
                                $host = $_SESSION['imp']['maildomain'];
                            }

                            // Convert IDN hosts to ASCII.
                            if ($host == '.SYNTAX-ERROR.') {
                                return PEAR::raiseError(_("Invalid hostname."));
                            } elseif (Util::extensionExists('idn')) {
                                $host = idn_to_ascii(String::convertCharset($host, NLS::getCharset(), 'UTF-8'));
                            } elseif (Horde_Mime::is8bit($ad['mailbox'])) {
                                return PEAR::raiseError(sprintf(_("Invalid character in e-mail address: %s."), $email));
                            }

                            $group_addresses[] = Horde_Mime_Address::writeAddress($ad['mailbox'], $host, isset($ad['personal']) ? $ad['personal'] : '');
                        }

                        // Add individual addresses to the recipient list.
                        $addrlist = array_merge($addrlist, $group_addresses);

                        $tmp[] = Horde_Mime_Address::writeGroupAddress($ob['groupname'], $group_addresses) . ' ';
                    } else {
                        if (Horde_Mime::is8bit($ob['mailbox'])) {
                            return PEAR::raiseError(sprintf(_("Invalid character in e-mail address: %s."), $email));
                        }

                        // Make sure we have a valid host.
                        $host = trim($ob['host']);
                        if (empty($host)) {
                            $host = $_SESSION['imp']['maildomain'];
                        }

                        // Convert IDN hosts to ASCII.
                        if ($host == '.SYNTAX-ERROR.') {
                            return PEAR::raiseError(_("Invalid hostname."));
                        } elseif (Util::extensionExists('idn')) {
                            $host = idn_to_ascii(String::convertCharset($host, NLS::getCharset(), 'UTF-8'));
                        } elseif (Horde_Mime::is8bit($ob['mailbox'])) {
                            return PEAR::raiseError(sprintf(_("Invalid character in e-mail address: %s."), $email));
                        }

                        $addrlist[] = Horde_Mime::writeAddress($ob['mailbox'], $host, isset($ob['personal']) ? $ob['personal'] : '');
                        $tmp[] = end($addrlist) . ', ';
                    }
                }
            }

            $header[$key] = rtrim(implode('', $tmp), ' ,');
        }

        if (empty($addrlist)) {
            return PEAR::raiseError(_("You must enter at least one recipient."));
        }

        /* Count recipients if necessary. We need to split email groups
         * because the group members count as separate recipients. */
        if ($exceed) {
            $max_recipients = IMP::hasPermission('max_recipients');
            if ($max_recipients !== true) {
                $num_recipients = 0;
                foreach ($addrlist as $recipient) {
                    $num_recipients += count(explode(',', $recipient));
                }
                if ($num_recipients > $max_recipients) {
                    $message = @htmlspecialchars(sprintf(_("You are not allowed to send messages to more than %d recipients."), $max_recipients), ENT_COMPAT, NLS::getCharset());
                    if (!empty($conf['hooks']['permsdenied'])) {
                        $message = Horde::callHook('_perms_hook_denied', array('imp:max_recipients'), 'horde', $message);
                    }
                    return PEAR::raiseError($message);
                }
            }
        }

        return array('list' => $addrlist, 'header' => $header);
    }

    /**
     * Create the base Horde_Mime_Message for sending.
     *
     * @param array $to         The recipient list.
     * @param string $body      Message body.
     * @param integer $encrypt  The encryption flag.
     * @param string $from      The outgoing from address - only define if
     *                          using multiple PGP encryption.
     *
     * @return mixed  Array containing MIME message and recipients or
     *                PEAR_Error on error.
     */
    protected function _createMimeMessage($to, $body, $encrypt, $from = null)
    {
        $mime_message = new Horde_Mime_Message();

        $usePGP = ($GLOBALS['prefs']->getValue('use_pgp') &&
                   !empty($GLOBALS['conf']['utils']['gnupg']));
        $useSMIME = $GLOBALS['prefs']->getValue('use_smime');

        /* Set up the base message now. */
        if ($usePGP &&
            in_array($encrypt, array(IMP::PGP_ENCRYPT, IMP::PGP_SIGN, IMP::PGP_SIGNENC, IMP::PGP_SYM_ENCRYPT, IMP::PGP_SYM_SIGNENC))) {
            require_once IMP_BASE .'/lib/Crypt/PGP.php';
            $imp_pgp = new IMP_PGP();

            /* Get the user's passphrases, if we need it. */
            $passphrase = '';
            if (in_array($encrypt, array(IMP::PGP_SIGN, IMP::PGP_SIGNENC, IMP::PGP_SYM_SIGNENC))) {
                /* Check to see if we have the user's passphrase yet. */
                $passphrase = $imp_pgp->getPassphrase();
                if (empty($passphrase)) {
                    return PEAR::raiseError(_("PGP: Need passphrase for personal private key."), 'horde.message', null, null, 'pgp_passphrase_dialog');
                }
            }
            $symmetric_passphrase = '';
            if (in_array($encrypt, array(IMP::PGP_SYM_ENCRYPT, IMP::PGP_SYM_SIGNENC))) {
                /* Check to see if we have the user's symmetric passphrase
                 * yet. */
                $symmetric_passphrase = $imp_pgp->getSymmetricPassphrase();
                if (empty($symmetric_passphrase)) {
                    return PEAR::raiseError(_("PGP: Need passphrase to encrypt your message with."), 'horde.message', null, null, 'pgp_symmetric_passphrase_dialog');
                }
            }

            /* Do the encryption/signing requested. */
            switch ($encrypt) {
            case IMP::PGP_SIGN:
                $body = $imp_pgp->IMPsignMIMEPart($body);
                break;

            case IMP::PGP_ENCRYPT:
            case IMP::PGP_SYM_ENCRYPT:
                $to_list = ($from !== null) ? array_keys(array_flip(array_merge($to, array($from)))) : $to;
                $body = $imp_pgp->IMPencryptMIMEPart($body, $to_list, $encrypt == IMP::PGP_SYM_ENCRYPT);
                if ($encrypt == IMP::PGP_SYM_ENCRYPT) {
                    $imp_pgp->unsetSymmetricPassphrase();
                }
                break;

            case IMP::PGP_SIGNENC:
            case IMP::PGP_SYM_SIGNENC:
                $to_list = ($from !== null) ? array_keys(array_flip(array_merge($to, array($from)))) : $to;
                $body = $imp_pgp->IMPsignAndEncryptMIMEPart($body, $to_list, $encrypt == IMP::PGP_SYM_SIGNENC);
                if ($encrypt == IMP::PGP_SYM_SIGNENC) {
                    $imp_pgp->unsetSymmetricPassphrase();
                }
                break;
            }

            /* Check for errors. */
            if (is_a($body, 'PEAR_Error')) {
                return PEAR::raiseError(_("PGP Error: ") . $body->getMessage());
            }
        } elseif ($useSMIME &&
                  in_array($encrypt, array(IMP::SMIME_ENCRYPT, IMP::SMIME_SIGN, IMP::SMIME_SIGNENC))) {
            require_once IMP_BASE. '/lib/Crypt/SMIME.php';
            $imp_smime = new IMP_SMIME();

            /* Check to see if we have the user's passphrase yet. */
            if (in_array($encrypt, array(IMP::SMIME_SIGN, IMP::SMIME_SIGNENC))) {
                $passphrase = $imp_smime->getPassphrase();
                if ($passphrase === false) {
                    return PEAR::raiseError(_("S/MIME Error: Need passphrase for personal private key."), 'horde.error', null, null, 'smime_passphrase_dialog');
                }
            }

            /* Do the encryption/signing requested. */
            switch ($encrypt) {
            case IMP::SMIME_SIGN:
                $body = $imp_smime->IMPsignMIMEPart($body);
                break;

            case IMP::SMIME_ENCRYPT:
                $body = $imp_smime->IMPencryptMIMEPart($body, $to[0]);
                break;

            case IMP::SMIME_SIGNENC:
                $body = $imp_smime->IMPsignAndEncryptMIMEPart($body, $to[0]);
                break;
            }

            /* Check for errors. */
            if (is_a($body, 'PEAR_Error')) {
                return PEAR::raiseError(_("S/MIME Error: ") . $body->getMessage());
            }
        }

        /* Add data to Horde_Mime_Message object. */
        $body->setMIMEId(0);
        $mime_message->addPart($body);

        return array('recipients' => $to,
                     'to' => implode(', ', $to),
                     'msg' => &$mime_message);
    }

    /**
     * Finds the main "body" text part (if any) in a message.
     *
     * @param IMP_Contents &$imp_contents  An IMP_Contents object.
     *
     * @return string  The text of the "body" part of the message.
     *                 Returns an empty string if no "body" found.
     */
    public function findBody(&$imp_contents)
    {
        if (($this->_mimeid === null) || $this->_findhtml) {
            $mimeid = $imp_contents->findBody(($this->_findhtml) ? 'html' : null);
            if (!$this->_findhtml) {
                $this->_mimeid = $mimeid;
            }

            if (is_null($mimeid)) {
                return '';
            }
        } else {
            $mimeid = $this->_mimeid;
        }

        $mime_part = $imp_contents->getMIMEPart($mimeid);
        $body = $mime_part->getContents();
        $this->_bodyCharset = $mime_part->getCharset();

        //if ($mime_message->getType() == 'multipart/encrypted') {
            /* TODO: Maybe someday I can figure out how to show embedded
             * text parts here.  But for now, just output this message. */
        //    return '[' . _("Original message was encrypted") . ']';
        //}

        if (!$this->_findhtml && ($mime_part->getSubType() == 'html')) {
            require_once 'Horde/Text/Filter.php';
            return Text_Filter::filter(
                $body, 'html2text',
                array('charset' => $this->_bodyCharset));
        } else {
            return $body;
        }
    }

    /**
     * Returns the HTML body text of a message.
     *
     * The HTML code is passed through the XSS filter and any tags outside and
     * including the body and html tags are removed.
     *
     * @param IMP_Contents $imp_contents  An IMP_Contents object.
     *
     * @return string  The HTML text of the message. Returns an empty string if
     *                 no "body" found.
     */
    public function getHTMLBody($imp_contents)
    {
        $this->_findhtml = true;
        $body = $this->findBody($imp_contents);
        $this->_findhtml = false;
        if (!$body) {
            return $body;
        }

        /* Run tidy on the HTML. */
        if ($tidy_config = IMP::getTidyConfig(String::length($body))) {
            $tidy_config['show-body-only'] = true;
            if ($this->getBodyCharset($imp_contents) == 'us-ascii') {
                $tidy = tidy_parse_string($body, $tidy_config, 'ascii');
                $tidy->cleanRepair();
                $body = tidy_get_output($tidy);
            } else {
                $tidy = tidy_parse_string(
                    String::convertCharset(
                        $body, $this->getBodyCharset($imp_contents), 'UTF-8'),
                    $tidy_config, 'UTF8');
                $tidy->cleanRepair();
                $body = String::convertCharset(
                    tidy_get_output($tidy), 'UTF-8',
                    $this->getBodyCharset($imp_contents));
            }
        }

        require_once 'Horde/Text/Filter.php';
        $body = Text_Filter::filter($body, 'xss',
                                    array('body_only' => true,
                                          'strip_styles' => true,
                                          'strip_style_attributes' => false));

        return $body;
    }

    /**
     * Returns the ID of the MIME part containing the "body".
     *
     * @param IMP_Contents &$imp_contents  An IMP_Contents object.
     *
     * @return string  The ID of the mime part's body.
     */
    public function getBodyId(&$imp_contents)
    {
        if ($this->_mimeid === null) {
            $this->findBody($imp_contents);
        }
        return $this->_mimeid;
    }

    /**
     * Returns the charset of the MIME part containing the "body".
     *
     * @param IMP_Contents &$imp_contents  An IMP_Contents object.
     *
     * @return string  The charset of the mime part's body.
     */
    public function getBodyCharset(&$imp_contents)
    {
        if ($this->_bodyCharset === null) {
            $this->findBody($imp_contents);
        }
        return $this->_bodyCharset;
    }

    /**
     * Determines the reply text and headers for a message.
     *
     * @param string $actionID            The reply action (reply, reply_all,
     *                                    reply_list or *).
     * @param IMP_Contents $imp_contents  An IMP_Contents object.
     * @param string $to                  The recipient of the reply. Overrides
     *                                    the automatically determined value.
     *
     * @return array  An array with the following keys:
     * <pre>
     * 'body'     - The text of the body part
     * 'headers'  - The headers of the message to use for the reply
     * 'format'   - The format of the body message
     * 'identity' - The identity to use for the reply based on the original
     *              message's addresses.
     * </pre>
     */
    public function replyMessage($actionID, &$imp_contents, $to = null)
    {
        global $prefs;

        /* The headers of the message. */
        $header = array();
        $header['to'] = '';
        $header['cc'] = '';
        $header['bcc'] = '';
        $header['subject'] = '';
        $header['in_reply_to'] = '';
        $header['references'] = '';

        $h = $imp_contents->getHeaderOb();
        $match_identity = $this->_getMatchingIdentity($h);

        /* Set the message_id and references headers. */
        if (($msg_id = $h->getValue('message-id'))) {
            $header['in_reply_to'] = chop($msg_id);
            if (($header['references'] = $h->getValue('references'))) {
                $header['references'] .= ' ' . $header['in_reply_to'];
            } else {
                $header['references'] = $header['in_reply_to'];
            }
        }

        $header['subject'] = $h->getValue('subject');
        if (!empty($header['subject'])) {
            $header['subject'] = 'Re: ' . Horde_Imap_Client::getBaseSubject($header['subject'], array('keepblob' => true));
        } else {
            $header['subject'] = 'Re: ';
        }

        $mime_message = $imp_contents->getMIMEMessage();
        $header['encoding'] = $this->_getEncoding($mime_message);

        if ($actionID == 'reply' || $actionID == '*') {
            ($header['to'] = $to) ||
            ($header['to'] = Horde_Mime_Address::addrArray2String($h->getOb('reply-to'))) ||
            ($header['to'] = Horde_Mime_Address::addrArray2String($h->getOb('from')));
            if ($actionID == '*') {
                $all_headers['reply'] = $header;
            }
        }
        if ($actionID == 'reply_all' || $actionID == '*') {
            /* Filter out our own address from the addresses we reply to. */
            require_once 'Horde/Identity.php';
            $identity = &Identity::singleton(array('imp', 'imp'));
            $me = array_keys($identity->getAllFromAddresses(true));

            /* Build the To: header. */
            $from_arr = $h->getOb('from');
            $to_arr = $h->getOb('reply-to');
            $reply = '';
            if (!empty($to_arr)) {
                $reply = Horde_Mime_Address::addrArray2String($to_arr);
            } elseif (!empty($from_arr)) {
                $reply = Horde_Mime_Address::addrArray2String($from_arr);
            }
            $header['to'] = Horde_Mime_Address::addrArray2String(array_merge($to_arr, $from_arr));
            $me[] = Horde_Mime_Address::bareAddress($header['to'], $_SESSION['imp']['maildomain']);

            /* Build the Cc: header. */
            $cc_arr = $h->getOb('to');
            if (!empty($cc_arr) &&
                ($reply != Horde_Mime_Address::addrArray2String($cc_arr))) {
                $cc_arr = array_merge($cc_arr, $h->getOb('cc'));
            } else {
                $cc_arr = $h->getOb('cc');
            }
            $header['cc'] = Horde_Mime_Address::addrArray2String($cc_arr, $me);

            /* Build the Bcc: header. */
            $header['bcc'] = Horde_Mime_Address::addrArray2String($h->getOb('bcc') + $identity->getBccAddresses(), $me);
            if ($actionID == '*') {
                $all_headers['reply_all'] = $header;
            }
        }
        if ($actionID == 'reply_list' || $actionID == '*') {
            $list_info = $h->getListInformation();
            if ($list_info['exists']) {
                $header['to'] = $list_info['reply_list'];
                if ($actionID == '*') {
                    $all_headers['reply_list'] = $header;
                }
            }
        }
        if ($actionID == '*') {
            $header = $all_headers;
        }

        $from = Horde_Mime_Address::addrArray2String($h->getOb('from'));
        if (empty($from)) {
            $from = '&lt;&gt;';
        }

        if (!$prefs->getValue('reply_quote')) {
            return array('body' => '', 'headers' => $header, 'format' => 'text', 'identity' => $match_identity);
        }

        if ($prefs->getValue('reply_headers') && !empty($h)) {
            $msg_pre = '----- ';
            if (($from = $h->getFromAddress())) {
                $msg_pre .= sprintf(_("Message from %s"), $from);
            } else {
                $msg_pre .= _("Message");
            }

            /* Extra '-'s line up with "End Message" below. */
            $msg_pre .= " ---------\n";
            $msg_pre .= $this->_getMsgHeaders($h) . "\n\n";
            if (!empty($from)) {
                $msg_post = "\n\n" . '----- ' . sprintf(_("End message from %s"), $from) . " -----\n";
            } else {
                $msg_post .= "\n\n" . '----- ' . _("End message") . " -----\n";
            }
        } else {
            $msg_pre = $this->_expandAttribution($prefs->getValue('attrib_text'), $from, $h) . "\n\n";
            $msg_post = '';
        }

        $msg = '';
        $rte = $GLOBALS['browser']->hasFeature('rte');

        if ($rte && $GLOBALS['prefs']->getValue('reply_format')) {
            $body = $this->getHTMLBody($imp_contents);
            if ($body) {
                $body = $this->_getHtmlText($body, $imp_contents, $mime_message);
                $msg_pre = '<p>' . $this->text2html(trim($msg_pre)) . '</p>';
                if ($msg_post) {
                    $msg_post = $this->text2html($msg_post);
                }
                $msg = $msg_pre . '<blockquote type="cite">' . $body .
                    '</blockquote>' . $msg_post;
                $format = 'html';
            }
        }

        if (empty($msg)) {
            $msg = $this->_rebuildMsgText($imp_contents, true);
            if (empty($msg)) {
                $msg = '[' . _("No message body text") . ']';
            } else {
                $msg = $msg_pre . $msg . $msg_post;
            }
            if ($rte && $GLOBALS['prefs']->getValue('compose_html')) {
                $msg = $this->text2html($msg);
                $format = 'html';
            } else {
                $format = 'text';
            }
        }

        return array('body' => $msg . "\n", 'headers' => $header, 'format' => $format, 'identity' => $match_identity);
    }

    /**
     * Determine the text and headers for a forwarded message.
     *
     * @param IMP_Contents $imp_contents  An IMP_Contents object.
     * @param string $forcebodytxt        Force addition of body text, even if
     *                                    prefs would not allow it.
     *
     * @return array  An array with the following keys:
     * <pre>
     * 'body'     - The text of the body part
     * 'headers'  - The headers of the message to use for the reply
     * 'format'   - The format of the body message
     * 'identity' - The identity to use for the reply based on the original
     *              message's addresses.
     * </pre>
     */
    public function forwardMessage(&$imp_contents, $forcebodytxt = false)
    {
        /* The headers of the message. */
        $header = array();
        $header['to'] = '';
        $header['cc'] = '';
        $header['bcc'] = '';
        $header['subject'] = '';
        $header['in_reply_to'] = '';
        $header['references'] = '';

        $h = $imp_contents->getHeaderOb();

        /* We need the Message-Id so we can log this event. */
        $message_id = $h->getValue('message-id');
        $header['in_reply_to'] = chop($message_id);

        $header['subject'] = $h->getValue('subject');
        if (!empty($header['subject'])) {
            $header['title'] = _("Forward:") . ' ' . $header['subject'];
            $header['subject'] = 'Fwd: ' . Horde_Imap_Client::getBaseSubject($header['subject'], array('keepblob' => true));
        } else {
            $header['title'] = _("Forward");
            $header['subject'] = 'Fwd:';
        }

        $mime_message = $imp_contents->getMIMEMessage();
        $header['encoding'] = $this->_getEncoding($mime_message);

        if ($forcebodytxt || $GLOBALS['prefs']->getValue('forward_bodytext')) {
            $msg_pre = "\n\n\n----- ";

            if (($from = $h->getFromAddress())) {
                $msg_pre .= sprintf(_("Forwarded message from %s"), $from);
            } else {
                $msg_pre .= _("Forwarded message");
            }

            $msg_pre .= " -----\n" . $this->_getMsgHeaders($h) . "\n";
            $msg_post = "\n\n----- " . _("End forwarded message") . " -----\n";

            $msg = '';
            $rte = $GLOBALS['browser']->hasFeature('rte');

            if ($rte && $GLOBALS['prefs']->getValue('reply_format')) {
                $body = $this->getHTMLBody($imp_contents);
                if ($body) {
                    $body = $this->_getHtmlText($body, $imp_contents, $mime_message);
                    $msg = $this->text2html($msg_pre) . $body . $this->text2html($msg_post);
                    $format = 'html';
                }
            }

            if (empty($msg)) {
                $msg = $msg_pre . $this->_rebuildMsgText($imp_contents) . $msg_post;
                if ($rte && $GLOBALS['prefs']->getValue('compose_html')) {
                    $msg = $this->text2html($msg);
                    $format = 'html';
                } else {
                    $format = 'text';
                }
            }
        } else {
            $msg = '';
            $format = 'text';
        }

        $identity = $this->_getMatchingIdentity($h);

        return array('body' => $msg, 'headers' => $header, 'format' => $format, 'identity' => $identity);
    }

    /**
     * Get "tieto" identity information.
     *
     * @param IMP_Headers $h  The headers object for the message.
     *
     * @return mixed  See Identity_imp::getMatchingIdentity().
     */
    protected function _getMatchingIdentity($h)
    {
        $msgAddresses = array();
        foreach (array('to', 'cc', 'bcc') as $val) {
            $msgAddresses[] = $h->getValue($val);
        }
        require_once 'Horde/Identity.php';
        $user_identity = &Identity::singleton(array('imp', 'imp'));
        return $user_identity->getMatchingIdentity($msgAddresses);
    }

    /**
     * Add mail message(s) from the mail server as a message/rfc822 attachment.
     *
     * @param mixed $indices  See IMP::parseIndicesList().
     * @param array &$header  Message headers array.
     */
    public function attachIMAPMessage($indices, &$header)
    {
        $msgList = IMP::parseIndicesList($indices);
        if (empty($msgList)) {
            return;
        }

        $attached = 0;
        foreach ($msgList as $folder => $indicesList) {
            foreach ($indicesList as $val) {
                ++$attached;
                $part = new Horde_Mime_Part('message/rfc822');
                $contents = &IMP_Contents::singleton($val . IMP::IDX_SEP . $folder);
                $digest_headers = $contents->getHeaderOb();
                if (!($name = $digest_headers->getValue('subject'))) {
                    $name = _("[No Subject]");
                } else {
                    // Strip periods from name - see Ticket #4977
                    $part->setCharset(NLS::getCharset());
                    $part->setName(_("Forwarded Message:") . ' ' . rtrim($name, '.'));
                }
                $part->setContents(preg_replace("/\r\n?/", "\n", $contents->fullMessageText()));
                $result = $this->addMIMEPartAttachment($part);
                if (is_a($result, 'PEAR_Error')) {
                    $GLOBALS['notification']->push($result);
                }
            }
        }

        if (empty($header['subject'])) {
            if ($attached == 1) {
                // $name is set inside the loop above.
                $header['subject'] = 'Fwd: ' . Horde_Imap_Client::getBaseSubject($name, array('keepblob' => true));
            } else {
                $header['subject'] = 'Fwd: ' . sprintf(_("%u Forwarded Messages"), $attached);
            }
        }
    }

    /**
     * Returns the charset to use for outgoing messages based on (by replying
     * to or forwarding) the given MIME message and the user's default
     * settings.
     *
     * @param Horde_Mime_Message $mime_message  A MIME message object.
     *
     * @return string  The charset to use.
     */
    protected function _getEncoding($mime_message = null)
    {
        $encoding = NLS::getEmailCharset();

        if (isset($mime_message)) {
            if ($mime_message->getPrimaryType() == 'multipart') {
                foreach ($mime_message->getParts() as $part) {
                    if ($part->getPrimaryType() == 'text') {
                        $mime_message = $part;
                        break;
                    }
                }
            }
            if (NLS::getCharset() == 'UTF-8') {
                $charset_upper = String::upper($mime_message->getCharset());
                if (($charset_upper != 'US-ASCII') &&
                    ($charset_upper != String::upper($encoding))) {
                    $encoding = 'UTF-8';
                }
            }
        }

        return $encoding;
    }

    /**
     * Determine the header information to display in the forward/reply.
     *
     * @param IMP_Headers &$h  The IMP_Headers object for the message.
     *
     * @return string  The header information for the original message.
     */
    protected function _getMsgHeaders(&$h)
    {
        $text = '';

        if (($date_ob = $h->getValue('date'))) {
            $text .= _("    Date: ") . $date_ob . "\n";
        }
        if (($from_ob = Horde_Mime_Address::addrArray2String($h->getOb('from')))) {
            $text .= _("    From: ") . $from_ob . "\n";
        }
        if (($rep_ob = Horde_Mime_Address::addrArray2String($h->getOb('reply-to')))) {
            $text .= _("Reply-To: ") . $rep_ob . "\n";
        }
        if (($sub_ob = $h->getValue('subject'))) {
            $text .= _(" Subject: ") . $sub_ob . "\n";
        }
        if (($to_ob = Horde_Mime_Address::addrArray2String($h->getOb('to')))) {
            $text .= _("      To: ") . $to_ob . "\n";
        }
        if (($cc_ob = Horde_Mime_Address::addrArray2String($h->getOb('cc')))) {
            $text .= _("      Cc: ") . $cc_ob . "\n";
        }

        return $text;
    }

    /**
     * Adds an attachment to a Horde_Mime_Part from an uploaded file.
     * The actual attachment data is stored in a separate file - the
     * Horde_Mime_Part information entries 'temp_filename' and 'temp_filetype'
     * are set with this information.
     *
     * @param string $name         The input field name from the form.
     * @param string $disposition  The disposition to use for the file.
     *
     * @return mixed  Returns the filename on success.
     *                Returns PEAR_Error on error.
     */
    public function addUploadAttachment($name, $disposition)
    {
        global $conf;

        $res = Browser::wasFileUploaded($name, _("attachment"));
        if (is_a($res, 'PEAR_Error')) {
            return $res;
        }

        $filename = Util::dispelMagicQuotes($_FILES[$name]['name']);
        $tempfile = $_FILES[$name]['tmp_name'];

        /* Check for filesize limitations. */
        if (!empty($conf['compose']['attach_size_limit']) &&
            (($conf['compose']['attach_size_limit'] - $this->sizeOfAttachments() - $_FILES[$name]['size']) < 0)) {
            return PEAR::raiseError(sprintf(_("Attached file \"%s\" exceeds the attachment size limits. File NOT attached."), $filename), 'horde.error');
        }

        /* Store the data in a Horde_Mime_Part. Some browsers do not send the
         * MIME type so try an educated guess. */
        $part = new Horde_Mime_Part();
        if (!empty($_FILES[$name]['type']) &&
            ($_FILES[$name]['type'] != 'application/octet-stream')) {
            $type = $_FILES[$name]['type'];
        } else {
            /* Try to determine the MIME type from 1) analysis of the file
             * (if available) and, if that fails, 2) from the extension. We
             * do it in this order here because, most likely, if a browser
             * can't identify the type of a file, it is because the file
             * extension isn't available and/or recognized. */
            if (!($type = Horde_Mime_Magic::analyzeFile($tempfile, !empty($conf['mime']['magic_db']) ? $conf['mime']['magic_db'] : null))) {
                $type = Horde_Mime_Magic::filenameToMIME($filename, false);
            }
        }
        $part->setType($type);
        $part->setCharset(NLS::getCharset());
        $part->setName($filename);
        $part->setBytes($_FILES[$name]['size']);
        if ($disposition) {
            $part->setDisposition($disposition);
        }

        if ($conf['compose']['use_vfs']) {
            $attachment = $tempfile;
        } else {
            $attachment = Horde::getTempFile('impatt', false);
            if (move_uploaded_file($tempfile, $attachment) === false) {
                return PEAR::raiseError(sprintf(_("The file %s could not be attached."), $filename), 'horde.error');
            }
        }

        /* Store the data. */
        $result = $this->_storeAttachment($part, $attachment);
        if (is_a($result, 'PEAR_Error')) {
            return $result;
        }

        return $filename;
    }

    /**
     * Adds an attachment to a Horde_Mime_Part from data existing in the part.
     *
     * @param Horde_Mime_Part &$part  The object that contains the attachment
     *                                data.
     *
     * @return PEAR_Error  Returns a PEAR_Error object on error.
     */
    public function addMIMEPartAttachment(&$part)
    {
        global $conf;

        $type = $part->getType();
        $vfs = $conf['compose']['use_vfs'];

        /* Decode the contents. */
        $part->transferDecodeContents();

        /* Try to determine the MIME type from 1) the extension and
         * then 2) analysis of the file (if available). */
        if ($type == 'application/octet-stream') {
            $type = Horde_Mime_Magic::filenameToMIME($part->getName(true), false);
        }

        /* Extract the data from the currently existing Horde_Mime_Part and
           then delete it. If this is an unknown MIME part, we must save to a
           temporary file to run the file analysis on it. */
        if (!$vfs) {
            $attachment = Horde::getTempFile('impatt', false);
            $fp = fopen($attachment, 'w');
            $res = fwrite($fp, $part->getContents());
            fclose($fp);
            if ($res === false) {
                return PEAR::raiseError(sprintf(_("Could not attach %s to the message."), $part->getName()), 'horde.error');
            }

            if (($type == 'application/octet-stream') &&
                ($analyzetype = Horde_Mime_Magic::analyzeFile($attachment, !empty($conf['mime']['magic_db']) ? $conf['mime']['magic_db'] : null))) {
                $type = $analyzetype;
            }
        } else {
            $vfs_data = $part->getContents();
            if (($type == 'application/octet-stream') &&
                ($analyzetype = Horde_Mime_Magic::analyzeData($vfs_data, !empty($conf['mime']['magic_db']) ? $conf['mime']['magic_db'] : null))) {
                $type = $analyzetype;
            }
        }

        $part->setType($type);

        /* Set the size of the Part explicitly since we delete the contents
           later on in this function. */
        $part->setBytes($part->getBytes());
        $part->clearContents();

        /* Check for filesize limitations. */
        if (!empty($conf['compose']['attach_size_limit']) &&
            (($conf['compose']['attach_size_limit'] - $this->sizeOfAttachments() - $part->getBytes()) < 0)) {
            return PEAR::raiseError(sprintf(_("Attached file \"%s\" exceeds the attachment size limits. File NOT attached."), $part->getName()), 'horde.error');
        }

        /* Store the data. */
        if ($vfs) {
            $this->_storeAttachment($part, $vfs_data, false);
        } else {
            $this->_storeAttachment($part, $attachment);
        }
    }

    /**
     * Stores the attachment data in its correct location.
     *
     * @param Horde_Mime_Part &$part   The Horde_Mime_Part of the attachment.
     * @param string $data       Either the filename of the attachment or, if
     *                           $vfs_file is false, the attachment data.
     * @param boolean $vfs_file  If using VFS, is $data a filename?
     */
    protected function _storeAttachment(&$part, $data, $vfs_file = true)
    {
        global $conf;

        /* Store in VFS. */
        if ($conf['compose']['use_vfs']) {
            require_once 'VFS.php';
            require_once 'VFS/GC.php';
            $vfs = &VFS::singleton($conf['vfs']['type'], Horde::getDriverConfig('vfs', $conf['vfs']['type']));
            VFS_GC::gc($vfs, self::VFS_ATTACH_PATH, 86400);
            $cacheID = uniqid(mt_rand());
            if ($vfs_file) {
                $result = $vfs->write(self::VFS_ATTACH_PATH, $cacheID, $data, true);
            } else {
                $result = $vfs->writeData(self::VFS_ATTACH_PATH, $cacheID, $data, true);
            }
            if (is_a($result, 'PEAR_Error')) {
                return $result;
            }
            $part->setInformation('temp_filename', $cacheID);
            $part->setInformation('temp_filetype', 'vfs');
        } else {
            chmod($data, 0600);
            $part->setInformation('temp_filename', $data);
            $part->setInformation('temp_filetype', 'file');
        }

        /* Add the size information to the counter. */
        $this->_size += $part->getBytes();

        $this->_cache[++$this->_atcid] = $part;
    }

    /**
     * Delete attached files.
     *
     * @param mixed $number  Either a single integer or an array of integers
     *                       corresponding to the attachment position.
     *
     * @return array  The list of deleted filenames (MIME encoded).
     */
    public function deleteAttachment($number)
    {
        global $conf;

        $names = array();

        if (!is_array($number)) {
            $number = array($number);
        }

        foreach ($number as $val) {
            $part = &$this->_cache[$val];
            if (!is_a($part, 'Horde_Mime_Part')) {
                continue;
            }
            $filename = $part->getInformation('temp_filename');
            if ($part->getInformation('temp_filetype') == 'vfs') {
                /* Delete from VFS. */
                require_once 'VFS.php';
                $vfs = &VFS::singleton($conf['vfs']['type'], Horde::getDriverConfig('vfs', $conf['vfs']['type']));
                $vfs->deleteFile(self::VFS_ATTACH_PATH, $filename);
            } else {
                /* Delete from filesystem. */
                @unlink($filename);
            }

            $part->setInformation('temp_filename', '');
            $part->setInformation('temp_filetype', '');

            $names[] = $part->getName(false, true);

            /* Remove the size information from the counter. */
            $this->_size -= $part->getBytes();

            unset($this->_cache[$val]);
        }

        return $names;
    }

    /**
     * Deletes all attachments.
     */
    public function deleteAllAttachments()
    {
        $this->deleteAttachment(array_keys($this->_cache));
    }

    /**
     * Updates information in a specific attachment.
     *
     * @param integer $number  The attachment to update.
     * @param array $params    An array of update information.
     * <pre>
     * 'disposition'  --  The Content-Disposition value.
     * 'description'  --  The Content-Description value.
     * </pre>
     */
    public function updateAttachment($number, $params)
    {
        $this->_cache[$number]->setDisposition($params['disposition']);
        $this->_cache[$number]->setDescription($params['description']);
    }

    /**
     * Returns the list of current attachments.
     *
     * @return array  The list of attachments.
     */
    public function getAttachments()
    {
        return $this->_cache;
    }

    /**
     * Returns the number of attachments currently in this message.
     *
     * @return integer  The number of attachments in this message.
     */
    public function numberOfAttachments()
    {
        return count($this->_cache);
    }

    /**
     * Returns the size of the attachments in bytes.
     *
     * @return integer  The size of the attachments (in bytes).
     */
    public function sizeOfAttachments()
    {
        return $this->_size;
    }

    /**
     * Build a single attachment part with its data.
     *
     * @param integer $id  The ID of the part to rebuild.
     *
     * @return Horde_Mime_Part  The Horde_Mime_Part with its contents.
     */
    public function buildAttachment($id)
    {
        $part = $this->_cache[$id];
        $this->_buildPartData($part);
        return $part;
    }

    /**
     * Takes the temporary data for a single part and puts it into the
     * contents of that part.
     *
     * @param Horde_Mime_Part &$part  The part to rebuild data into.
     */
    protected function _buildPartData(&$part)
    {
        global $conf;

        $filename = $part->getInformation('temp_filename');
        if ($part->getInformation('temp_filetype') == 'vfs') {
            require_once 'VFS.php';
            $vfs = &VFS::singleton($conf['vfs']['type'], Horde::getDriverConfig('vfs', $conf['vfs']['type']));
            $data = $vfs->read(self::VFS_ATTACH_PATH, $filename);
        } else {
            $data = file_get_contents($filename);
        }

        /* Set the part's contents to the raw attachment data. */
        $part->setContents($data);
    }

    /**
     * Expand macros in attribution text when replying to messages.
     *
     * @param string $line     The line of attribution text.
     * @param string $from     The email address of the original
     *                         sender.
     * @param IMP_Headers &$h  The IMP_Headers object for the message.
     *
     * @return string  The attribution text.
     */
    protected function _expandAttribution($line, $from, &$h)
    {
        $addressList = '';
        $nameList = '';

        /* First we'll get a comma seperated list of email addresses
           and a comma seperated list of personal names out of $from
           (there just might be more than one of each). */
        $addr_list = Horde_Mime_Address::parseAddressList($from);
        if (empty($addr_list)) {
            $addr_list = array();
        }

        foreach ($addr_list as $entry) {
            if (isset($entry['mailbox'])) {
                if (strlen($addressList) > 0) {
                    $addressList .= ', ';
                }
                $addressList .= $entry['mailbox'];
                if (isset($entry['host'])) {
                    $addressList .= '@' . $entry['host'];
                }
            }

            if (isset($entry['personal'])) {
                if (strlen($nameList) > 0) {
                    $nameList .= ', ';
                }
                $nameList .= $entry['personal'];
            } elseif (isset($entry['mailbox'])) {
                if (strlen($nameList) > 0) {
                    $nameList .= ', ';
                }
                $nameList .= $entry['mailbox'];
            }
        }

        /* Define the macros. */
        if (is_array($message_id = $h->getValue('message_id'))) {
            $message_id = reset($message_id);
        }
        if (!($subject = $h->getValue('subject'))) {
            $subject = _("[No Subject]");
        }
        $udate = strtotime($h->getValue('date'));

        $match = array(
            /* New line. */
            '/%n/' => "\n",

            /* The '%' character. */
            '/%%/' => '%',

            /* Name and email address of original sender. */
            '/%f/' => $from,

            /* Senders email address(es). */
            '/%a/' => $addressList,

            /* Senders name(s). */
            '/%p/' => $nameList,

            /* RFC 822 date and time. */
            '/%r/' => $h->getValue('date'),

            /* Date as ddd, dd mmm yyyy. */
            '/%d/' => String::convertCharset(strftime("%a, %d %b %Y", $udate), NLS::getExternalCharset()),

            /* Date in locale's default. */
            '/%x/' => String::convertCharset(strftime("%x", $udate), NLS::getExternalCharset()),

            /* Date and time in locale's default. */
            '/%c/' => String::convertCharset(strftime("%c", $udate), NLS::getExternalCharset()),

            /* Message-ID. */
            '/%m/' => $message_id,

            /* Message subject. */
            '/%s/' => $subject
        );

        return (preg_replace(array_keys($match), array_values($match), $line));
    }

    /**
     * Obtains the cache ID for the session object.
     *
     * @return string  The message cache ID.
     */
    public function getMessageCacheId()
    {
        return $this->_cacheid;
    }

    /**
     * How many more attachments are allowed?
     *
     * @return mixed  Returns true if no attachment limit.
     *                Else returns the number of additional attachments
     *                allowed.
     */
    public function additionalAttachmentsAllowed()
    {
        global $conf;

        if (!empty($conf['compose']['attach_count_limit'])) {
            return $conf['compose']['attach_count_limit'] - $this->numberOfAttachments();
        } else {
            return true;
        }
    }

    /**
     * What is the maximum attachment size allowed?
     *
     * @return integer  The maximum attachment size allowed (in bytes).
     */
    public function maxAttachmentSize()
    {
        global $conf;

        $size = $_SESSION['imp']['file_upload'];

        if (!empty($conf['compose']['attach_size_limit'])) {
            $size = min($size, max($conf['compose']['attach_size_limit'] - $this->sizeOfAttachments(), 0));
        }

        return $size;
    }

    /**
     * Adds attachments from the IMP_Contents object to the message.
     *
     * @param IMP_Contents &$contents  An IMP_Contents object.
     * @param boolean $download        Use the algorithm in
     *                                 IMP_Contents::getDownloadAllList() to
     *                                 determine the list of attachments?
     *
     * @return array  An array of PEAR_Error object on error.
     *                An empty array if successful.
     */
    public function attachFilesFromMessage(&$contents, $download = false)
    {
        $errors = array();

        $contents->rebuildMessage();
        $this->findBody($contents);
        $mime_message = $contents->getMIMEMessage();
        $dl_list = ($download) ? $contents->getDownloadAllList() : array_keys($mime_message->contentTypeMap());

        foreach ($dl_list as $key) {
            if (($key != 0) &&
                (($this->_mimeid === null) || ($key != $this->_mimeid))) {
                $mime = $mime_message->getPart($key);
                if (!empty($mime) &&
                    (!$this->_resume || !$mime->getInformation('rfc822_part'))) {
                    $res = $this->addMIMEPartAttachment($mime);
                    if (is_a($res, 'PEAR_Error')) {
                        $errors[] = $res;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Convert a text/html Horde_Mime_Part message with embedded image links
     * to a multipart/related MIME_Part with the image data embedded in the
     * part.
     *
     * @param Horde_Mime_Part $mime_part  The text/html object.
     *
     * @return Horde_Mime_Part  The modified Horde_Mime_Part.
     */
    public function convertToMultipartRelated($mime_part)
    {
        global $conf;

        /* Return immediately if HTTP_Request is not available. */
        $inc = include_once 'HTTP/Request.php';
        if ($inc === false) {
            return $mime_part;
        }

        /* Return immediately if not an HTML part. */
        if ($mime_part->getType() != 'text/html') {
            return $mime_part;
        }

        /* Scan for 'img' tags - specifically the 'src' parameter. If
         * none, return the original Horde_Mime_Part. */
        if (!preg_match_all('/<img[^>]+src\s*\=\s*([^\s]+)\s+/iU', $mime_part->getContents(), $results)) {
            return $mime_part;
        }

        /* Go through list of results, download the image, and create
         * Horde_Mime_Part objects with the data. */
        $img_data = array();
        $img_parts = array();
        $img_request_options = array('timeout' => 5);
        if (isset($conf['http']['proxy']) && !empty($conf['http']['proxy']['proxy_host'])) {
            $img_request_options = array_merge($img_request_options, $conf['http']['proxy']);
        }
        foreach ($results[1] as $url) {
            /* Strip any quotation marks and convert '&amp;' to '&' (since
             * HTTP_Request doesn't handle the former correctly). */
            $img_url = str_replace('&amp;', '&', trim($url, '"\''));

            /* Attempt to download the image data. */
            $request = new HTTP_Request($img_url, $img_request_options);
            $request->sendRequest();

            if ($request->getResponseCode() == '200') {
                /* We need to determine the image type.  Try getting
                 * that information from the returned HTTP
                 * content-type header.  TODO: Use Horde_Mime_Magic if this
                 * fails (?) */
                $part = new Horde_Mime_Part($request->getResponseHeader('content-type'), $request->getResponseBody(), null, 'attachment', '8bit');
                $img_data[$url] = '"cid:' . $part->setContentID() . '"';
                $img_parts[] = $part;
            }
        }

        /* If we could not successfully download any data, return the
         * original Horde_Mime_Part now. */
        if (empty($img_data)) {
            return $mime_part;
        }

        /* Replace the URLs with with CID tags. */
        $text = $mime_part->getContents();
        $text = str_replace(array_keys($img_data), array_values($img_data), $text);
        $mime_part->setContents($text);

        /* Create new multipart/related part. */
        $related = new Horde_Mime_Part('multipart/related');

        /* Get the CID for the 'root' part. Although by default the
         * first part is the root part (RFC 2387 [3.2]), we may as
         * well be explicit and put the CID in the 'start'
         * parameter. */
        $related->setContentTypeParameter('start', $mime_part->setContentID());

        /* Add the root part and the various images to the multipart
         * object. */
        $related->addPart($mime_part);
        foreach ($img_parts as $val) {
            $related->addPart($val);
        }

        return $related;
    }

    /**
     * Remove all attachments from an email message and replace with
     * urls to downloadable links. Should properly save all
     * attachments to a new folder and remove the Horde_Mime_Parts for the
     * attachments.
     *
     * @param string $baseurl             The base URL for creating the links.
     * @param Horde_Mime_Part $base_part  The body of the message.
     * @param string $auth                The authorized user who owns the
     *                                    attachments.
     *
     * @return Horde_Mime_Part  Modified part with links to attachments.
     *                          Returns PEAR_Error on error.
     */
    public function linkAttachments($baseurl, $base_part, $auth)
    {
        global $conf, $prefs;

        if (!$conf['compose']['link_attachments']) {
            return PEAR::raiseError(_("Linked attachments are forbidden."));
        }

        require_once 'VFS.php';
        $vfs = &VFS::singleton($conf['vfs']['type'], Horde::getDriverConfig('vfs', $conf['vfs']['type']));

        $ts = time();
        $fullpath = sprintf('%s/%s/%d', self::VFS_LINK_ATTACH_PATH, $auth, $ts);

        $trailer = String::convertCharset(_("Attachments"), NLS::getCharset(), $base_part->getCharset());

        if ($prefs->getValue('delete_attachments_monthly')) {
            /* Determine the first day of the month in which the current
             * attachments will be ripe for deletion, then subtract 1 second
             * to obtain the last day of the previous month. */
            $del_time = mktime(0, 0, 0, date('n') + $prefs->getValue('delete_attachments_monthly_keep') + 1, 1, date('Y')) - 1;
            $trailer .= String::convertCharset(' (' . sprintf(_("Links will expire on %s"), strftime('%x', $del_time)) . ')', NLS::getCharset(), $base_part->getCharset());
        }

        foreach ($this->getAttachments() as $att) {
            $trailer .= "\n" . Util::addParameter($baseurl, array('u' => $auth,
                                                                  't' => $ts,
                                                                  'f' => $att->getName()),
                                                  null, false);
            if ($conf['compose']['use_vfs']) {
                $res = $vfs->rename(self::VFS_ATTACH_PATH, $att->getInformation('temp_filename'), $fullpath, escapeshellcmd($att->getName()));
            } else {
                $data = file_get_contents($att->getInformation('temp_filename'));
                $res = $vfs->writeData($fullpath, escapeshellcmd($att->getName()), $data, true);
            }
            if (is_a($res, 'PEAR_Error')) {
                Horde::logMessage($res, __FILE__, __LINE__, PEAR_LOG_ERR);
                return $res;
            }
        }

        $this->deleteAllAttachments();

        if ($base_part->getPrimaryType() == 'multipart') {
            $mixed_part = new Horde_Mime_Part('multipart/mixed');
            $mixed_part->addPart($base_part);
            $link_part = new Horde_Mime_Part('text/plain', $trailer, $base_part->getCharset(), 'inline', $base_part->getCurrentEncoding());
            $link_part->setDescription(_("Attachment Information"));
            $mixed_part->addPart($link_part);
            return $mixed_part;
        } else {
            $base_part->appendContents("\n-----\n" . $trailer, $base_part->getCurrentEncoding());
            return $base_part;
        }
    }

    /**
     * Regenerates plain body text for use in the compose screen from IMAP
     * data.
     *
     * @param IMP_Contents $imp_contents  An IMP_Contents object.
     * @param boolean $toflowed           Convert to flowed?
     *
     * @return string  The body text.
     */
    protected function _rebuildMsgText($imp_contents, $toflowed = false)
    {
        $msg = $this->findBody($imp_contents);
        if ($this->_mimeid === null) {
            return '';
        }

        $mime_message = $imp_contents->getMIMEMessage();
        $old_part = $mime_message->getPart($this->_mimeid);
        $msg = $this->_applyReplyLimit($msg, $old_part->getCharset());
        $msg = $mime_message->replaceEOL($msg, "\n");

        if ($old_part->getContentTypeParameter('format') == 'flowed') {
            /* We need to convert the flowed text to fixed text before we
             * begin working on it. */
            require_once 'Text/Flowed.php';
            $flowed = new Text_Flowed($msg);
            if (String::lower($mime_message->getContentTypeParameter('delsp')) == 'yes') {
                $flowed->setDelSp(true);
            }
            $flowed->setMaxLength(0);
            $msg = $flowed->toFixed(false);
        } else {
            /* If the input is *not* in flowed format, make sure there is
             * no padding at the end of lines. */
            $msg = preg_replace("/\s*\n/U", "\n", $msg);
        }

        $msg = String::convertCharset($msg, $old_part->getCharset());

        if ($toflowed && ($old_part->getType() == 'text/plain')) {
            require_once 'Text/Flowed.php';
            $flowed = new Text_Flowed($msg);
            return $flowed->toFlowed(true);
        } else {
            return $msg;
        }
    }

    /**
     * Attach the user's PGP public key to every message sent by
     * buildAndSendMessage().
     *
     * @param boolean $attach  True if public key should be attached.
     */
    public function pgpAttachPubkey($attach)
    {
        $this->_pgpAttachPubkey = $attach;
    }

    /**
     * Attach the user's vCard to every message sent by buildAndSendMessage().
     *
     * @param boolean $attach  True if vCard should be attached.
     * @param string $name     The user's name.
     */
    public function attachVCard($attach, $name)
    {
        if (!$attach) {
            return;
        }
        $vcard = $GLOBALS['registry']->call('contacts/ownVCard');
        if (is_a($vcard, 'PEAR_Error')) {
            $this->_attachVCard = $vcard;
        } else {
            $part = new Horde_Mime_Part('text/x-vcard', $vcard, NLS::getCharset());
            $part->setName((strlen($name) ? $name : 'vcard') . '.vcf');
            $this->_attachVCard = $part;
        }
    }

    /**
     * Has user specifically asked attachments to be linked in outgoing
     * messages?
     *
     * @param boolean $attach  True if attachments should be linked.
     */
    public function userLinkAttachments($attach)
    {
        $this->_linkAttach = $attach;
    }

    /**
     * Add uploaded files from form data.
     *
     * @param string $field    The field prefix (numbering starts at 1).
     * @param string $disp     The prefix for a file disposition input
     *                         (numbering starts at 1).
     * @param boolean $notify  Add a notification message for each successful
     *                         attachment?
     *
     * @return boolean  Returns false if any file was unsuccessfully added.
     */
    public function addFilesFromUpload($field, $disp = null, $notify = false)
    {
        $success = true;

        /* Add new attachments. */
        for ($i = 1; $i <= count($_FILES); $i++) {
            $key = $field . $i;
            if (isset($_FILES[$key]) &&
                ($_FILES[$key]['error'] != 4)) {
                $filename = Util::dispelMagicQuotes($_FILES[$key]['name']);
                if (!empty($_FILES[$key]['error'])) {
                    switch ($_FILES[$key]['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $GLOBALS['notification']->push(sprintf(_("Did not attach \"%s\" as the maximum allowed upload size has been exceeded."), $filename), 'horde.warning');
                        break;

                    case UPLOAD_ERR_PARTIAL:
                        $GLOBALS['notification']->push(sprintf(_("Did not attach \"%s\" as it was only partially uploaded."), $filename), 'horde.warning');
                        break;

                    default:
                        $GLOBALS['notification']->push(sprintf(_("Did not attach \"%s\" as the server configuration did not allow the file to be uploaded."), $filename), 'horde.warning');
                        break;
                    }
                    $success = false;
                } elseif ($_FILES[$key]['size'] == 0) {
                    $GLOBALS['notification']->push(sprintf(_("Did not attach \"%s\" as the file was empty."), $filename), 'horde.warning');
                    $success = false;
                } else {
                    $disposition = ($disp === null) ? 'attachment' : Util::getFormData($disp . $i);
                    $result = $this->addUploadAttachment($key, $disposition);
                    if (is_a($result, 'PEAR_Error')) {
                        $GLOBALS['notification']->push($result, 'horde.error');
                        $success = false;
                    } elseif ($notify) {
                        $GLOBALS['notification']->push(sprintf(_("Added \"%s\" as an attachment."), $result), 'horde.success');
                    }
                }
            }
        }

        return $success;
    }

    /**
     * Shortcut function to convert text -> HTML for purposes of composition.
     *
     * @param string $msg  The message text.
     *
     * @return string  HTML text.
     */
    public function text2html($msg)
    {
        require_once 'Horde/Text/Filter.php';
        return Text_Filter::filter($msg, 'text2html', array('parselevel' => TEXT_HTML_MICRO_LINKURL, 'class' => null, 'callback' => null));
    }

    /**
     * Generates html body text for use in the compose screen.
     *
     * @param string $body                The body text.
     * @param IMP_Contents $imp_contents  An IMP_Contents object.
     * @param Horde_Mime_Message $mime_message  A Horde_Mime_Message object.
     *
     * @return string  The body text.
     */
    protected function _getHtmlText($body, $imp_contents, $mime_message)
    {
        $this->_findhtml = true;
        $body = String::convertCharset(
            $this->_applyReplyLimit($body,
                                    $this->getBodyCharset($imp_contents)),
            $this->getBodyCharset($imp_contents));
        $this->_findhtml = false;
        return $body;
    }

    /**
     * Removes excess text if string exceeds reply limit.
     *
     * @param string $body     The body text.
     * @param string $charset  The body charset.
     *
     * @return string  The body text with reply limit applied.
     */
    protected function _applyReplyLimit($body, $charset)
    {
        if (!empty($GLOBALS['conf']['compose']['reply_limit'])) {
            $limit = $GLOBALS['conf']['compose']['reply_limit'];
            if (strlen($body) > $limit) {
                return substr($body, 0, $limit) . "\n" . String::convertCharset(_("[Truncated Text]"), NLS::getCharset(), $charset);
            }
        }
        return $body;
    }

    /**
     * Store draft compose data if session expires.
     */
    public function sessionExpireDraft()
    {
        if (empty($GLOBALS['conf']['compose']['use_vfs'])) {
            return;
        }

        $imp_ui = new IMP_UI_Compose();

        $headers = array();
        foreach (array('to', 'cc', 'bcc', 'subject') as $val) {
            $headers[$val] = $imp_ui->getAddressList(Util::getFormData($val), Util::getFormData($val . '_list'), Util::getFormData($val . '_field'), Util::getFormData($val . '_new'));
        }

        $body = $this->_saveDraftMsg($headers, Util::getFormData('message', ''), Util::getFormData('charset'), Util::getFormData('rtemode'), false);
        if (is_a($body, 'PEAR_Error')) {
            return;
        }

        require_once 'VFS.php';
        $vfs = &VFS::singleton($GLOBALS['conf']['vfs']['type'], Horde::getDriverConfig('vfs', $GLOBALS['conf']['vfs']['type']));
        // TODO: Garbage collection?
        $result = $vfs->writeData(self::VFS_DRAFTS_PATH, md5(Util::getFormData('user')), $body, true);
        if (is_a($result, 'PEAR_Error')) {
            return;
        }

        $GLOBALS['notification']->push(_("The message you were composing has been saved as a draft. The next time you login, you may resume composing your message."));
    }

    /**
     * Restore session expiration draft compose data.
     */
    public function recoverSessionExpireDraft()
    {
        if (empty($GLOBALS['conf']['compose']['use_vfs'])) {
            return;
        }

        $filename = md5($_SESSION['imp']['uniquser']);
        require_once 'VFS.php';
        $vfs = &VFS::singleton($GLOBALS['conf']['vfs']['type'], Horde::getDriverConfig('vfs', $GLOBALS['conf']['vfs']['type']));
        if ($vfs->exists(self::VFS_DRAFTS_PATH, $filename)) {
            $data = $vfs->read(self::VFS_DRAFTS_PATH, $filename);
            if (is_a($data, 'PEAR_Error')) {
                return;
            }
            $vfs->deleteFile(self::VFS_DRAFTS_PATH, $filename);

            $drafts_folder = IMP::folderPref($GLOBALS['prefs']->getValue('drafts_folder'), true);
            if (empty($drafts_folder)) {
                return;
            }
            $res = $this->_saveDraftServer($data, $drafts_folder);
            if (!is_a($res, 'PEAR_Error')) {
                $GLOBALS['notification']->push(_("A message you were composing when your session expired has been recovered. You may resume composing your message by going to your Drafts folder."));
            }
        }
    }

    /**
     * Formats the address properly.
     *
     * This method can be called statically, i.e.:
     *   $ret = IMP_Compose::formatAddr();
     *
     * @param string $addr  The address to format.
     *
     * @return string  The formatted address.
     */
    static public function formatAddr($addr)
    {
        /* If there are angle brackets (<>), or a colon (group name
         * delimiter), assume the user knew what they were doing. */
        if (!empty($addr) &&
            (strpos($addr, '>') === false) &&
            (strpos($addr, ':') === false)) {
            $addr = trim(strtr($addr, ';,', '  '));
            $addr = preg_replace('|\s+|', ', ', $addr);
        }

        return $addr;
    }

    /**
     * Uses the Registry to expand names and return error information for
     * any address that is either not valid or fails to expand. This function
     * will not search if the address string is empty.
     *
     * This method can be called statically, i.e.:
     *   $ret = IMP_Compose::expandAddresses();
     *
     * @param string $addrString  The name(s) or address(es) to expand.
     *
     * @return array  All matching addresses.
     */
    static public function expandAddresses($addrString)
    {
        if (!preg_match('|[^\s]|', $addrString)) {
            return '';
        }

        return IMP_Compose::getAddressList(reset(array_filter(array_map('trim', Horde_Mime_Address::explode($addrString, ',;')))));
    }

    /**
     * Uses the Registry to expand names and return error information for
     * any address that is either not valid or fails to expand.
     *
     * This method can be called statically, i.e.:
     *   $ret = IMP_Compose::expandAddresses();
     *
     * @param string $search  The term to search by.
     *
     * @return array  All matching addresses.
     */
    public static function getAddressList($search = '')
    {
        $sparams = IMP_Compose::getAddressSearchParams();
        $res = $GLOBALS['registry']->call('contacts/search', array($search, $sparams['sources'], $sparams['fields'], true));
        if (is_a($res, 'PEAR_Error')) {
            Horde::logMessage($res, __FILE__, __LINE__, PEAR_LOG_ERR);
            return array();
        }
        if (!count($res)) {
            return array();
        }

        /* The first key of the result will be the search term. The matching
         * entries are stored underneath this key. */
        $search = array();
        foreach (reset($res) as $val) {
            if (!empty($val['email'])) {
                if (strpos($val['email'], ',') !== false) {
                    $search[] = Horde_Mime_Address::encode($val['name'], 'personal') . ': ' . $val['email'] . ';';
                } else {
                    $mbox_host = explode('@', $val['email']);
                    if (isset($mbox_host[1])) {
                        $search[] = Horde_Mime_Address::writeAddress($mbox_host[0], $mbox_host[1], $val['name']);
                    }
                }
            }
        }

        return $search;
    }

    /**
     * Determines parameters needed to do an address search
     *
     * This method can be called statically, i.e.:
     *   $ret = IMP_Compose::getAddressSearchParams();
     *
     * @return array  An array with two keys: 'sources' and 'fields'.
     */
    public static function getAddressSearchParams()
    {
        $src = explode("\t", $GLOBALS['prefs']->getValue('search_sources'));
        if ((count($src) == 1) && empty($src[0])) {
            $src = array();
        }

        $fields = array();
        if (($val = $GLOBALS['prefs']->getValue('search_fields'))) {
            $field_arr = explode("\n", $val);
            foreach ($field_arr as $field) {
                $field = trim($field);
                if (!empty($field)) {
                    $tmp = explode("\t", $field);
                    if (count($tmp) > 1) {
                        $source = array_splice($tmp, 0, 1);
                        $fields[$source[0]] = $tmp;
                    }
                }
            }
        }

        return array('sources' => $src, 'fields' => $fields);
    }
}
