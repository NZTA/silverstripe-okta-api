<?php

namespace NZTA\OktaAPI\Jobs;

use DateTime;
use NZTA\App\Model\OktaUserGroupFilter;
use SilverStripe\ORM\DB;
use SilverStripe\Core\Convert;
use SilverStripe\Security\Member;
use SilverStripe\Core\Config\Config;
use NZTA\OktaAPI\Services\OktaService;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\QueuedJob;
use NZTA\OktaAPI\Extensions\OktaProfileMemberExtension;

class SyncOktaUsersJob extends AbstractOktaSyncJob implements QueuedJob
{

    /**
     * @var OktaService
     */
    public $OktaService;

    /**
     * Time in seconds to reschedule for, from when this job finishes.
     *
     * @var integer
     */
    private static $reschedule_time = 86400;

    /**
     * The whitelist of statuses that filter which users from Okta are synced over.
     *
     * @var array
     */
    private static $statuses_to_sync = [
        'ACTIVE',
        'PASSWORD_EXPIRED',
        'LOCKED_OUT',
        'RECOVERY'
    ];

    /**
     * @var array
     */
    private static $dependencies = [
        'OktaService' => '%$' . OktaService::class,
    ];

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Sync the users from Okta with the SilverStripe Member records';
    }

    /**
     * Use the {@link OktaService} to get the full list of users from the Okta
     * API.
     *
     * We then compare the users from Okta to the records stored in the
     * DB and use it determine which users need to be created, updated or
     * removed.
     *
     * We split out the users into their own arrays to make it easy
     * to create a single SQL call for each (as we are processing a large amount
     * of users and want to prevent a separate DB call for each user).
     *
     * We finish up by rescheduling this job and marking as complete so it can
     * be removed from the queue and create new {@link MapOktaManagerToMemberJob}
     * Job to mapping okta manager ID to SilverStripe ManagerID
     *
     * @return void
     */
    public function process()
    {
        // For each of the user group filters make an api call to get the list of users from okta
        $allUsers = [];
        foreach (OktaUserGroupFilter::get() as $oktaUserGroupFilter) {
            $users = Injector::inst()
                ->get(OktaService::class)
                ->getAllUsersFromGroup(100, $oktaUserGroupFilter->OktaGroupID);
            foreach ($users as $user) {
                $userEmail = strtolower($user['profile']['email']);
                if (!array_key_exists($userEmail, $allUsers)) {
                    $allUsers[$userEmail] = $user;
                }
            }
        }

        // get the member unique identifier field
        $uniqueField = Config::inst()->get(OktaService::class, 'member_unique_identifier');

        // decide which users need to be inserted, updated or deleted
        $categories = $this->splitUsersIntoCategories($allUsers, $uniqueField);

        $usersToInsert = $categories['Insert'];
        $usersToUpdate = $categories['Update'];
        $usersToDelete = $categories['Delete'];

        $this->paginateBulkSqlQueries($usersToInsert, 'insert');
        $this->paginateBulkSqlQueries($usersToUpdate, 'update', $uniqueField);
        $this->deleteUsers($usersToDelete, $uniqueField);

        // check how many users have been deleted
        $deletedUsersCount = count($usersToDelete);

        // add a message to the job to show number of users added, updated and deleted in the CMS
        $this->addMessage(sprintf(
            'Added %d users, updated %d users and deleted %d users.',
            count($usersToInsert),
            count($usersToUpdate),
            $deletedUsersCount
        ));

        // just a heads up to help alert website administrators when larger amounts of users are being deleted
        if ($deletedUsersCount > Config::inst()->get(SyncOktaUsersJob::class, 'deleted_warning_threshold')) {
            $this->getLogger()->warning(
                sprintf('Warning: The SyncOktaUsersJob has deleted %s users.', $deletedUsersCount)
            );
        }

        $this->scheduleNextExecution();
        $this->scheduleAdditionalJobs();
        $this->markJobAsDone();
    }

    /**
     * Set $limit members(data) in a one single insert/update and loop through until end of all members(data) array,
     * instead of all members(data) in one sql insert
     *
     * @param array $data
     * @param string $queryType
     * @param string|null $uniqueField
     *
     * @return bool|void
     */
    private function paginateBulkSqlQueries($data, $queryType, $uniqueField = null)
    {
        if ($queryType == 'insert') {
            $limit = Config::inst()->get(SyncOktaUsersJob::class, 'bulk_insert_pagination_limit');
        } elseif ($queryType == 'update') {
            $limit = Config::inst()->get(SyncOktaUsersJob::class, 'bulk_update_pagination_limit');
        }

        while (count($data) > 0) {
            // Get first $limit elements of the array
            $queryData = array_slice($data, 0, $limit, true);
            if ($queryType == 'insert') {
                $this->insertUsers($queryData);
            } elseif ($queryType == 'update') {
                $this->updateUsers($queryData, $uniqueField);
            }

            // Assign rest of the array elements to $data again. (simply removing $queryData from $data array)
            $data = array_slice($data, $limit, null, true);
        }
    }

    /**
     * Given a list of users from Okta, split them into 3 categories on whether
     * they need to be added, updated or removed from the SS database.
     *
     * @param array $users
     * @param string $uniqueField
     *
     * @return array
     */
    private function splitUsersIntoCategories(array $users, $uniqueField)
    {
        $data = [
            'Insert' => [],
            'Update' => [],
            'Delete' => []
        ];

        if (count($users) == 0) {
            return $data;
        }

        // get the current {@link Member} records that are flagged as Okta members
        $oktaMembers = Member::get()->filter('IsOktaMember', true);

        // get the list of current ids that exist in the DB (by unique id)
        $currentIds = $oktaMembers->column($uniqueField);

        // get okta mapped unique id
        $fieldMapping = Config::inst()->get(OktaProfileMemberExtension::class, 'okta_ss_member_fields_name_map');
        $oktaUniqueField = $fieldMapping[$uniqueField];

        // put users into the category that relates
        foreach ($users as $user) {
            if (is_array($user)) {
                $user = (object) $user;
            }

            $userId = $this->getValueFromUser($user, $oktaUniqueField);

            // catch case if no user id field, shouldn't ever get here
            if (!$userId) {
                continue;
            }

            // put user into either update or insert array depending if they exist
            if (in_array($userId, $currentIds)) {
                // update if already in DB
                $data['Update'][] = $user;
            } else {
                // insert if not already in DB
                $data['Insert'][] = $user;
            }

            // deduct user from current list
            $key = array_search($userId, $currentIds);
            if ($key !== false) {
                unset($currentIds[$key]);
            }
        }

        // remaining current ids should be deleted as they weren't in fresh list
        $data['Delete'] = $currentIds;

        return $data;
    }

    /**
     * Create and execute a single INSERT statement to add all provided users
     * to the DB.
     *
     * @param array $users
     *
     * @return void
     */
    private function insertUsers(array $users)
    {
        if (count($users) > 0) {
            $insert = new SQLInsert('Member');

            $fields = Config::inst()->get(OktaProfileMemberExtension::class, 'okta_ss_member_fields_name_map');

            try {
                // add row of data to insert for each user
                foreach ($users as $user) {
                    // mapping okta fields to SS Member fields
                    foreach ($fields as $ssFieldName => $oktaFieldName) {
                        $valueFromUser = $this->getValueFromUser($user, $oktaFieldName);

                        // Convert string to date format for LastEdited field
                        $value = ($ssFieldName == 'LastEdited') ? new DateTime($valueFromUser) : $valueFromUser;
                        $addRow[$ssFieldName] = $valueFromUser;
                    }

                    // ensure member is flagged as an okta member
                    $addRow['IsOktaMember'] = true;

                    $insert->addRow($addRow);
                }

                // execute the INSERT statement
                $insert->execute();
            } catch (\Exception $e) {
                $this->getLogger()->error(
                    sprintf(
                        'Error occurred attempting to insert users in SyncOktaUsersJob. %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Create a single UPDATE statement to update existing Member records from
     * the provided user data.
     *
     * @param array $users
     * @param string $uniqueField
     *
     * @return void
     */
    private function updateUsers(array $users, $uniqueField)
    {
        if (count($users) > 0) {
            $updateIds = [];

            // create the sql update statement
            $sql = 'UPDATE Member SET ';

            // define DB to okta mappings
            $updateFields = Config::inst()->get(OktaProfileMemberExtension::class, 'okta_ss_member_fields_name_map');

            // create the SET and CASE statements
            foreach ($updateFields as $updateField => $profileKey) {
                $last = next($updateFields) === false;

                // create the CASE statement for this DB field
                $caseStatement = sprintf('CASE %s ', $uniqueField);

                // get the okta key for the unique identifier
                $fieldMapping = Config::inst()->get(OktaProfileMemberExtension::class, 'okta_ss_member_fields_name_map');
                $oktaUniqueField = $fieldMapping[$uniqueField];

                // create a WHEN statement for each user
                foreach ($users as $user) {
                    $id = $this->getValueFromUser($user, $oktaUniqueField);
                    $value = $this->getValueFromUser($user, $profileKey);

                    // ensure the field is available from okta adding WHEN statement
                    if ($id) {
                        // add id into list of users being updated if not already added
                        if (!in_array($id, $updateIds)) {
                            $updateIds[] = "{$id}";
                        }

                        // add the WHEN statement for this user for the current SET statement
                        $caseStatement .= sprintf("WHEN '%s' THEN '%s' ", $id, $value);
                    }
                }

                // create the SET statement for the current DB field, only adding a comma if we are not at the last key
                $sql .= sprintf(
                    "%s = (%sELSE '' END)%s ",
                    $updateField,
                    $caseStatement,
                    $last ? '' : ','
                );
            }

            // add the WHERE statement to limit Members to those being updated
            $sql .= sprintf("WHERE %s IN ('%s')", $uniqueField, implode("','", $updateIds));

            try {
                // run the UPDATE statement
                DB::query($sql);
            } catch (\Exception $e) {
                $this->getLogger()->error(
                    sprintf(
                        'Error occurred attempting to update users in SyncOktaUsersJob. %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Create a single DELETE statment to remove existing Member records that
     * no longer exist in Okta.
     *
     * @param array $userIds
     * @param $uniqueField
     *
     * @return void
     */
    private function deleteUsers(array $userIds, $uniqueField)
    {
        if (count($userIds)) {
            $delete = new SQLDelete('Member');

            // add each id as a WHERE clause
            foreach ($userIds as $id) {
                $delete->addWhere(sprintf("%s = %s", $uniqueField, Convert::raw2sql($id, true)));
            }

            // split each WHERE by an OR
            $delete->useDisjunction();

            try {
                // execute DELETE statement
                $delete->execute();
            } catch (\Exception $e) {
                $this->getLogger()->error(
                    sprintf(
                        'Error occurred attempting to delete users in SyncOktaUsersJob. %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Helper to get nested keys from a user data array from the Okta API,
     * given a specific key.
     *
     * @param array $user
     * @param string $key
     *
     * @return string
     */
    private function getValueFromUser($user, $key)
    {
        $value = '';

        // allow the ability to use nested fields
        $oktaFieldParts = explode('.', $key);
        $oktaFieldPartsCount = count($oktaFieldParts);

        // we are going to assume there can only be 1 level deep
        if ($oktaFieldPartsCount == 2) {
            $part0 = $oktaFieldParts[0];
            $part1 = $oktaFieldParts[1];
            if (is_array($user->$part0)) {
                $user->$part0 = (object) $user->$part0;
            }
            $value = isset($user->$part0->$part1)
                ? Convert::raw2sql($user->$part0->$part1)
                : '';
        } elseif ($oktaFieldPartsCount == 1) {
            $userKey = $oktaFieldParts[0];
            $value =  isset($user->$userKey) ? Convert::raw2sql($user->$userKey) :  '';
        }

        return $value;
    }
}
