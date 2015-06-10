<?php
/**
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 */
abstract class CacheableData extends ViewableData {
    private static $cacheable_fields = array(
        "ID",
        "Title"
    );

    /**
     *
     * @var array
     */
    private static $cacheable_functions = array();

    public function get_cacheable_fields() {
        return $this->config()->cacheable_fields;
    }

    public function get_cacheable_functions() {
        return $this->config()->cacheable_functions;
    }

    /**
     * 
     * @return array
     */
    public function CachedNavigation() {
        return Config::inst()->get('Cacheable', '_cached_navigation');
    }

    abstract public function canView($member = null);

    /**
     * Returns true if this object "exists", i.e., has a sensible value.
     * The default behaviour for a DataObject is to return true if
     * the object exists in the database, you can override this in subclasses.
     *
     * @return boolean true if this object exists
     */
    public function exists() {
        return (isset($this->ID) && $this->ID > 0);
    }

    public function extendedCan($methodName, $member) {
        $results = $this->extend($methodName, $member);
        if($results && is_array($results)) {
            // Remove NULLs
            $results = array_filter($results, function($v) {return !is_null($v);});
            // If there are any non-NULL responses, then return the lowest one of them.
            // If any explicitly deny the permission, then we don't get access
            if($results) return min($results);
        }
        return null;
    }

    public function NonCachedData() {
        return DataObject::get_by_id($this->ClassName, $this->ID);
    }

    /**
     * 
     * Template function for debugging. Allows you to see at-a-glance, 
     * the fields, functions and child nodes held in the Object-Cache about 
     * the current object.
     * 
     * Usage:
     * 
     * <code>
     *  <% with $CachedData %>
     *  $Debug(98)
     *  <% end_with %>
     * <code>
     * 
     * @return string
     */
    public function Debug($id = null) {
        if(!Director::isDev() || !isset($_REQUEST['showcache'])) {
            return;
        }
        
        if($id) {
            $mode = strtolower($_REQUEST['showcache']);
            $conf = SiteConfig::current_site_config();
            $cacheService = new CacheableNavigationService($mode, $conf);
            $objectCache = $cacheService->getObjectCache();
            $cachedSiteTree = $objectCache->get_site_map();
            if(isset($cachedSiteTree[$id])) {
                $object = $cachedSiteTree[$id];
            } else {
                return false;
            }
        } else {
            $object = $this;
        }
        
        $message = "<h2>Object-Cache fields & functions for: " . get_class($object) . "</h2>";
        
        $message .= "<ul>";
            $message .= "\t<li><strong>Cached specifics:</strong>";
                $message .= "\t\t<ul>";
                    $message .= "\t\t\t<li>ID: " . $object->ID . "</li>";
                    $message .= "\t\t\t<li>Title: " . $object->Title . "</li>";
                    $message .= "\t\t\t<li>ClassName: " . $object->ClassName . "</li>";
                    $message .= "\t\t\t<li>Child count: " . $object->getChildren()->count() . "</li>";
                $message .= "\t\t</ul>";
            $message .= "\t</li>";
        $message .= "</ul>";
                    
        $message .= "<ul>";
            $message .= "\t<li><strong>Cached Fields:</strong>";
                $message .= "\t\t<ul>";

                foreach($object->get_cacheable_fields() as $field) {
                    $message .= "\t\t\t<li>" . $field . ': ' . $object->$field . "</li>";
                }

                $message .= "\t\t</ul>";
            $message .= "\t</li>";
            $message .= "\t<li><strong>Cached Functions:</strong>";
                $message .= "\t\t<ul>";

                foreach($object->get_cacheable_functions() as $function) {
                    $message .= "\t\t\t<li>" . $function . '</li>';
                }

                $message .= "\t\t</ul>";
            $message .= "\t</li>";
        $message .= "</ul>";
        
        $message .= "<h2>Child nodes of this object:</h2>";
        
        $message .= '<ol>';
        foreach($object->getChildren() as $child) {
            $message .= "\t<li>" . $child->Title . ' (#' . $child->ID . ')</li>';
        }
        $message .= '</ol>';

        return $message;
    }
    
    public function debug_simple() {
        $message = "<h5>cacheable data: ".get_class($this)."</h5><ul>";
        $message .= "<il>ID: ".$this->ID.". Title: ".$this->Title.". ClassName".$this->ClassName."</il>";
        $message .= "</ul>";
        return $message;
    }
}
