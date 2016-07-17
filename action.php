<?php
/**
 * DokuWiki Plugin ExtList (Action component)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 *
 */
if (!defined('DOKU_INC')) die();

class action_plugin_extlist extends DokuWiki_Action_Plugin {

    /**
     * register the event handlers
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, '_prepareCssFile');
    }

    /**
     * Prepare plugin stylesheet file
     */
    public function _prepareCssFile(Doku_Event $event) {

        if ($this->getConf('use_plugin_css')) {
            $f0 = dirname(__FILE__).'/sample.less';
            $f1 = dirname(__FILE__).'/all.less';
            if (!@file_exists($f1) && @file_exists($f0)) {
                @copy( $f0, $f1 );
            }
        } else {
            $f = dirname(__FILE__).'/all.less';
            if (@file_exists($f)) { @unlink($f); }
        }
    }



}
