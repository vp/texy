<?php

/**
 * Texy! universal text -> html converter
 * --------------------------------------
 *
 * This source file is subject to the GNU GPL license.
 *
 * @author     David Grudl aka -dgx- <dave@dgx.cz>
 * @link       http://texy.info/
 * @copyright  Copyright (c) 2004-2006 David Grudl
 * @license    GNU GENERAL PUBLIC LICENSE v2
 * @package    Texy
 * @category   Text
 * @version    1.2 for PHP5 ONLY $Revision$ $Date$
 */


if (version_compare(PHP_VERSION , '5.0.0', '<'))
    die('Texy!: too old version of PHP!');

define('TEXY', 'Version 1.2 for PHP5 $Revision$');

/**
 * Absolute filesystem path to the Texy package
 */
define('TEXY_DIR',  dirname(__FILE__).'/');

require_once TEXY_DIR.'libs/constants.php';      // regular expressions & other constants
require_once TEXY_DIR.'libs/modifier.php';       // modifier processor
require_once TEXY_DIR.'libs/url.php';            // object encapsulate of URL
require_once TEXY_DIR.'libs/dom.php';            // Texy! DOM element's base class
require_once TEXY_DIR.'libs/module.php';         // Texy! module base class
require_once TEXY_DIR.'libs/parser.php';         // Texy! parser
require_once TEXY_DIR.'libs/html.php';
require_once TEXY_DIR.'libs/wellform.php';
require_once TEXY_DIR.'modules/block.php';
require_once TEXY_DIR.'modules/formatter.php';
require_once TEXY_DIR.'modules/generic-block.php';
require_once TEXY_DIR.'modules/heading.php';
require_once TEXY_DIR.'modules/horiz-line.php';
require_once TEXY_DIR.'modules/html-tag.php';
require_once TEXY_DIR.'modules/image.php';
require_once TEXY_DIR.'modules/image-description.php';
require_once TEXY_DIR.'modules/link.php';
require_once TEXY_DIR.'modules/list.php';
require_once TEXY_DIR.'modules/definition-list.php';
require_once TEXY_DIR.'modules/long-words.php';
require_once TEXY_DIR.'modules/phrase.php';
require_once TEXY_DIR.'modules/quick-correct.php';
require_once TEXY_DIR.'modules/quote.php';
require_once TEXY_DIR.'modules/script.php';
require_once TEXY_DIR.'modules/table.php';
require_once TEXY_DIR.'modules/smilies.php';


/**
 * Texy! - Convert plain text to XHTML format using {@link process()}
 *
 * <code>
 *     $texy = new Texy();
 *     $html = $texy->process($text);
 * </code>
 */
class Texy
{
    // configuration directives
    const ALL = TRUE;
    const NONE = FALSE;

    /** @var boolean  Use UTF-8? (texy configuration) */
    public $utf = FALSE;

    /** @var int  TAB width (for converting tabs to spaces) */
    public $tabWidth = 8;

    /** @var TRUE|FALSE|array  Allowed classes */
    public $allowedClasses = Texy::ALL;

    /** @var TRUE|FALSE|array  Allowed inline CSS style */
    public $allowedStyles = Texy::ALL;

    /** @var TRUE|FALSE|array  Allowed HTML tags */
    public $allowedTags;

    /** @var boolean  Do obfuscate e-mail addresses? */
    public $obfuscateEmail = TRUE;

    /** @var TexyDom  DOM structure for parsed text */
    private $DOM;

    /** @var array  Parsing summary */
    public $summary;

    /** @var string  Generated stylesheet */
    public $styleSheet = '';

    /** @var bool  Merge lines mode */
    public $mergeLines = TRUE;


    /**
     * Registered regexps and associated handlers for inline parsing
     * @var array Format: ('handler' => callback,
     *                     'pattern' => regular expression,
     *                     'user'    => user arguments)
     */
    public $patternsLine;

