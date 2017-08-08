<?php

/**
 * This class is responsible for adding Okta specific data to Members.
 * Class OktaProfileMemberExtension
 */
class OktaProfileMemberExtension extends DataExtension
{

    /**
     * @var array
     */
    private static $okta_ss_member_fields_name_map = [
        'FirstName'             => 'profile.firstName',
        'Surname'               => 'profile.lastName',
        'Email'                 => 'profile.email',
        'PrimaryPhone'          => 'profile.primaryPhone',
        'JobTitle'              => 'profile.title',
        'EncodedProfilePicture' => 'profile.thumbnailPhoto',
        'LastEdited'            => 'lastUpdated'
    ];

    /**
     * @var array
     */
    private static $db = [
        'JobTitle'              => 'Varchar(100)',
        'PrimaryPhone'          => 'Varchar',
        'OktaStatus'            => 'ENUM("Active,Password_expired,Locked_out,Recovery", "Active")',
        'IsOktaMember'          => 'Boolean',
        'EncodedProfilePicture' => 'Text'
    ];

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        $tabFieldsArray = [
            TextField::create('JobTitle', 'Job Title'),
            TextField::create('PrimaryPhone', 'Primary Phone'),
            DropdownField::create('OktaStatus', 'Okta Status', $this->owner->dbObject('OktaStatus')->enumValues()),
            CheckboxField_Readonly::create('IsOktaMember', 'Is Okta Member?')
        ];

        $encodedProfilePicture = $this->owner->EncodedProfilePicture;
        if ($encodedProfilePicture) {
            array_push(
                $tabFieldsArray,
                LiteralField::create(
                    'EncodedProfilePicture',
                    sprintf('<img src="%s"/>', $this->getProfileImageSrc())
                )
            );
        }

        $fields->addFieldsToTab(
            'Root.Okta',
            $tabFieldsArray
        );
    }

    /**
     * Helper get the <img> src string for the EncodedProfilePicture
     *
     * @return string
     */
    public function getProfileImageSrc()
    {
        $encodedProfilePicture = $this->owner->EncodedProfilePicture;

        return $encodedProfilePicture
            ? sprintf('data:image/jpg;base64,%s', Convert::raw2sql($encodedProfilePicture))
            : '';
    }

}
