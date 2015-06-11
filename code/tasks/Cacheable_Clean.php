<?php
/**
 * 
 * This BuildTask completely clears the object cache for {@link CacheableSiteTree} and 
 * {@link CacheableSiteConfig} objects.
 * 
 * The BuildTask should be run via the tasks controller in a browser or from the 
 * command-line as the webserver user as follows:
 * 
 * <code>
 *  #> sudo -u www-data ./framework/sake dev/tasks/CacheableNavigation_Clean
 * <code> 
 * 
 * @author Deviate Ltd 2014-2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * @see {@link Cacheable_Rebuild}.
 */
class Cacheable_Clean extends BuildTask {

    /**
     *
     * @var string
     */
    protected $description = 'Clears silverstripe-cacheable object cache.';

    /**
     * 
     * @param SS_HTTPRequest $request
     */
    public function run($request) {
        $newLine = Cacheable_Rebuild::new_line();

        SS_Cache::pick_backend(CACHEABLE_STORE_NAME, CACHEABLE_STORE_FOR, CACHEABLE_STORE_WEIGHT);
        SS_Cache::factory(CACHEABLE_STORE_FOR)->clean('all');

        echo 'Cleanup: ' . CACHEABLE_STORE_NAME . " done." . $newLine;
    }

}
