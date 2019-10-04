<?php
/**
 * DokuWiki Plugin ExtList (Syntax component)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 *
 */
if (!defined('DOKU_INC')) die();

class syntax_plugin_extlist extends DokuWiki_Syntax_Plugin
{
    public function getType()
    {   // Syntax Type
        return 'container';
    }

    public function getAllowedTypes()
    {   // Allowed Mode Types
        return array(
            'formatting',
            'substition',
            'disabled',
            'protected',
        );
    }

    public function getPType()
    {   // Paragraph Type
        return 'block';
    }

    /**
     * Connect pattern to lexer
     */
    protected $mode;
    protected $macro_pattern;
    protected $entry_pattern, $match_pattern, $extra_pattern, $exit_pattern;

    public function preConnect()
    {
        // drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // macro to specify list class
        $this->macro_pattern = '\n(?: {2,}|\t{1,})~~(?:dl|ol|ul):[\w -]*?~~';

        // list patterns
        $olist_pattern    = '\-?\d+[.:] |-:?'; // ordered list item
        $ulist_pattern    = '\*:?';            // unordered list items
        $dlist_pattern    = ';;?|::?';         // description list item
        $continue_pattern = '\+:?';            // continued contents to the previous item

        $this->entry_pattern = '\n(?: {2,}|\t{1,})'.'(?:'
                       . $olist_pattern .'|'
                       . $ulist_pattern .'|'
                       . $dlist_pattern . ') *';
        $this->match_pattern = '\n(?: {2,}|\t{1,})'.'(?:'
                       . $olist_pattern .'|'
                       . $ulist_pattern .'|'
                       . $dlist_pattern .'|'
                       . $continue_pattern . ') *';

        // continued item content by indentation
        $this->extra_pattern = '\n(?: {2,}|\t{1,})(?![-*;:?+~])';

        $this->exit_pattern  = '\n';
    }

    public function connectTo($mode)
    {
        $this->Lexer->addEntryPattern('[ \t]*'.$this->entry_pattern, $mode, $this->mode);

        // macro syntax to specify class for next list [ul|ol|dl]
        $this->Lexer->addSpecialPattern('[ \t]*'.$this->macro_pattern, $mode, $this->mode);
        $this->Lexer->addPattern($this->macro_pattern, $this->mode);
    }

    public function postConnect()
    {
        // subsequent list item
        $this->Lexer->addPattern($this->match_pattern, $this->mode);
        $this->Lexer->addPattern('  ::? ', $this->mode);  // dt and dd in one line

        // continued list item content, indented by at least two spaces
        $this->Lexer->addPattern($this->extra_pattern, $this->mode);

        // terminate a list block
        $this->Lexer->addExitPattern($this->exit_pattern, $this->mode);
    }

    public function getSort()
    {   // sort number used to determine priority of this mode
        return 9; // just before listblock (10)
    }


    /**
     * Plugin features
     */
    protected $stack = array();
    protected $list_class = array(); // store class specified by macro

    // Enable hierarchical numbering for nested ordered lists
    protected $olist_level = 0;
    protected $olist_info = array();

    protected $use_div = true;

    protected $state_map = array(
        'open'  => DOKU_LEXER_ENTER,
        'close' => DOKU_LEXER_EXIT,
    );


    /**
     * get markup and depth from the match
     *
     * @param $match string 
     * @return array
     */
    protected function interpret($match)
    {
        // depth: count double spaces indent after '\n'
        $depth = substr_count(str_replace("\t", '  ', ltrim($match,' ')),'  ');
        $match = trim($match);

        $m = array('depth' => $depth);

        // check order list markup with number
        if (preg_match('/^(-?\d+)([.:])/', $match, $matches)) {
            $m += array(
                    'mk' => ($matches[2] == '.') ? '-' : '-:',
                    'list' => 'ol', 'item' => 'li', 'num' => $matches[1]
            );
            if ($matches[2] == ':') $m += array('p' => 1);
        } else {
            $m += array('mk' => $match);

            switch ($match[0]) {
                case '' :
                    $m += array('list' => NULL, 'item' => NULL);
                    break;
                case '+':
                    $m += array('list' => NULL, 'item' => NULL);
                    if ($match == '+:') {
                        $m += array('p' => 1);
                    } else {
                        $m['mk'] = '';
                    }
                    break;
                case '-': // ordered list
                    $m += array('list' => 'ol', 'item' => 'li');
                    if ($match == '-:') $m += array('p' => 1);
                    break;
                case '*': // unordered list
                    $m += array('list' => 'ul', 'item' => 'li');
                    if ($match == '*:') $m += array('p' => 1);
                    break;
                case ';': // description list term
                    $m += array('list' => 'dl', 'item' => 'dt');
                    if ($match == ';') $m += array('class' => 'compact');
                    break;
                case ':': // description list desc
                    $m += array('list' => 'dl', 'item' => 'dd');
                    if ($match == '::') $m += array('p' => 1);
                    break;
            }
        }
        //error_log('extlist intpret: $m='.var_export($m,1));
        return $m;
    }


