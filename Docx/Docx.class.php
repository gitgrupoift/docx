<?php
/**
 * Created by PhpStorm.
 * User: philg
 * Date: 29/04/2018
 * Time: 16:47
 */
namespace Docx ;
use Docx\Nodes\Node;
use Docx\Nodes\Para;
use Docx\Nodes\Table;

/**
 * Class Docx
 * @desc Prepares xPath & domDocument for loaded .docx file,
 * and processes elements into internal Node & Run objects
 * @package Docx
 */
class Docx extends DocxFileManipulation {
    /**
     * @var null  | \DOMXPath
     */
    protected $_xPath = null ;

    /**
     * @var Nodes\Node[]
     * @desc Track constructed Nodes
     */
    protected $_constructedNodes = [];

    /**
     * Docx constructor.
     * @param $fileUri
     */
    public function __construct($fileUri){
        parent::__construct($fileUri);
        try {
            $this->_loadNodes();
        } catch (\Exception $e) {
            var_dump($e);
            die;
        }
    }



    /**
     * @desc Pull out the primary data containers ( nodes ) that have different types depending on content type
     * @throws \Exception
     */
    private function _loadNodes(){
        /*
         * Prepare the DomDocument
         */
        $dom = new \DOMDocument();
        $dom->loadXML($this->_xmlStructure, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
        $dom->encoding = 'utf-8';

        /*
         * Set up xPath for improved dom navigating
         */
        $xPath = new \DOMXPath($dom);
        $xPath->registerNamespace('mc', "http://schemas.openxmlformats.org/markup-compatibility/2006");
        $xPath->registerNamespace('wp', "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing");
        $xPath->registerNamespace('w', "http://schemas.openxmlformats.org/wordprocessingml/2006/main");
        $xPath->registerNamespace('a', "http://schemas.openxmlformats.org/drawingml/2006/main");
        $xPath->registerNamespace('pic', "http://schemas.openxmlformats.org/drawingml/2006/picture");
        $xPath->registerNamespace('v', "urn:schemas-microsoft-com:vml");
        $this->_xPath = $xPath;

        /*
         * Now we need to load up the root node, and then iterate through recursively
         */
        $bodyElementResult = $xPath->query('//w:body'); // //w:drawing | //w:txbxContent | //w:tbl | //w:p'
        if ($bodyElementResult->length > 0 ) {
            $bodyElement = $bodyElementResult->item(0 ) ;
            $this->loadNodesFromElement($bodyElement) ;
        } else {
            throw new \Exception('No Body element found');
        }


    }

    /**
     * @param $domElement \DOMElement
     * @param bool $bIsFromRootElement
     * @return Node[]
     */
    public function loadNodesFromElement($domElement, $bIsFromRootElement = true ){
        $ret = [];
        foreach ($domElement->childNodes as $childNode){
            /**
             * @var $childNode \DOMElement
             */
            $node = null ;
            switch ($childNode->tagName){
                case 'w:tbl':
                    $node = new Table($this, $childNode);
                    break;
                case 'w:p':
                    $node = new Para($this, $childNode);
                    break;
            }
            if (is_object($node)) {
                $node->attachToDocx($this, $bIsFromRootElement );

                $ret[] = $node;
            }
        }

        $ret  = $this->_listPostProcessor( $ret );

        return $ret ;
    }

    /**
     * @desc Modifies $this->>_constructed nodes, and finds the relevant list tags
     * and modifies the sibling nodes prepend/append attributes as needed
     * @param $nodeArr Node[]
     * @return Node[]
     */
    protected function _listPostProcessor($nodeArr ){
        $currentListLevel = 0;
        foreach ($nodeArr as $i =>  $node ) {
            /*
             * Override the node type
             */
            if ($node->getListLevel() > 0) $node->setType('listitem');
            /*
             * Get class attribute (if any)
             */
            $liClassStr = '';
            if (is_object($node->getStyle())){
                $styleData = $node->getStyle();
                if ($styleData->getHtmlClass() != '')
                    $liClassStr = ' class="' . $styleData->getHtmlClass() . '"';
            }

            /*
             * List tag calculations
             */
            if ($currentListLevel > $node->getListLevel()){
                for ($loopI = $currentListLevel; $loopI > $node->getListLevel(); $loopI--){
                    $nodeArr[$i - 1]->appendAdditional('</li></ul>');
                }
            } else {
                if ($currentListLevel > 0 && $currentListLevel == $node->getListLevel()) {
                    $nodeArr[$i - 1]->appendAdditional('</li>');
                }
            }
            if ($currentListLevel < $node->getListLevel()){
                for ($loopI = $currentListLevel; $loopI < $node->getListLevel(); $loopI++){
                    $node->prependAdditional('<ul><li' . $liClassStr . '>');
                }
            } else {
                if ($currentListLevel > 0  ) {
                    $node->prependAdditional('<li' . $liClassStr . '>');
                }
            }
            $currentListLevel = $node->getListLevel();
        }
        return $nodeArr;
    }

    /**
     * @desc Attaches a given Node to $this
     * @param $nodeObj Nodes\Node
     */
    public function attachNode($nodeObj){
        $this->_constructedNodes[] = $nodeObj;
    }


    /**
     * @param string $renderViewType
     * @return string
     */
    public function render($renderViewType = 'html'){
        $ret = '';
        foreach ($this->_constructedNodes as $constructedNode){
            $ret .=  $constructedNode->render($renderViewType);
        }
        return $ret ;

    }

    /**
     * @return \DOMXPath|null
     */
    public function getXPath(){
        return $this->_xPath;
    }


    /**
     * @param $rawString string
     * @desc Given a string, we process out any characters that cannot be output for an htmlId attribute
     * @return string
     */
    public static function getHtmlIdFromString($rawString){
        $ret = 'docx_' . $rawString;
        $ret = str_replace(['&nbsp;', " "], ["", '_'], $ret);
        $ret = trim(strip_tags($ret));
        $ret = preg_replace("/[^A-Za-z0-9_]/", '', $ret);
        return $ret;
    }


    /**
     * @param string | null $linkupId
     * @return LinkAttachment[] | LinkAttachment
     */
    public function getAttachedLinks($linkupId = null){
        if ($linkupId == null ) {
            return $this->_linkAttachments;
        }
        $ret = [];
        foreach ($this->_linkAttachments as $linkAttachment ){
            if ($linkAttachment->getLinkupId() == $linkupId) {
                $ret = $linkAttachment;
            }
        }
        return $ret ;
    }

    /**
     * @param string | null $imageLinkupId
     * @return FileAttachment[] | FileAttachment
     */
    public function getAttachedFiles($imageLinkupId = null){
        if ($imageLinkupId == null) {
            return $this->_fileAttachments;
        }
        $ret = [] ;
        foreach ($this->_fileAttachments as $fileAttachment) {
            if ($fileAttachment->getLinkupId() == $imageLinkupId){
                $ret = $fileAttachment;
            }
        }
        return $ret;
    }

    /**
     * @desc Converts internal docx measurment into px
     * @param $twip int
     * @return int
     */
    public function twipToPt($twip){
        $px = round($twip / 20);
        return $px;
    }


}