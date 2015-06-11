<?php
/**
 * 
 * Provides a persistence cache or "holder" for job-queue data when job is initialised 
 * in {@link QueuedJobService}.
 * 
 * It's operated on by {@link Config} via {@link CachableChunkedRefreshJob} for 
 * use with QueuedJobs.
 * 
 * @author Deviate Ltd 2014-2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 */
class CachableChunkedRefreshJobStorageService {

    /**
     * 
     * @var CacheableNavigationService
     */
    private static $service;

    /**
     * 
     * @var array
     */
    private static $chunk = array();

    /**
     * 
     * @return void
     */
    public function __construct() {
        
    }

}
