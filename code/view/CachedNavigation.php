<?php
/**
 * 
 * CachedNavigation is the "wrapper" list object which is physically cached, and 
 * who's children comprise {@link CacheableSiteTree} and {@link CacheableSiteConfig}
 * objects.
 * 
 * @author Deviate Ltd 2014-2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @todo Rename the class to "CachedObjectList" or similar.
 */
class CachedNavigation extends ArrayList {

    /**
     *
     * @var CacheableSiteConfig
     */
    private $cached_site_config;

    /**
     *
     * @var array
     */
    private $site_map = array();

    /**
     *
     * @var array
     */
    private $root_elements = array();

    /**
     *
     * Signals that the cache is still being built.
     * 
     * @var boolean
     */
    private $locked = false;

    /**
     *
     * Signals that the cache has completed being built.
     * 
     * @var boolean
     */
    private $completed = false;

    /**
     * 
     * @param type CacheableSiteConfig
     * @return void
     * @todo Convert to camelCase in method name
     */
    public function set_site_config($cached_site_config) {
        $this->cached_site_config = $cached_site_config;
    }

    /**
     * 
     * Extract and return an instance of CacheableSiteConfig from the cached, CachedNavigation.
     * 
     * @return CacheableSiteConfig
     * @todo Convert to camelCase in method name
     */
    public function get_site_config() {
        return $this->cached_site_config;
    }

    /**
     * 
     * @param array $site_map
     * @return void
     * @todo Convert to camelCase in method name
     */
    public function set_site_map($site_map) {
        $this->site_map = $site_map;
    }

    /**
     * 
     * @return array
     * @todo Convert to camelCase in method name
     */
    public function get_site_map() {
        return $this->site_map;
    }

    /**
     * 
     * @param array $root_elements
     * @todo Convert to camelCase in method name
     */
    public function set_root_elements($root_elements) {
        $this->root_elements = $root_elements;
    }

    /**
     * 
     * @return array
     */
    public function get_root_elements() {
        return $this->root_elements;
    }

    /**
     *
     * @return void
     * @todo Unused.
     */
    public function lock() {
        $this->locked = true;
    }

    /**
     *
     * @return void
     * @todo Unused.
     */
    public function unlock() {
        $this->locked = false;
    }

    /**
     *
     * @return boolean
     * @todo Unused. (When debug() is also removed)
     */
    public function isLocked() {
        return $this->locked === true;
    }

    /**
     *
     * @return void
     */
    public function isUnlocked() {
        return $this->locked === false;
    }

    /**
     *
     * @param boolean $completed
     * @return void
     */
    public function set_completed($completed) {
        $this->completed = $completed;
    }

    /**
     * 
     * @return boolean
     */
    public function get_completed() {
        return $this->completed;
    }

    /**
     * 
     * @param int $level
     * @return ArrayList
     * @todo There is an almost identical version of this method on {@link CacheableSiteTree}
     * but are both required?
     */
    public function Menu($level = 1) {
        if($level == 1) {
            $root_elements = new ArrayList($this->get_root_elements());
            $result = $root_elements->filter(array("ShowInMenus" => 1));
        } else {
            $dataID = Director::get_current_page()->ID;
            $site_map = $this->get_site_map();
            if(isset($site_map[$dataID])) {
                $parent = $site_map[$dataID];

                $stack = array($parent);
                if($parent) {
                    while($parent = $parent->getParent()) {
                        array_unshift($stack, $parent);
                    }
                }

                if(isset($stack[$level - 2])) {
                    $elements = new ArrayList($stack[$level - 2]->getAllChildren());
                    $result = $elements->filter(
                            array(
                                "ShowInMenus" => 1,
                            )
                    );
                }
            }
        }

        $visible = array();

        // Remove all entries the can not be viewed by the current user
        // We might need to create a show in menu permission
        if(isset($result)) {
            foreach($result as $page) {
                if($page->canView()) {
                    $visible[] = $page;
                }
            }
        }
        return new ArrayList($visible);
    }

    /**
     * 
     * @param int $cachedID
     * @return ArrayList
     * @todo spelig
     */
    public function getAncestores($cachedID) {
        $site_map = $this->get_site_map();
        $ancestors = new ArrayList();
        if(isset($site_map[$cachedID])) {
            $parent = $site_map[$cachedID];
            while($parent = $parent->getParent()) {
                $ancestors->push($parent);
            }
        }

        return $ancestors;
    }

    /**
     * 
     * @return string
     * @todo Remove.
     */
    public function debug() {
        $message = "<h3>cacheable navigation object: " . get_class($this) . "</h3>\n<ul>\n";
        if($this->isLocked())
            $message .= "<h4>The navigation object is locked.</h4>";
        else
            $message .= "<h4>The navigation object is unlocked.</h4>";

        if($this->get_completed())
            $message .= "<h4>The navigation object is completed.</h4>";
        else
            $message .= "<h4>The navigation object is incompleted.</h4>";

        if($site_config = $this->get_site_config()) {
            $message .= "<h4>The cached site config ID: " . $site_config->ID . "</h4>";
        }

        $message .= "<h4>The root elements:</h4>";
        foreach($this->get_root_elements() as $element) {
            $message .= $element->debug_simple();
        }

        $message .= "<h4>The site map elements:</h4>";
        foreach($this->get_site_map() as $element) {
            $message .= $element->debug_simple();
        }

        return $message;
    }

}
