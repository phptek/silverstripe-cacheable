<?php
/**
 * 
 * Looking at the refresh BuildTask: We break the entire iteration over the $pages 
 * result-set into chunks depending on peak memory usage which is checked at each
 * iteration. If meory exceeeds a preset limit, we pass processing of the cache-refresh
 * onto a job queue. 
 * 
 * The idea is that each chunk should be managable enough in terms of memory and 
 * execution time to run even on the smallest of host setups, rather than iteratively 
 * refreshing all objects in the same chunk of N 100s of pages, and consuming a ton 
 * of system resources.
 * 
 * Rough testing has yielded ~300Mb peak memory use on a 4GB RAM, non-SSD machine
 * with 500Mb allocated to PHP, on a v3.1 site with 700 identical pages, _without_
 * chunking.
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link CacheableNavigation_Rebuild}.
 */
class CachableChunkedRefreshJob extends AbstractQueuedJob {
    
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
    public $chunkingErrorMsg = '';
    
    /**
     * "Magic" setters managed by magic methods on {@link AbstractQueuedJob}.
     * 
     * @param CacheableNavigationService $service
     * @param array $chunk An array of DataObjects to process as a "chunk"
     */
	public function __construct(CacheableNavigationService $service, $chunk) {
		if($service && $chunk) {
            $this->setService($service);
            $this->setChunk($chunk);
			$this->totalSteps = 1;
		}
	}
    
    /**
     * 
     * @return string
     */
    public function getTitle() {
        return 'Scheduled refreshing of ' . count($this->chunk) . ' objects.';
    }
    
    /**
     * 
     * The body of the job: Runs the memory-intensive refreshXX() method on each page
     * of the passed $chunk, using the passed $service.
     * 
     * Sets an error message viewable in the CMS' "Jobs" section, if an entry 
     * was not able to be saved to the cache.
     */
    public function process() {
        foreach($this->chunk as $object) {
            $this->service->set_model($object);
            // Only if refreshCachedPage() signals it completed A-OK and saved its payload
            // to the cachestore, do we then update the job status to 'complete'.
            if(!$this->service->refreshCachedPage()) {
                $errorMsg = 'Unable to save object#' . $object->ID . ' to cache.';
                $this->addMessage($errorMsg);
                if(!$this->chunkingErrorMsg) {
                    $this->chunkingErrorMsg = $errorMsg;
                }
            }
        }
        
        $this->currentStep = 1;
        $this->isComplete = true;
    }
    
}
