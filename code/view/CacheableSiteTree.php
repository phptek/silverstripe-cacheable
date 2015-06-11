<?php
/**
 * 
 * This class can be thought of as the module's equivalent of {@link SiteTree}.
 * 
 * @author Deviate Ltd 2014-2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link CacheableData}
 * @todo How do these CacheableData subclasses confer userland canXX() abilities
 * to cached objects?
 */
class CacheableSiteTree extends CacheableData {

    /**
     *
     * An array of fields that will be cached. These can be extended in userland YML
     * config.
     * 
     * @var array
     */
    private static $cacheable_fields = array(
        "MenuTitle",
        "URLSegment",
        "ParentID",
        "ClassName",
        "Sort",
        "ShowInMenus",
        "CanViewType",
    );

    /**
     *
     * An array of functions that will be cached. These can be extended in userland YML
     * config.
     * 
     * @var array
     */
    private static $cacheable_functions = array(
        "Link",
        "ViewerGroups",
        "getSourceQueryParams",
    );

    /**
     *
     * The child-objects of this object, if this is the parent.
     * 
     * @var array
     */
    private $Children = array();

    /**
     *
     * @var CacheableSiteTree
     */
    private $Parent;
    
    /**
     *
     * @var null|boolean
     */
    private $_isSection = null;


    /**
     * 
     * Return the CacheableSiteConfig in operation in the current Oject cache.
     * 
     * @return SiteConfig|CacheableSiteConfig
     */
    public function getSiteConfig() {
        if($this->hasMethod('alternateSiteConfig')) {
            $altConfig = $this->alternateSiteConfig();
            if($altConfig) {
                return $altConfig;
            }
        }

        $nav = Config::inst()->get('Cacheable', '_cached_navigation');
        if($nav) {
            $site_map = $nav->get_site_map();
            $site_config = $nav->get_site_config();
            if(isset($site_map[$this->ID]) && $site_config->exists()) {
                return $site_config;
            }
        }

        return SiteConfig::current_site_config();
    }

    /**
     * 
     * @param string|int $key
     * @return type
     */
    public function getCachedSourceQueryParam($key) {
        if(isset($this->getSourceQueryParams[$key])) {
            return $this->getSourceQueryParams[$key];
        }

        return null;
    }

    /**
     * 
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null) {
        if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
            $member = Member::currentUserID();
        }

        // admin override
        $adminCheck = array("ADMIN", "SITETREE_VIEW_ALL");
        if($member && Permission::checkMember($member, $adminCheck)) {
            return true;
        }

        /*
         * Make sure we were loaded off an allowed stage
         * Were we definitely loaded directly off Live during our query?
         */
        $fromLive = true;
        $stages = array(
            'mode' => 'stage', 
            'stage' => 'live'
        );
        foreach($stages as $param => $match) {
            $fromLive = $fromLive && strtolower((string) $this->getCachedSourceQueryParam("Versioned.$param")) == $match;
        }
        
        if(!$fromLive && !Session::get('unsecuredDraftSite') && !Permission::checkMember($member, array('CMS_ACCESS_LeftAndMain', 'CMS_ACCESS_CMSMain', 'VIEW_DRAFT_CONTENT'))) {
            // If we weren't definitely loaded from live, and we can't view non-live content, we need to
            // check to make sure this version is the live version and so can be viewed
            if(Versioned::get_versionnumber_by_stage($this->ClassName, 'Live', $this->ID) != $this->Version) {
                return false;
            }
        }

        // Orphaned pages (in the current stage) are unavailable, except for admins via the CMS
        if($this->isOrphaned()) {
            return false;
        }

        // Standard mechanism for accepting permission changes from extensions
        $extended = $this->extendedCan('canView', $member);
        if($extended !== null) {
            return $extended;
        }
        
        // check for empty spec
        if(!$this->CanViewType || $this->CanViewType == 'Anyone') {
            return true;
        }

        // check for inherit
        if($this->CanViewType == 'Inherit') {
            if($parent = $this->getParent()) {
                return $parent->canView($member);
            }
            
            return $this->getSiteConfig()->canView($member);
        }

        // check for any logged-in users
        if($this->CanViewType == 'LoggedInUsers' && $member) {
            return true;
        }

        // check for specific groups
        if($member && is_numeric($member)) {
            $member = DataObject::get_by_id('Member', $member);
        }
        
        if($this->CanViewType == 'OnlyTheseUsers' && ($member && $member->inGroups($this->ViewerGroups))) {
            return true;
        }

