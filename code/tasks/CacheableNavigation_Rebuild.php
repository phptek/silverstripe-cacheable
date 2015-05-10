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
        $startTime = time();
        
        ini_set('memory_limit', -1);
        if((int)$maxTime = $request->getVar('MaxTime')) {
            ini_set('max_execution_time', $maxTime);
        }
        
        $currentStage = Versioned::current_stage();
        
        echo 'Current Cachestore: ' . CacheableConfig::current_cache_mode() . $this->lineBreak(2);
        
        // Restrict cache rebuild to the given mode
        if($mode = $request->getVar('Mode')) {
            $stage_mode_mapping = array(
                ucfirst($mode) => strtolower($mode)
            );
        // All modes
        } else {
            $stage_mode_mapping = array(
                "Stage" => "stage",
                "Live"  => "live",
            );
        }

        $siteConfigs = DataObject::get('SiteConfig');
        foreach($stage_mode_mapping as $stage => $mode) {
            Versioned::set_reading_mode('Stage.' . $stage);
            if(class_exists('Subsite')) {
                Subsite::disable_subsite_filter(true);
                Config::inst()->update("CacheableSiteConfig", 'cacheable_fields', array('SubsiteID'));
                Config::inst()->update("CacheableSiteTree", 'cacheable_fields', array('SubsiteID'));
            }
            
            foreach($siteConfigs as $config) {                
                $service = new CacheableNavigationService($mode, $config);
                $service->refreshCachedConfig();
                $table = '';
                if($stage === 'Live') {
                    $table = '_' . $stage;
                }
                
                if(class_exists('Subsite')) {
                    $pages = $this->getPages($table, "SubsiteID = '" . $config->SubsiteID . "'");
                } else {
                    $pages = $this->getPages($table);
                }
                
                if($pages->count()) {
                    $count = 0;
                    foreach($pages as $page) {
                        $service->set_model($page);
                        $service->refreshCachedPage();
                        
                        $count++;
                        $percent = $this->percentageComplete($count, $pages->count());
                        
                        if($request->getVar('Debug')) {
                            echo 'Memory Now: ' . memory_get_usage(true) / 1024 / 1024 . 'Mb' . $this->lineBreak();
                            echo 'Memory Peak: ' . memory_get_peak_usage(true) / 1024 / 1024 . 'Mb' . $this->lineBreak();
                        }
                        echo 'Cached: ' . $page->Title . ' (' . $percent . ')' . $this->lineBreak();
                        unset($page);
                    }
                }
                
                $service->completeBuild();
                echo $pages->count()." pages cached in $stage mode for subsite " . $config->ID . $this->lineBreak();
                
                unset($service);
            }
            
            if(class_exists('Subsite')){
                Subsite::disable_subsite_filter(false);
            }
        }

        Versioned::set_reading_mode($currentStage);
        
        $endTime = time();
        $totalTime = ($endTime - $startTime);
        
        echo 'Time to run: ' . $totalTime . 's' . $this->lineBreak();
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
    
    /**
     * 
     * Generate an O/S independent line-break, for as many times as required.
     * 
     * @param number $mul
     * @return string
     */
    public function lineBreak($mul = 1) {
        $line_break = Director::is_cli() ? PHP_EOL : "<br />";
        return str_repeat($line_break, $mul);
    }
    
    /**
     * 
     * ~30Mb less peak_memory_usage with SqlQuery vs DataObject::get()
     * 
     * @param string $table
     * @param string $where
     * @return SS_List
     */
    public function getPages($table, $where) {
        // Method 1).
//        $pages = DataObject::get("SiteTree$table", $where);
//        return $pages;
        //$pages = DataObject::get("Page");
        
        // Method 2).
        
        $query = new SQLQuery();
        $query->setFrom("SiteTree$table");
        $query->selectField('*');
        $query->setWhere($where);
        $records = $query->execute();
        
        unset($query);
        
        $pages = new ArrayList();
        foreach($records as $record) {
            $page = new Page($record); // Just a DataObject really
            $pages->push($page);
            unset($page);
        }  
        
        return $pages;
        
        // Method 3).
        
//        $sql = "SELECT * FROM SiteTree$table";
//        if($where) {
//            $sql .= ' WHERE ' . $where;
//        }
//        $query = DB::query($sql);
//        $pages = new ArrayList();
//        while($record = $query->record()) {;
//            $page = new Page($record); // Just a DataObject really
//            $pages->push($page);
//            unset($page);
//        }
//        
//        return $pages;
    }
}
