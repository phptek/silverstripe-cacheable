<?php
/**
 * 
 * Extends SiteConfig and confers cache modification abilities 
 * onto it by means of standard SilverStripe onXX() methods.
 * 
 * @author Deviate Ltd 2015 http://www.deviate.net.nz
 * @package silverstripe-cachable
 */
class SiteConfigCacheable extends DataExtension {

    /**
     * 
     * @return void
     */
    public function onAfterWrite() {
        $stageMapping = array(
            "Stage" => "stage",
            "Live" => "live"
        );

        foreach($stageMapping as $stage => $mode) {
            $service = new CacheableNavigationService($mode, $this->owner);
            $service->refreshCachedConfig();
        }
    }

}
