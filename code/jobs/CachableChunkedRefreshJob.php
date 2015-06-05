<?php
/**
 * 
 * Looking at the refresh BuildTask: We break the entire iteration over the $pages 
 * result-set into chunks depending on peak memory usage which is checked at each
 * iteration. If memory exceeds a preset limit, we pass processing of the cache-refresh
 * onto a job queue. 
 * 
 * The idea is that each chunk should be managable enough in terms of memory and 
 * execution time to run even on the smallest of host setups, rather than iteratively 
 * refreshing all objects in the same chunk of N 100s of pages, and having PHP 
 * consume a ton of system resources.
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link CacheableNavigation_Rebuild}.
 * @todo The "Clean" ob may be clearing things it shouldn't be
 * @todo Modify peak memory calculation for throwing exception if php.ini accepts anything
 * other than 'M' in its memory_limit setting,
 */
class CachableChunkedRefreshJob extends AbstractQueuedJob implements QueuedJob {

    /**
     * 
     * @var CacheableNavigationService
     */
    protected $service;

    /**
     * 
     * @var array
     */
    protected $chunk = array();

    /**
     * 
     * @var string
     */
    protected $stage = '';

    /**
     * 
     * @var number
     */
    protected $subsiteID = 0;

    /**
     * 
     * On each N iterations of the for-loop in $this->process(), we check to see if
     * current peak memory exceeds buffer subtracted from memory_limit. If it does, 
     * we throw an exception which is caught by {@link QueuedJobService} to mark the job 
     * as broken.
     * 
     * @var number
     */
    public static $critical_memory_buffer = 2097152; // 2Mb

    /**
     * 
     * Sets internal variables and persistent data for when job is created without
     * constructor params, and process() is called in {@link QueuedJobService}.
     * 
     * @param CacheableNavigationService $service
     * @param array $chunk                          An array of objects to cache
     * @param string $stage                         "Live" or "Stage"
     * @param number $subsiteID
     * @return void
     * @todo In the spirit of S.O.L.I.D the job service (CachableChunkedRefreshJobStorageService) should
     * be passed as a constructor arg, not baked-in.
     */

    public function __construct(CacheableNavigationService $service, $chunk, $stage, $subsiteID) {
        // Setters required for internal methods except $this->process()
        $this->setService($service);
        $this->setChunk($chunk);
        $this->setStage($stage);
        $this->setSubsiteID($subsiteID);

        // Persist structured "metadata" about the job using {@link CachableChunkedRefreshJobStorageService}.
        $jobConfig = array(
            'CachableChunkedRefreshJobStorageService' => array(
                'service' => $this->getService(),
                'chunk' => $this->getChunk()
        ));
        
        $this->setCustomConfig($jobConfig);
        $this->prepareForRestart();
        $this->totalSteps = $this->chunkSize();
    }

    /**
     * 
     * @param CacheableNavigationService $service
     */
    public function setService(CacheableNavigationService $service) {
        $this->service = $service;
    }

    /**
     * 
     * @param array $chunk
     */
    public function setChunk($chunk) {
        $this->chunk = $chunk;
    }

    /**
     * 
     * @param string $stage
     */
    public function setStage($stage) {
        $this->stage = $stage;
    }

    /**
     * 
     * @param number $subsiteID
     */
    public function setSubsiteID($subsiteID) {
        $this->subsiteID = $subsiteID;
    }

    /**
     * 
     * @return CacheableNavigationService
     */
    public function getService() {
        return $this->service;
    }

    /**
     * 
     * @return array
     */
    public function getChunk() {
        return $this->chunk;
    }

    /**
     * 
     * @return string
     */
    public function getStage() {
        return $this->stage;
    }

    /**
     * 
     * @return number
     */
    public function getSubsiteID() {
        return $this->subsiteID;
    }

    /**
     * 
     * Pack all relevant info into the job's so that it's viewable in the
     *  "queuedjobs" CMS section. The title data in the title will appear 
     * inaccuarate when run via the main ProcessJobQueueTask.
     * 
     * @return string
     */
    public function getTitle() {
        $title = 'Cacheable refresh'
            . ' ' . $this->chunkSize() . ' objects.'
            . ($this->getSubsiteID() ? ' (SubsiteID ' . $this->getSubsiteID() . ')' : '')
            . ' ' . $this->getStage();

        return $title;
    }

