<?php
/**
 * 
 * @author Deviate Ltd 2014-2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 * 
 * Configure the module's storage:
 * 
 * The default is to use memcached for the cache store, but this can be overriden in 
 * project YML config. You can also optionally override the default "server" array 
 * normally passed to {@link SS_Cache} and {@link Zend_Cache}. See the README.
 */

define('CACHEABLE_STORE_DIR', TEMP_FOLDER . DIRECTORY_SEPARATOR . 'module-cacheable');
define('CACHEABLE_STORE_DIR_TEST', TEMP_FOLDER . DIRECTORY_SEPARATOR . 'module-cacheable-tests');
define('CACHEABLE_STORE_NAME', 'cacheablestore');
define('CACHEABLE_STORE_FOR', 'Cacheable');
define('CACHEABLE_STORE_WEIGHT', 1000);

CacheableConfig::configure();
SS_Cache::pick_backend(CACHEABLE_STORE_NAME, CACHEABLE_STORE_FOR, CACHEABLE_STORE_WEIGHT);
