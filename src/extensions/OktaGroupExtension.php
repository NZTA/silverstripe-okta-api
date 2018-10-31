<?php

namespace NZTA\OktaAPI\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\CheckboxField_Readonly;

class OktaGroupExtension extends DataExtension
{
    /**
     * @var array
     */
    private static $db = [
        'OktaGroupID'   => 'Varchar(255)',
        'OktaGroupName' => 'Varchar(255)',
        'IsOktaGroup'   => 'Boolean',
    ];

    /**
     * @param FieldList $fields
     *
     * @return void
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab(
            'Root.Okta',
            [
                ReadonlyField::create('OktaGroupID', 'Okta Group ID'),
                ReadonlyField::create('OktaGroupName', 'Okta Group Name'),
                CheckboxField_Readonly::create('IsOktaGroup', 'Is an Okta group?'),
            ]
        );
    }
}
