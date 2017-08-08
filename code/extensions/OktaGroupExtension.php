<?php

class OktaGroupExtension extends DataExtension
{

    /**
     * @var array
     */
    private static $db = [
        'OktaGroupID' => 'Varchar(255)',
        'OktaGroupName' => 'Varchar(255)',
        'IsOktaGroup' => 'Boolean',
    ];

    /**
     * @return FieldList
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab(
            'Root.Okta',
            [
                ReadonlyField::create('OktaGroupID', 'Okta Group ID'),
                ReadonlyField::create('OktaGroupName', 'Okta Group Name'),
                CheckboxField_Readonly::create('IsOktaGroup', 'Is an Okta group?')
            ]
        );
    }

}