    /**
     * check whether list type has changed
     *
     * @param $m0 array  interpreted match pattern of previous list item
     * @param $m1 array  interpreted match pattern of current list item
     * @return bool
     */
    private function isListTypeChanged($m0, $m1)
    {
        return (strncmp($m0['list'], $m1['list'], 1) !== 0);
    }

    /**
     * create marker for ordered list items
     * 
     * @return string
     */
    private function olist_marker($level)
    {
        $num = $this->olist_info[$level];
        //error_log('olist lv='.$level.' list_class='.$this->list_class['ol'].' num='.$num);

        // Parenthesized latin small letter marker: ⒜,⒝,⒞, … ,⒵
        if (strpos($this->list_class['ol'], 'alphabet') !== false){
            $modulus = ($num -1) % 26;
            $marker = '&#'.(9372 + $modulus).';';
            return $marker;
        }

        // Hierarchical numbering (default): eg. 1. | 2.1 | 3.2.9
        $marker = $this->olist_info[1];
        if ($level == 1) {
            return $marker.'.';
        } else {
            for ($i = 2; $i <= $level; $i++) {
                $marker .= '.'.$this->olist_info[$i];
            }
            return $marker;
        }
    }

    /**
     * srore class attribute for lists [ul|ol|dl] specfied by macro pattern
     * macro_pattern = ~~(?:dl|ol|ul):[\w -]*?~~
     */
    private function storeListClass($str)
    {
            $str = trim($str);
            $this->list_class[substr($str,2,2)] = trim(substr($str,5,-2));
    }


    /**
     * write call to open or close a list block [ul|ol|dl]
     */
    private function handleList($state, $m, $pos, Doku_Handler $handler)
    {
        if (array_key_exists($state, $this->state_map)) {
            $state = $this->state_map[$state];
        } else {
            return;
        }
        $tag = $m['list'];

        if ($state == DOKU_LEXER_ENTER) {
            // start value only for ordered list
            if ($tag == 'ol') {
                $attr = isset($m['num']) ? 'start="'.$m['num'].'"' : '';
                $this->olist_level++; // increase olist level
            }
            // list class
            $class = 'extlist';
            if (isset($this->list_class[$tag])) {
                // Note: list_class can be empty
                $class.= ' '.$this->list_class[$tag];
            } else {
                $class.= ' '.$this->getConf($tag.'_class');
            }
            $class = rtrim($class);
            $attr.= !empty($attr) ? ' ' : '';
            $attr.= ' class="'.$class.'"';
        } else {
            if ($tag == 'ol') {
                $this->olist_level--; // reduce olist level
            }
            $attr = '';
        }
        $match = array($state, $tag, $attr);
        $handler->plugin($match, 'addPluginCall', $pos, $this->getPluginName());
    }

    /**
     * write call to open or close a list item [li|dt|dd]
     */
    private function handleItem($state, $m, $pos, Doku_Handler $handler)
    {
        if (array_key_exists($state, $this->state_map)) {
            $state = $this->state_map[$state];
        } else {
            return;
        }
        $tag = $m['item'];
        if ($state == DOKU_LEXER_ENTER) {
            switch ($m['mk']) {
                case '-':
                case '-:':
                    // prepare hierarchical marker for nested ordered list item
                    $this->olist_info[$this->olist_level] = $m['num'];
                    $lv = $this->olist_level;
                    $attr = ' value="'.$m['num'].'"';
                    $attr.= ' data-marker="'.$this->olist_marker($lv).'"';
                    break;
                case ';':
                    $attr = 'class="'.$m['class'].'"';
                    break;
                default:
                    $attr = '';
            }
        } else {
            $attr = '';
        }
        $match = array($state, $tag, $attr);
        $handler->plugin($match, 'addPluginCall', $pos, $this->getPluginName());
    }