    /**
     * Registered regexps and associated handlers for block parsing
     * @var array Format: ('handler' => callback,
     *                     'pattern' => regular expression,
     *                     'user'    => user arguments)
     */
    public $patternsBlock;


    /** @var array    List of all used modules */
    public $modules;

    /**
     * Reference stack
     * @var array Format: ('home' => TexyLinkReference, ...)
     */
    private $references       = array();

    public $referenceHandler;

    /**
     * prevent recursive calling
     * @var boolean
     */
    public $preventCycling  = false;

    /** @var object    Default modules */
    public
        $scriptModule,
        $htmlModule,
        $imageModule,
        $linkModule,
        $phraseModule,
        $smiliesModule,
        $blockModule,
        $headingModule,
        $horizLineModule,
        $quoteModule,
        $listModule,
        $definitionListModule,
        $tableModule,
        $imageDescModule,
        $genericBlockModule,
        $quickCorrectModule,
        $longWordsModule,
        $formatterModule;



    public function __construct()
    {
        // init some other variables
        $this->summary['images']  = array();
        $this->summary['links']   = array();
        $this->summary['preload'] = array();

        // !!!
        $this->allowedTags    = TexyHtml::$valid; // full support for HTML tags

        // load all modules
        $this->loadModules();

        // example of link reference ;-)
        /*
        $elRef = new TexyLinkReference($this, 'http://texy.info/', 'Texy!');
        $elRef->modifier->title = 'Text to HTML converter and formatter';
        $this->addReference('Texy', $elRef);
        */
    }




    /**
     * Create array of all used modules ($this->modules)
     * This array can be changed by overriding this method (by subclasses)
     * or directly in main code
     */
    protected function loadModules()
    {
        // Line parsing - order is not much important
        $this->scriptModule = new TexyScriptModule($this);
        $this->htmlModule = new TexyHtmlModule($this);
        $this->imageModule = new TexyImageModule($this);
        $this->linkModule = new TexyLinkModule($this);
        $this->phraseModule = new TexyPhraseModule($this);
        $this->smiliesModule = new TexySmiliesModule($this);

        // block parsing - order is not much important
        $this->blockModule = new TexyBlockModule($this);
        $this->headingModule = new TexyHeadingModule($this);
        $this->horizLineModule = new TexyHorizLineModule($this);
        $this->quoteModule = new TexyQuoteModule($this);
        $this->listModule = new TexyListModule($this);
        $this->definitionListModule = new TexyDefinitionListModule($this);
        $this->tableModule = new TexyTableModule($this);
        $this->imageDescModule = new TexyImageDescModule($this);
        $this->genericBlockModule = new TexyGenericBlockModule($this);

        // post process
        $this->quickCorrectModule = new TexyQuickCorrectModule($this);
        $this->longWordsModule = new TexyLongWordsModule($this);
        $this->formatterModule = new TexyFormatterModule($this);  // should be last post-processing module!
    }



    public function registerModule($module)
    {
        $this->modules[] = $module;
    }



    public function registerLinePattern($module, $method, $pattern, $user_args = NULL)
    {
        $this->patternsLine[] = array(
            'handler'     => array($module, $method),
            'pattern'     => $this->translatePattern($pattern),
            'user'        => $user_args
        );
    }


    public function registerBlockPattern($module, $method, $pattern, $user_args = NULL)
    {
//    if (!preg_match('#(.)\^.*\$\\1[a-z]*#is', $pattern)) die('Texy: Not a block pattern. Module '.get_class($module).', pattern '.htmlSpecialChars($pattern));

        $this->patternsBlock[] = array(
            'handler'     => array($module, $method),
            'pattern'     => $this->translatePattern($pattern)  . 'm',  // force multiline!
            'user'        => $user_args
        );
    }

    /**
     * Initialization
     * It is called between constructor and first use (method parse)
     */
    private function init()
    {
        $this->patternsLine   = array();
        $this->patternsBlock  = array();

        if (!$this->modules) die('Texy: No modules installed');

        // init modules
        foreach ($this->modules as $module)
            $module->init();
    }