        return false;
    }

    /**
     * 
     * @param CacheableData $data
     * @return void
     */
    public function addChild(CacheableData $data) {
        $this->Children[$data->ID] = $data;
    }

    /**
     * 
     * "Removes" an item from the Children array, but by default not using PHP's 
     * unset() function becuase this will cause PHP to reset its internal array 
     * pointer and we need to maintain array state. Use the $force param to unset.
     * 
     * @param int $childID
     * @param boolean $force    Invoke PHP's unset() function on the selected item.
     * @return void
     */
    public function removeChild($childID, $force = true) {
        if(isset($this->Children[$childID])) {
            if($force === true) {
                unset($this->Children[$childID]);
            } else {
                $this->Children[$childID] = CacheableSiteTree::create(); // dummy
            }
        }
    }

    /**
     * 
     * @param CacheableData $data
     */
    public function setParent(CacheableData $data) {
        $this->Parent = $data;
    }

    /**
     * 
     * @return CacheableSiteTree
     */
    public function getParent() {
        return $this->Parent;
    }

    /**
     * 
     * @return array
     */
    public function getAllChildren() {
        return $this->Children;
    }

    /**
     * 
     * $this->Children is an array, so return the same list, filtered for ShowInMenus
     * as an ArrayList.
     * 
     * @return ArrayList
     */
    public function getChildren() {
        $children = new ArrayList($this->Children);
        $children = $children->filter(array("ShowInMenus" => 1));
        $visible = array();
        foreach($children as $child) {
            if($child->canView()) {
                $visible[] = $child;
            }
        }
        
        return new ArrayList($visible);
    }

    /**
     * 
     * Return "link" or "current" depending on if this is the {@link SiteTree::isCurrent()} current page.
     *
     * @return string
     */
    public function LinkOrCurrent() {
        return $this->isCurrent() ? 'current' : 'link';
    }

    /**
     * 
     * Return "link" or "section" depending on if this is the {@link SiteTree::isSeciton()} current section.
     *
     * @return string
     */
    public function LinkOrSection() {
        return $this->isSection() ? 'section' : 'link';
    }

    /**
     * 
     * @return string
     */
    public function LinkingMode() {
        if($this->isCurrent()) {
            return 'current';
        } elseif($this->isSection()) {
            return 'section';
        } else {
            return 'link';
        }
    }
    
    /**
     * 
     * @return boolean
     */
    public function isSection() {
        if($this->_isSection === null) {
            if($this->isCurrent()) {
                $this->_isSection = true;
            } else {
                $currentPage = Director::get_current_page();
                $navigation = $this->CachedNavigation();
                $ancestors = $navigation->getAncestores($currentPage->ID);
                $this->_isSection = $currentPage instanceof SiteTree && in_array($this->ID, $ancestors->column());
            }
        }
        return $this->_isSection;
    }

    /**
     * 
     * @return boolean
     */
    public function isCurrent() {
        return $this->ID ? $this->ID == Director::get_current_page()->ID : $this === Director::get_current_page();
    }

    /**
     * 
     * @return boolean
     */
    public function isOrphaned() {
        // Always false for root pages
        if(empty($this->ParentID)) {
            return false;
        }
        
        $parent = $this->getParent();
        return !$parent || !$parent->exists() || $parent->isOrphaned();
    }

    /**
     * 
     * @return string
     */
    public function debug_simple() {
        $message = "<h5>cacheable data: " . get_class($this) . "</h5><ul>";
        $message .= "<il>ID: " . $this->ID . ". Title: " . $this->Title . ". ClassName" . $this->ClassName . "</il>";
        $parent = $this->getParent();
        if($parent && $parent->exists()) {
            $message .= "<il>Parent ID: " . $parent->ID . ". Title: " . $parent->Title . ". ClassName" . $parent->ClassName . "</il>";
        }
        $message .= "</ul>";
        return $message;
    }

    /**
     *
     * Built-in module-specific version of {@link ContentController::Menu()} function.
     * Takes its data not from the ORM but the Object Cache.
     * 
     * @param type $level
     * @return ArrayList
     */
    public function Menu($level = 1) {
        if($nav = $this->CachedNavigation()) {
            if($level == 1) {
                $root_elements = new ArrayList($nav->get_root_elements());
                $result = $root_elements->filter(array(
                    "ShowInMenus" => 1
                ));
            } else {
                $dataID = Director::get_current_page()->ID;
                $site_map = $nav->get_site_map();
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
                        $result = $elements->filter(array("ShowInMenus" => 1));
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
    }

    /**
     * 
     * Returns an instance of {@link CachedNavigation} the object list-container for
     * all Object Cache contents.
     * 
     * @return CachedNavigation
     */
    public function CachedNavigation() {
        if($cachedNavigiation = Config::inst()->get('Cacheable', '_cached_navigation')) {
            if($cachedNavigiation->isUnlocked() && $cachedNavigiation->get_completed()) {
                return $cachedNavigiation;
            }
        }
    }

}