    /**
     * write call to open or close inner wrapper [div|span]
     */
    private function handleWrapper($state, $m, $pos, Doku_Handler $handler)
    {
        if (array_key_exists($state, $this->state_map)) {
            $state = $this->state_map[$state];
        } else {
            return;
        }
        switch ($m['mk']) {
            case ';':  // dl dt
            case ';;': // dl dt, explicitly no-compact
                $tag = 'span';
                $attr = '';
                break;
            case ':':  // dl dd
            case '::': // dl dd p
                return;
                break;
            default:
                if (!$this->use_div) return;
                $tag = 'div';
                $attr = ($state == DOKU_LEXER_ENTER) ? 'class="li"' : '';
                break;
        }
        $match = array($state, $tag, $attr);
        $handler->plugin($match, 'addPluginCall', $pos, $this->getPluginName());
    }

    /**
     * write call to open or close paragraph (p tag)
     */
    private function handleParagraph($state, $pos, Doku_Handler $handler)
    {
        if (array_key_exists($state, $this->state_map)) {
            $state = $this->state_map[$state];
        } else {
            return;
        }
        $match = array($state, 'p', '');
        $handler->plugin($match, 'addPluginCall', $pos, $this->getPluginName());
    }

    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        switch ($state) {
        case 'addPluginCall':
            // write plugin instruction to call list of the handler
            // Note: $match is array, not matched text
            return $data = $match;

        case DOKU_LEXER_SPECIAL:
            //  specify class attribute for lists [ul|ol|dl]
            $this->storeListClass($match);
            break;

        case DOKU_LEXER_ENTER:
            $m1 = $this->interpret($match);
            if (($m1['list'] == 'ol') && !isset($m1['num'])) {
                $m1['num'] = 1;
            }
 
            // open list tag [ul|ol|dl]
            $this->handleList('open', $m1, $pos, $handler);
            // open item tag [li|dt|dd]
            $this->handleItem('open', $m1, $pos, $handler);
            // open inner wrapper [div|span]
            $this->handleWrapper('open', $m1, $pos, $handler);
            // open p if necessary
            if (isset($m1['p'])) $this->handleParagraph('open', $pos, $handler);

            // add to stack
            array_push($this->stack, $m1);
            break;

        case DOKU_LEXER_UNMATCHED:
            // cdata --- use base() as _writeCall() is prefixed for private/protected
            $handler->base($match, $state, $pos);
            break;

        case DOKU_LEXER_EXIT:
            // clear list_class
            $this->list_class = array();
            // do not break here!

        case DOKU_LEXER_MATCHED:
            //  specify class attribute for lists [ul|ol|dl]
            if (substr($match, -2) == '~~') {
                $this->storeListClass($match);
                break;
            }

            // retrieve previous list item from stack
            $m0 = array_pop($this->stack);
            $m1 = $this->interpret($match);

            // set m1 depth if dt and dd are in one line
            if (($m1['depth'] == 0) && ($m0['item'] == 'dt')) {
                $m1['depth'] = $m0['depth'];
            }

            // continued list item content, indented by at least two spaces
            if (empty($m1['mk']) && ($m1['depth'] > 0)) {
                // !!EXPERIMENTAL SCRIPTIO CONTINUA concerns!! 
                // replace indent to single space, but leave it for LineBreak2 plugin
                $handler->base("\n",  DOKU_LEXER_UNMATCHED, $pos);

                // restore stack
                array_push($this->stack, $m0);
                break;
            }

            // close p if necessary
            if (isset($m0['p'])) $this->handleParagraph('close', $pos, $handler);

            // close inner wrapper [div|span] if necessary
            if ($m1['mk'] == '+:') {
                // Paragraph markup
                if ($m0['depth'] > $m1['depth']) {
                    $this->handleWrapper('close', $m0, $pos, $handler);
                } else {
                    // new paragraph can not be deeper than previous depth
                    // fix current depth quietly
                    $m1['depth'] = min($m0['depth'], $m1['depth']);
                }
                // fix previous p type
                $m0['p'] = 1;
            } else {
                // List item markup
                if ($m0['depth'] >= $m1['depth']) {
                    $this->handleWrapper('close', $m0, $pos, $handler);
                }
            }

            // List item becomes shallower - close deeper list
            while ($m0['depth'] > $m1['depth']) {
                // close item [li|dt|dd]
                $this->handleItem('close', $m0, $pos, $handler);
                // close list [ul|ol|dl]
                $this->handleList('close', $m0, $pos, $handler);

                $m0 = array_pop($this->stack);
            }

            // Break out of switch structure if end of list block
            if ($state == DOKU_LEXER_EXIT) {
                break;
            }

            // Paragraph markup
            if ($m1['mk'] == '+:') {
                $this->handleParagraph('open', $pos, $handler);
                $m1['depth'] = $m0['depth'];
                $m1 = $m0 + array('p' => 1);

                // restore stack
                array_push($this->stack, $m1);
                break;
            }

            // List item markup
            if ($m0['depth'] < $m1['depth']) { // list becomes deeper
                // restore stack
                array_push($this->stack, $m0);

            } else if ($m0['depth'] == $m1['depth']) {
                // close item [li|dt|dd]
                $this->handleItem('close', $m0, $pos, $handler);
                // close list [ul|ol|dl] if necessary
                if ($this->isListTypeChanged($m0, $m1)) {
                    $this->handleList('close', $m0, $pos, $handler);
                    $m0['num'] = 0;
                }
            }

            // open list [ul|ol|dl] if necessary
            if (($m0['depth'] < $m1['depth']) || ($m0['num'] === 0)) {
                if (!is_numeric($m1['num'])) $m1['num'] = 1;
                $this->handleList('open', $m1, $pos, $handler);
            } else {
                if (!is_numeric($m1['num'])) $m1['num'] = $m0['num']  +1;
            }

            // open item [li|dt|dd]
            $this->handleItem('open', $m1, $pos, $handler);
            // open inner wrapper [div|span]
            $this->handleWrapper('open', $m1, $pos, $handler);
            // open p if necessary
            if (isset($m1['p'])) $this->handleParagraph('open', $pos, $handler);

            // add to stack
            array_push($this->stack, $m1);

        } // end of switch
        return false;
    }


    /**
     * Create output
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        switch ($format) {
            case 'xhtml':
                return $this->render_xhtml($renderer, $data);
            //case 'latex':
            //    $latex = $this->loadHelper('extlist_latex');
            //    return $latex->render($renderer, $data);
            //case 'odt':
            //    $odt = $this->loadHelper('extlist_odt');
            //    return $odt->render($renderer, $data);
            default:
                return false;
        }
    }


    /**
     * Create xhtml output
     */
    protected function render_xhtml(Doku_Renderer $renderer, $data)
    {
        list($state, $tag, $attr) = $data;
        switch ($state) {
            case DOKU_LEXER_ENTER:   // open tag
                $renderer->doc.= $this->_open($tag, $attr);
                break;
            case DOKU_LEXER_EXIT:    // close tag
                $renderer->doc.= $this->_close($tag);
                break;
            default:
                // defensive, shouldn't occur
                return false;
        }
        return true;
    }

    /**
     * open a tag, a utility for render_xhtml()
     */
    protected function _open($tag, $attr = null)
    {
        if (!empty($attr)) $attr = ' '.$attr;
        list($before, $after) = $this->_tag_indent($tag);
        return $before.'<'.$tag.$attr.'>'.$after;
    }

    /**
     * close a tag, a utility for render_xhtml()
     */
    protected function _close($tag)
    {
        list($before, $after) = $this->_tag_indent('/'.$tag);
        return $before.'</'.$tag.'>'.$after;
    }

    /**
     * indent tags for readability if HTML source
     *
     * @param string $tag tag name
     * @return array
     */
    private function _tag_indent($tag)
    {
        // prefix and surffix of html tags
        $indent = array(
            'ol' => array("\n","\n"),  '/ol' => array("","\n"),
            'ul' => array("\n","\n"),  '/ul' => array("","\n"),
            'dl' => array("\n","\n"),  '/dl' => array("","\n"),
            'li' => array("  ",""),    '/li' => array("","\n"),
            'dt' => array("  ",""),    '/dt' => array("","\n"),
            'dd' => array("  ","\n"),  '/dd' => array("","\n"),
            'p'  => array("\n",""),    '/p'  => array("","\n"),
        );
        return $indent[$tag];
    }

}
