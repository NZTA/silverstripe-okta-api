<?php

class SyncOktaGroupsJob extends AbstractOktaSyncJob implements QueuedJob
{

    /**
     * @var OktaService
     */
    public $OktaService;

    /**
     * @var array
     */
    private static $dependencies = [
        'OktaService' => '%$OktaService',
    ];

    /**
     * Time in seconds to reschedule for, from when this job finishes.
     *
     * @var integer
     */
    private static $reschedule_time = 86400;

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Sync the groups from Okta into the SilverStripe CMS';
    }

    /**
     * Use the {@link OktaService} to get the full list of groups from the Okta
     * API.
     *
     * We then compare the groups from Okta to the records stored in the
     * DB and use it determine which groups need to be created, updated or
     * removed.
     *
     * We split out the groups into their own arrays to make it easy
     * to create a single SQL call for each (as we may be processing a large
     * amount of groups and want to prevent a separate DB call for each group).
     *
     * We finish up by rescheduling this job and marking as complete so it can
     * be removed from the queue.
     *
     * @return void
     */
    public function process()
    {
        $this->syncOktaGroups();
        $this->scheduleNextExecution();
        $this->scheduleAdditionalJobs();
        $this->markJobAsDone();
    }

    /**
     * Retrieve all the groups from Okta and save all the groups that match
     * the filters created in the CMS. If no filters are added to the CMS,
     * save all the groups.
     *
     * @return void
     */
    private function syncOktaGroups()
    {
        $groups = $this->OktaService->getAllGroups();
        $groupFilters = OktaGroupFilter::get();
        $hasFilters = ($groupFilters->count() > 0);
        $createdGroups = 0;
        $updatedIds = [];

        // go through each group from the API and save if applicable
        foreach ($groups as $group) {
            if ($this->saveGroup($group, $groupFilters, $hasFilters)) {
                $createdGroups++;
            }

            // record each group id from the API so we can remove groups if necessary
            if (isset($group->id)) {
                $updatedIds[] = $group->id;
            }
        }

        // remove any groups we have created from Okta that no longer exist in Okta
        $this->removeDeletedGroups($updatedIds);

        $this->addMessage(sprintf(
            'Created %d groups',
            $createdGroups
        ));
    }

    /**
     * Save the group into the CMS unless there are filters. If there are
     * filters, check the group matches against one of the filters first.
     *
     * Also check we have not already created this group from Okta.
     *
     * @param stdObject $group The group data
     * @param DataList $filters The list of OktaGroupFilters
     * @param boolean $hasFilters Is there any filters?
     *
     * @return boolean
     */
    private function saveGroup($group, $filters, $hasFilters)
    {
        if ($hasFilters && !$this->checkMatchesFilter($group, $filters)) {
            return false;
        }

        // base the unique code on the Okta group ID
        $groupId = isset($group->id) ? $group->id : 'Okta Group';
        $groupName = isset($group->profile->name) ? $group->profile->name : '';

        // ensure this group has not already been created
        if (Group::get()->filter('OktaGroupID', $groupId)->first()) {
            return false;
        }

        // otherwise we create the new group
        $newGroup = new Group();
        $newGroup->Title = $groupName ? $groupName : $groupId;
        $newGroup->OktaGroupID = $groupId;
        $newGroup->OktaGroupName = $groupName;
        $newGroup->IsOktaGroup = true;

        $newGroup->write();

        return true;
    }

    /**
     * Check if the specified group matches against one of the filters. We
     * assume the filter keys can only be nested two levels down using the
     * dot (e.g. "key.subkey") syntax.
     *
     * @param stdObject $group
     * @param DataList $filters
     *
     * @return boolean
     */
    private function checkMatchesFilter($group, $filters)
    {
        // check through each of the filters to see if any match the current group
        foreach ($filters as $filter) {
            $filterKey = $filter->Filter;
            $filterValue = $filter->Value;

            // provide ability to filter by a nested key
            $filterKeyParts = explode('.', $filterKey);
            $partsCount = count($filterKeyParts);

            // assume only two levels deep for filtering
            if ($partsCount == 2) {
                $groupValue = isset($group->$filterKeyParts[0]->$filterKeyParts[1])
                    ? $group->$filterKeyParts[0]->$filterKeyParts[1]
                    : null;
            } elseif ($partsCount == 1) {
                $groupValue = isset($group->$filterKeyParts[0])
                    ? $group->$filterKeyParts[0]
                    : null;
            }

            // if we find one matching filter, we should save this group
            if ($groupValue == $filterValue) {
                return true;
            }
        }

        // if we don't match against any filters, we shouldn't create this group
        return false;
    }

    /**
     * Compare the list of current groups from the Okta API and remove any SS
     * created groups that are Okta flagged groups if they no longer exist.
     *
     * @param array $updatedIds
     *
     * @return void
     */
    private function removeDeletedGroups($updatedIds)
    {
        // check we have ids from the API
        if (count($updatedIds) > 0) {
            $currentGroups = Group::get()
                ->filter('IsOktaGroup', true)
                ->column('OktaGroupID');

            // check we have current okta groups
            if (count($currentGroups) > 0) {
                $toDelete = array_diff($currentGroups, $updatedIds);
                $toDeleteCount = count($toDelete);

                // check if we have any groups to delete
                if ($toDeleteCount > 0) {
                    $delete = SQLDelete::create('"Group"');

                    foreach ($toDelete as $id) {
                        $delete->addWhere([
                            '"Group"."OktaGroupID"' => $id
                        ]);
                    }

                    // set to use OR instead of AND
                    $delete->useDisjunction();

                    try {
                        $delete->execute();

                        // inform job we have deleted groups
                        $this->addMessage(sprintf(
                            'Deleted %d groups',
                            $toDeleteCount
                        ));
                    } catch (Exception $e) {
                        SS_Log::log(
                            sprintf(
                                'Error occurred attempting to delete users in SyncOktaGroupsJob. %s',
                                $e->getMessage()
                            ),
                            SS_Log::ERR
                        );
                    }
                }
            }
        }
    }

}
