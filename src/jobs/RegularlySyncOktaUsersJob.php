<?php

namespace NZTA\OktaAPI\Jobs;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\Services\QueuedJob;
use NZTA\OktaAPI\Services\OktaService;

class RegularlySyncOktaUsersJob extends AbstractOktaSyncJob implements QueuedJob
{
    /**
     * @var OktaService
     */
    public $OktaService;

    /**
     * @var int
     */
    private static $reschedule_time = 60;

    /**
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
        'OktaService' =>  '%$' . OktaService::class,
    ];

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Regularly sync the users from Okta with the SilverStripe Member records. Runs every minute.';
    }

    /**
     * Update user details if they have been updated in Okta in the last 90 seconds.
     */
    public function process()
    {
        $lastUpdated = (time() - 300);

        $users = Injector::inst()
            ->get(OktaService::class)
            ->getAllUsers(100, self::$statuses_to_sync, $lastUpdated);

        $updateFields = Config::inst()->get('OktaProfileMemberExtension', 'okta_ss_member_fields_name_map');

        foreach ($users as $user) {
            $sql = 'UPDATE Member SET ';
            $params = [];

            foreach ($updateFields as $field => $map) {
                $value = $this->getValueFromUser($user, $map);

                if (!empty($value)) {
                    $sql .= $field . '=?,';
                    $params[] = $this->getValueFromUser($user, $map);
                }
            }

            $sql  = rtrim($sql, ',');
            $sql .= ' WHERE OktaID = ?';
            $params[] = $user->id;

            try {
                DB::prepared_query($sql, $params);
            } catch (\Exception $e) {
                $this->getLogger()->error(
                    sprintf(
                        'Error occurred attempting to update users in RegularlySyncOktaUsersJob. %s',
                        $e->getMessage()
                    )
                );
            }
        }

        $this->addMessage(sprintf('Updated %d users.', count($users)));

        $this->scheduleNextExecution();
        $this->scheduleAdditionalJobs();
        $this->markJobAsDone();
    }

    /**
     * @param Member $user
     * @param string $key
     * @return array|string
     */
    private function getValueFromUser($user, $key)
    {
        $value = '';

        // allow the ability to use nested fields
        $oktaFieldParts = explode('.', $key);
        $oktaFieldPartsCount = count($oktaFieldParts);

        // we are going to assume there can only be 1 level deep
        if ($oktaFieldPartsCount == 2) {
            $value = isset($user->$oktaFieldParts[0]->$oktaFieldParts[1])
                ? Convert::raw2sql($user->$oktaFieldParts[0]->$oktaFieldParts[1])
                : '';
        } elseif ($oktaFieldPartsCount == 1) {
            $value = isset($user->$oktaFieldParts[0]) ? Convert::raw2sql($user->$oktaFieldParts[0]) : '';
        }

        return $value;
    }
}
