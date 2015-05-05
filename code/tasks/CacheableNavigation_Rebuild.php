<?php
/**
 * 
 * This BuildTask pre-primes the f/s or in-memory cache for {@link SiteTree} and 
 * {@link SiteConfig} native SilverStripe objects.
 * 
 * The BuildTsask should be run from the command-line as the webserver user 
 * e.g. www-data otherwise while attempting to access the site from a browser, the 
 * webserver won't have permission to access the cache. E.g:
 * 
 * <code>
 *  #> sudo -u www-data ./framework/sake dev/tasks/CacheableNavigation_Rebuild
 * <code>
 * 
 * You may also pass-in an optional "Mode" parameter, one of "Live" or "Stage"
 * which helps when debugging. It will restrict the cache-rebuild to objects in 
 * the given {@Link Versioned} mode. The default is to cache objects in both 
 * "Stage" and "Live" modes.
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link CacheableNavigation_Clean}.
 * @todo Rename task to better suit the module's new name
 */
class CacheableNavigation_Rebuild extends BuildTask {
    
    /**
     *
     * @var string
     */
    protected $description = 'Rebuilds silverstripe-cacheable object cache.';

    /**
     * 
     * @param SS_HTTPRequest $request
     * @return void
     */
    public function run($request) {
        $currentStage = Versioned::current_stage();
        
        // Restrict cache rebuild to the given mode
        if($mode = $request->getVar('Mode')) {
            $stage_mode_mapping = array(
                ucfirst($mode) => strtolower($mode)
            );
        } else {
            $stage_mode_mapping = array(
                "Stage" => "stage",
                "Live"  => "live",
            );
        }
        
        $filter = array();
        /*
         * Just build enough to get the main nav built which is 10s of SiteTree objects 
         * vs potentialaly 1000s.
         */
        if($init = $request->getVar('Init')) {
            $filter = array('ParentID' => 0);
        }
        
        $this->doRebuild($stage_mode_mapping, $filter);

        Versioned::set_reading_mode($currentStage);
    }
    
    /**
     * 
     * Physically perform the rebuild
     * 
     * @param array $stages
     * @param array $filter An array of fields with which to filter the pages used to 
     * build the cachestore.
     */
    private function doRebuild($stages, $filter = array()) {
        $line_break = Director::is_cli() ? PHP_EOL : "<br />";
        $siteConfigs = DataObject::get('SiteConfig');
        foreach($stages as $stage => $mode){
            Versioned::set_reading_mode('Stage.'.$stage);
            if(class_exists('Subsite')){
                Subsite::disable_subsite_filter(true);
                Config::inst()->update("CacheableSiteConfig", 'cacheable_fields', array('SubsiteID'));
                Config::inst()->update("CacheableSiteTree", 'cacheable_fields', array('SubsiteID'));
            }
            
            foreach($siteConfigs as $config) {                
                $service = new CacheableNavigationService($mode, $config);
                $service->refreshCachedConfig();
                
                if(class_exists('Subsite')) {
                    $pages = DataObject::get("Page", "\"SubsiteID\" = '".$config->SubsiteID."'");
                } else {
                    $pages = DataObject::get("Page");
                }
                
                if($filter) {
                    $pages = $pages->filter($filter);
                }
                
                if($pages->exists()) {
                    $count = 0;
                    foreach($pages as $page){
                        $count++;
                        $service->set_model($page);
                        $percent = $this->percentageComplete($count, $pages->count());
                        echo 'Caching: ' . $page->Title . ' (' . $percent . ')' . $line_break;
                        $service->refreshCachedPage();
                    }
                }
                
                $service->completeBuild();
                echo $pages->count()." pages cached in $stage mode for subsite " . $config->ID . $line_break;
            }
            
            if(class_exists('Subsite')){
                Subsite::disable_subsite_filter(false);
            }
        }
    }


    /**
     * 
     * Generate a percentage of how complete the cache rebuild is.
     * 
     * @param number $count
     * @param number $total
     * @return string
     */
    public function percentageComplete($count, $total) {
        $calc = (((int)$count / (int)$total) * 100);
        return round($calc, 1) . '%';
    }
}
