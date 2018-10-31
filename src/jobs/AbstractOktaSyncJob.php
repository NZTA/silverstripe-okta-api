<?php

namespace NZTA\OktaAPI\Jobs;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * This is being used to provide basic rescheduling of the current job and
 * ability to define a list of additional jobs that can be queued once this
 * job is completed.
 *
 * We also provide the ability to set warning messages once a set threshold of
 * items are set to be deleted from the DB.
 */
abstract class AbstractOktaSyncJob extends AbstractQueuedJob implements QueuedJob
{

    /**
     * @var array
     */
    protected static $additional_job_list = [];

    /**
     * The limit of users to delete before the job will log an INFO level message
     *
     * @var integer
     */
    protected static $deleted_warning_threshold = 20;

    /**
     * The limit of sql insert queries per insert in @insertUsers method
     *
     * @var integer
     */
    protected static $bulk_insert_pagination_limit = 500;

    /**
     * The limit of sql update queries per update in @updateUsers method
     *
     * @var integer
     */
    protected static $bulk_update_pagination_limit = 300;

    /**
     * Queue up the next job to run.
     */
    protected function scheduleNextExecution()
    {
        // re-create this job
        $class = get_class($this);
        $job = new $class();

        // queue up to go using the reschedule time length
        singleton(QueuedJobService::class)
            ->queueJob(
                $job,
                date('Y-m-d H:i:s', time() + Config::inst()->get($class, 'reschedule_time'))
            );
    }

    /**
     * Schedule additional jobs
     *
     * @return void
     */
    protected function scheduleAdditionalJobs()
    {
        $class = get_class($this);
        $jobs = Config::inst()->get($class, 'additional_job_list');

        if (count($jobs) > 0) {
            foreach ($jobs as $job) {
                $jobObj = Injector::inst()->get($job);
                $scheduleTime = (isset($jobObj->schedule_after)) ? $jobObj->schedule_after : 30;

                singleton(QueuedJobService::class)
                    ->queueJob(
                        $jobObj,
                        date('Y-m-d H:i:s', time() + $scheduleTime)
                    );
            }
        }
    }

    /**
     * Complete the job so it can removed from the queue
     *
     * @return void
     */
    protected function markJobAsDone()
    {
        $this->totalSteps = 0;
        $this->isComplete = true;
    }

    /**
     * Get a logger
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
