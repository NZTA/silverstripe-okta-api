<?php

namespace NZTA\OktaAPI\Admin;

use SilverStripe\Admin\ModelAdmin;
use NZTA\OktaAPI\Model\OktaGroupFilter;

class OktaGroupFilterAdmin extends ModelAdmin
{
    /**
     * @var string
     */
    private static $menu_title = 'Okta Group Filters';

    /**
     * @var string
     */
    private static $url_segment = 'oktafilters';

    /**
     * @var array
     */
    private static $managed_models = [
        OktaGroupFilter::class,
    ];

}