    /**
     * Convert Texy! document in (X)HTML code
     * This is shortcut for parse() & DOM->toHtml()
     * @return string
     */
    public function process($text, $singleLine = FALSE)
    {
        if ($singleLine)
            $this->parseLine($text);
        else
            $this->parse($text);

        return $this->DOM->toHtml();
 }






    /**
     * Convert Texy! document into internal DOM structure ($this->DOM)
     * Before converting it normalize text and call all pre-processing modules
     */
    public function parse($text)
    {
            // initialization
        $this->init();

            ///////////   PROCESS
        $this->DOM = new TexyDom($this);
        $this->DOM->parse($text);
    }





    /**
     * Convert Texy! single line text into internal DOM structure ($this->DOM)
     */
    public function parseLine($text)
    {
            // initialization
        $this->init();

            ///////////   PROCESS
        $this->DOM = new TexyDomLine($this);
        $this->DOM->parse($text);
    }




    /**
     * Convert internal DOM structure ($this->DOM) to (X)HTML code
     * and call all post-processing modules
     * @return string
     */
    public function toHtml()
    {
        return $this->DOM->toHtml();
    }




    /**
     * Convert internal DOM structure ($this->DOM) to pure Text
     * @return string
     */
    public function toText()
    {
        // generate output
        $saveLineWrap = $this->formatterModule->lineWrap = false;
        $this->formatterModule->lineWrap = false;

        $text = $this->toHtml();

        $this->formatterModule->lineWrap = $saveLineWrap;

        // remove tags
        $text = preg_replace('#<(script|style)(.*)</\\1>#Uis', '', $text);
        $text = strip_tags($text);
        $text = preg_replace('#\n\s*\n\s*\n[\n\s]*\n#', "\n\n", $text);

        // entities -> chars
        if ((int) PHP_VERSION > 4 && $this->utf) { // fastest way for PHP 5 & UTF-8
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        } else {
            // only allowed named entities
            $text = strtr($text, array('&amp;'=>'&#38;', '&quot;'=>'&#34;', '&lt;'=>'&#60;', '&gt;'=>'&#62;'));

            // numeric
            $text = preg_replace_callback(
                '#&(\\#x[0-9a-fA-F]+|\\#[0-9]+);#',
                array($this, '_entityCallback'),
                $text
            );
        }

        // convert nbsp to normal space and remove shy
        $text = strtr($text, array(
            $this->utf ? "\xC2\xAD" : "\xAD" => '',  // shy
            $this->utf ? "\xC2\xA0" : "\xA0" => ' ', // nbsp
        ));

        return $text;
    }





    /**
     * Callback for preg_replace_callback() in toText()
     *
     * @param array    matched entity
     * @return string  decoded entity
     */
    private function _entityCallback($matches)
    {
        list(, $entity) = $matches;

        $ord = ($entity{1} == 'x')
             ? hexdec(substr($entity, 2))
             : (int) substr($entity, 1);

        if ($ord<128)  // ASCII
            return chr($ord);

        if ($this->utf) {
            if ($ord<2048) return chr(($ord>>6)+192) . chr(($ord&63)+128);
            if ($ord<65536) return chr(($ord>>12)+224) . chr((($ord>>6)&63)+128) . chr(($ord&63)+128);
            if ($ord<2097152) return chr(($ord>>18)+240) . chr((($ord>>12)&63)+128) . chr((($ord>>6)&63)+128) . chr(($ord&63)+128);
            return $match; // invalid entity
        }

        if (function_exists('iconv')) {
            return (string) iconv(
                'UCS-2',
                'WINDOWS-1250//TRANSLIT',
                pack('n', $ord)
            );
        }

        return '?';
    }




    /**
     * Switch Texy and default modules to safe mode
     * Suitable for 'comments' and other usages, where input text may insert attacker
     */
    public function safeMode()
    {
        $this->allowedClasses = Texy::NONE;                  // no class or ID are allowed
        $this->allowedStyles  = Texy::NONE;                  // style modifiers are disabled
        $this->htmlModule->safeMode();                      // only HTML tags and attributes specified in $safeTags array are allowed
        $this->imageModule->allowed = FALSE;                // disable images
        $this->linkModule->forceNoFollow = TRUE;            // force rel="nofollow"
    }