    /**
     * 
     * @return boolean
     */
    public function jobFinished() {
        parent::jobFinished();

        return $this->isComplete === true;
    }

    /**
     * 
     * @return number
     */
    public function chunkSize() {
        return count($this->getChunk());
    }

    /**
     * 
     * The body of the job: Runs the memory-intensive refreshXX() method on each page
     * of the passed $chunk, using the passed $service.
     * 
     * Sets an error message viewable in the CMS' "Jobs" section, if an entry 
     * was not able to be saved to the cache.
     * 
     * @throws CacheableException
     * @return void
     */
    public function process() {
        $jobConfig = $this->getCustomConfig();
        
        $service = $jobConfig['CachableChunkedRefreshJobStorageService']['service'];
        $chunk = $jobConfig['CachableChunkedRefreshJobStorageService']['chunk'];

        $memIni = ini_get('memory_limit');
        $memIniToBytes = $this->parseMemory($memIni);

        /*
         * Update QueuedJobService's idea of what its upper memory bounds should 
         * be. We actually perform our own peak memory checks in process(), allowing
         * a buffer between current peak memory and PHP's actual allocated memory.
         */
        $this->overrideQueuedJobServiceMemLimit($memIniToBytes);

        $i = 1;
        foreach($chunk as $object) {
            $service->set_model($object);

            /*
             * Check memory on each iteration. Throw exception at a predefined 
             * upper limit but only if memory_limit is unsigned.
             */
            if($memIniToBytes > 0) {
                $memThreshold = (($memIniToBytes * 1024 * 1024) - self::$critical_memory_buffer);
                $memPeak = memory_get_peak_usage(true);
                if($memPeak >= $memThreshold) {
                    $msg = 'Critical memory threshold reached in cache refresh job (' . $memPeak . ' bytes)';
                    throw new CacheableException($msg);
                }
            }
            
            $this->currentStep = $i++;

            /*
             * Only if refreshCachedPage() signals it completed A-OK and saved its payload
             * to the cachestore, do we then update the job status to 'complete'.
             */
            if(!$service->refreshCachedPage()) {
                $msg = 'Unable to cache object #' . $object->ID;
                throw new CacheableException($msg);
            }
        }
        
        if($service && !$service->completeBuild()) {
            $msg = 'Unable to complete cache build';
            throw new CacheableException($msg);
        }
        
        $this->isComplete = true;
    }

    /**
     * 
     * Uses the QUEUED type, to ensure we make as efficient use of system resources 
     * as possible.
     * 
     * @return number
     */
    public function getJobType() {
        return QueuedJob::QUEUED;
    }

    /**
     * 
     * By default {@link AbstractQueuedJob} will only queue 1 "identical" job at 
     * a time, so an implementation of this method is necessary becuase we need 
     * to fire-off multiple jobs for processing a different chunk of objects.
     * 
     * See the QueuedJobs' wiki for more info:
     * https://github.com/silverstripe-australia/silverstripe-queuedjobs/wiki/Defining-queued-jobs
     * 
     * @return string
     */
    public function getSignature() {
        parent::getSignature();
        return $this->randomSignature();
    }

    /**
     * Convert memory limit string to bytes. Based on implementation 
     * in {@link QueuedJobService}.
     *
     * @param string $memString
     * @return float
     */
    protected function parseMemory($memString) {
        switch(strtolower(substr($memString, -1))) {
            case "b":
                return round(substr($memString, 0, -1));
            case "k":
                return round(substr($memString, 0, -1) * 1024);
            case "m":
                return round(substr($memString, 0, -1) * 1024 * 1024);
            case "g":
                return round(substr($memString, 0, -1) * 1024 * 1024 * 1024);
            default:
                return round($memString);
        }
    }

    /**
     * 
     * @var int $newLimit   A new limit for QueuedJobService::memory_limit in bytes.
     */
    protected function overrideQueuedJobServiceMemLimit($newLimit) {
        Config::inst()->update('QueuedJobService', 'memory_limit', $newLimit);
    }

}
