<?php
/**
 * 
 * This BuildTask pre-primes the f/s or in-memory cache for {@link SiteTree} and 
 * {@link SiteConfig} native SilverStripe objects.
 * 
 * The BuildTask should be run from the command-line as the webserver user 
 * e.g. www-data otherwise while attempting to access the site from a browser, the 
 * webserver won't have permission to access the cache. E.g:
 * 
 * <code>
 *  #> sudo -u www-data ./framework/sake dev/tasks/CacheableNavigation_Rebuild
 * <code>
 * 
 * You may also pass-in an optional "Stage" parameter, one of "Live" or "Stage"
 * which helps when debugging. It will restrict the cache-rebuild to objects in 
 * the given {@Link Versioned} stage. The default is to cache objects in both 
 * "Stage" and "Live" modes which takes longer to run and uses more memory.
 * 
 * There is also an optional "Debug" flag which will print out memory usage stats.
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link CacheableNavigation_Clean}.
 * @todo Rename task to better suit the module's new name
 * @todo Refactor debug feature to show:
 * - Percentage complete
 * - peak_memory_usage
 * - Selected Cachestore
 * - Stare time, end time & total execution time
 * - % Cache filled using {@link Zend_Cache_Core::getFillingPercentage()}.
 * @todo What's the difference between Zend_Cache_Backend_Libmemcached and Zend_Cache_Backend_Memcached?
 * @todo What else can we get out of Memcached::getextendedstats()? See: http://php.net/manual/en/memcache.getextendedstats.php
 * @todo Investigate memcached compression
 */
class CacheableNavigation_Rebuild extends BuildTask {
    
    /**
     * 
     * A reasonable amount of memory in bytes, at which to start the next chunk.
     * The idea is to keep this relatively low, to ensure each chunk as a QueuedJob
     * is easily managed by PHP's CLI SAPI, especially as there may be 10s of these
     * jobs to be queued.
     * 
     * @var number
     */
    private static $chunk_at = 52428800; // 50Mb
    
    /**
     *
     * @var string
     */
    protected $description = 'Rebuilds silverstripe-cacheable object cache.';

    /**
     * 
     * If an error is reported during the chunking process (job creation), its 
     * output is propogated here so we can display a warning to the user when 
     * this BuildTask completes.
     * 
     * @var boolean
     */
    protected $chunkingErrorMsg = '';
    
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
        
        // Skip te ORM and use Raw {@link SQLQuery} for performance?
        $useORM = $request->getVar('ORM');
        $currentStage = Versioned::current_stage();
        
        echo 'Current Cachestore: ' . CacheableConfig::current_cache_mode() . $this->lineBreak(2);
        
        // Restrict cache rebuild to the given stage
        if($paramStage = $request->getVar('Stage')) {
            $stage_mode_mapping = array(
                ucfirst($paramStage) => strtolower($paramStage)
            );
        // All stages
        } else {
            $stage_mode_mapping = array(
                "Stage" => "stage",
                "Live"  => "live",
            );
        }

        $canQueue = interface_exists('QueuedJob');
        $siteConfigs = DataObject::get('SiteConfig');
        $msg = '';
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
                
                // Which SiteTree table variant to SELECT from
                $table = '';
                if($stage === 'Live') {
                    $table = '_' . $stage;
                }
                
                if(class_exists('Subsite')) {
                    $pages = $this->getPages($table, "SubsiteID = '" . $config->SubsiteID . "'", $useORM);
                } else {
                    $pages = $this->getPages($table, null, $useORM);
                }
                
                if($pages->count()) {
                    $count = 0;
                    $chunkCount = 0;
                    foreach($pages as $page) {
                        // Start the chunk of pages to be refreshed
                        $chunk[] = $page;
                        
                        // If QueuedJobs exists and memory-use is high: Chunk
                        $memPeak = memory_get_peak_usage(true);
                        $startChunk = ($memPeak >= self::$chunk_at);
                        if($canQueue) {
                            if($startChunk) {
                                $chunkCount++;
                                echo '\tChunking at ' . $memPeak . 'bytes: Processing chunk #' . $chunkCount;
                                $this->queue($service, $chunk); // $chunk passed by ref
                            }
                        // Default to non-chunking mode and refresh entire page cache
                        } else {
                            $service->set_model($page);
                            $service->refreshCachedPage();
                        }
                        
                        // Show how we're going
                        $count++;
                        $percent = $this->percentageComplete($count, $pages->count());
                        
                        // Debug info, if requested
                        if($request->getVar('Debug')) {
                            echo 'Memory Now: ' . memory_get_usage(true) / 1024 / 1024 . 'Mb' . $this->lineBreak();
                            echo 'Memory Peak: ' . memory_get_peak_usage(true) / 1024 / 1024 . 'Mb' . $this->lineBreak();
                        }
                        
                        echo 'Cached: ' . $page->Title . ' (' . $percent . ')' . $this->lineBreak();
                        
                        // Free up some memory
                        unset($page);
                    }
                }
                
                $service->completeBuild();
                
                // Completion message
                $msg .= $pages->count() . " {$stage} pages in subsite " . $config->ID;
                $msg .= ' cached in ' . $chunkCount . ' chunks.';
                echo $msg . $this->lineBreak();
                if($this->isError) {
                    echo 'WARNING: error(s) occurred during chunking.' . $this->lineBreak();
                }
                
                // Free up some memory
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
     * @param string $table
     * @param string $where
     * @param boolean $useORM ~30Mb less peak_memory_usage with SqlQuery vs DataObject::get()
     * @return SS_List
     */
    public function getPages($table, $where = '', $useORM = true) {
        if($useORM) {
            if($where) {
                $pages = DataObject::get("SiteTree$table", $where);
            } else {
                $pages = DataObject::get("SiteTree$table");
            }
        } else {        
            $query = new SQLQuery();
            $query->setFrom("SiteTree$table");
            $query->selectField('*');
            if($where) {
                $query->setWhere($where);
            }
            $records = $query->execute();

            unset($query);

            $pages = new ArrayList();
            foreach($records as $record) {
                $page = new Page($record); // Just a DataObject really
                $pages->push($page);
                unset($page);
            }
        }
        
        return $pages;
    }
    
    /**
     * 
     * Create a {@link ChunkedCachableRefreshJob} for each "chunk" of N pages
     * to refresh the caches of. Once run, $chunk is truncated and passed back its
     * original reference.
     * 
     * @param CachableNavigationService $service
     * @param array $chunk (Pass by reference)
     * @todo Instead of arbitrarily selecting the chunk size, be more intelligent and do
     * it according to peak_memory_usage e.g. 50Mb (and place that in a static property)
     */
    public function queue($service, &$chunk) {
        $job = CachableChunkedRefreshJob::create($service, $chunk);
        $job->process();
        if($job->chunkingErrorMsg) {
            $this->chunkingErrorMsg = $job->chunkingErrorMsg;
        }

        // Reset the chunk-by-ref to deal with the next chunk
        $chunk = array();
    }
}