    /**
     * Switch Texy and default modules to (default) trust mode
     */
    public function trustMode()
    {
        $this->allowedClasses = Texy::ALL;                   // classes and id are allowed
        $this->allowedStyles  = Texy::ALL;                   // inline styles are allowed
        $this->htmlModule->trustMode();                     // full support for HTML tags
        $this->imageModule->allowed = TRUE;                 // enable images
        $this->linkModule->forceNoFollow = FALSE;           // disable automatic rel="nofollow"
    }







    /**
     * Translate all white spaces (\t \n \r space) to meta-spaces \x15-\x18
     * which are ignored by some formatting functions
     * @return string
     * @static
     */
    static public function freezeSpaces($s)
    {
        return strtr($s, " \t\r\n", "\x15\x16\x17\x18");
    }


    /**
     * Revert meta-spaces back to normal spaces
     * @return string
     * @static
     */
    static public function unfreezeSpaces($s)
    {
        return strtr($s, "\x15\x16\x17\x18", " \t\r\n");
    }



    /**
     * remove special controls chars used by Texy!
     * @return string
     * @static
     */
    static public function wash($text)
    {
            ///////////   REMOVE SPECIAL CHARS (used by Texy!)
        return strtr($text, "\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F", '           ');
    }






    /**
     * @static
     */
    static public function isHashOpening($hash)
    {
        return $hash{1} == "\x1F";
    }




    /**
     * Add new named reference
     */
    public function addReference($name, $obj)
    {
        if (!$this->utf) $name = strtolower($name);
        $this->references[$name] = $obj;
    }




    /**
     * Receive new named link. If not exists, try
     * call user function to create one.
     */
    private $_disableReferences;
    private $refQueries;
    function getReference($name)
    {
        if (!$this->utf) $name = strtolower($name);

        if ($this->preventCycling) {
            if (isset($this->refQueries[$name])) return FALSE;
            $this->refQueries[$name] = true;
        } else $this->refQueries = array();

        if (isset($this->references[$name]))
            return $this->references[$name];


        if ($this->referenceHandler) {
            $this->_disableReferences = true;
            $this->references[$name] = call_user_func_array(
                                     $this->referenceHandler,
                                     array($name, $this)
            );
            $this->_disableReferences = false;

            return $this->references[$name];
        }

        return FALSE;
    }



    /**
     * For easier regular expression writing
     * @return string
     */
    public function translatePattern($pattern)
    {
        return strtr($pattern, array(
            '<MODIFIER_HV>' => TEXY_PATTERN_MODIFIER_HV,
            '<MODIFIER_H>'  => TEXY_PATTERN_MODIFIER_H,
            '<MODIFIER>'    => TEXY_PATTERN_MODIFIER,
            '<LINK>'        => TEXY_PATTERN_LINK,
            '<UTF>'         => ($this->utf ? 'u' : ''),
            ':CHAR:'        => ($this->utf ? TEXY_CHAR_UTF : TEXY_CHAR),
            ':HASH:'        => TEXY_HASH,
            ':HASHSOFT:'    => TEXY_HASH_NC,
        ));
    }



    public function getModules()
    {
        return $this->modules;
    }


    /**
     * experimental
     */
    public function free()
    {
        foreach (array_keys(get_object_vars($this)) as $key)
            $this->$key = NULL;
    }



    /**
     * Undefined property usage prevention
     */
    function __set($nm, $val)     { $c=get_class($this); die("Undefined property '$c::$$nm'"); }
    function __get($nm)           { $c=get_class($this); die("Undefined property '$c::$$nm'"); }
    private function __unset($nm) { $c=get_class($this); die("Cannot unset property '$c::$$nm'."); }
    private function __isset($nm) { return FALSE; }

} // Texy
