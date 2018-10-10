<?php

namespace NZTA\OktaAPI\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;

class OktaGroupFilter extends DataObject
{

    /**
     * @var array
     */
    private static $db = [
        'Filter' => 'Varchar(255)',
        'Value' => 'Varchar(255)',
    ];

    /**
     * @var array
     */
    private static $summary_fields = [
        'Filter',
        'Value',
    ];

    /**
     * @var string
     */
    private static $table_name = "OktaGroupFilter";

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $filterField = $fields->dataFieldByName('Filter');
        if ($filterField) {
            $filterField->setDescription('The key from each group to filter against (e.g. "id"). You can also use
                nested properties using the dot syntax but this is limited to 2 levels deep (e.g. "profile.name")');
        }

        $valueField = $fields->dataFieldByName('Value');
        if ($valueField) {
            $valueField->setDescription('The value of the above filter key to match against (e.g. "123").');
        }

        return $fields;
    }
}
