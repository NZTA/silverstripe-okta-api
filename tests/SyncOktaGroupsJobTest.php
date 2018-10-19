<?php

namespace NZTA\OktaAPI\Tests;

use NZTA\OktaAPI\Jobs\SyncOktaGroupsJob;
use NZTA\OktaAPI\Model\OktaGroupFilter;
use ReflectionMethod;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use NZTA\OktaAPI\Services\OktaService;
use SilverStripe\Security\Group;

class SyncOktaGroupsJobTest extends SapphireTest
{

    /**
     * @var boolean
     */
    protected $usesDatabase = true;

    /**
     * Global reference to an instance of the {@link OktaService} which we will
     * setup to use a mocked Gateway for each test.
     *
     * @var OktaService|null
     */
    private $OktaService = null;

    /**
     * @var MockOktaApiService
     */
    private $mockOktaApiService;

    public function setUpOnce()
    {
        parent::setUpOnce();
    }

    /**
     * Setting up the mocked Gateway for our OktaService
     */
    public function setUp()
    {
        parent::setUp();

        $this->mockOktaApiService = Injector::inst()->get(MockOktaApiService::class);
        $this->OktaService = Injector::inst()->get(OktaService::class);
        $this->OktaService->OktaGateway = Injector::inst()
            ->get(MockOktaApiService::class)
            ->getGateway();
    }

    public function testSaveGroup()
    {
        $method = new ReflectionMethod(SyncOktaGroupsJob::class, 'saveGroup');
        $method->setAccessible(true);

        // instantiate job to invoke method with
        $job = Injector::inst()->get(SyncOktaGroupsJob::class);

        // use mocked gateway to get a mocked group to use
        $response = $this->OktaService->getGroups($this->mockOktaApiService->limit,'');

        // use first mocked group
        $args = [
            $response['Contents'][0],
            null,
            false,
        ];

        // assume no filters for first test
        $result = $method->invokeArgs($job, $args);

        $this->assertTrue($result);

        // assert some details about created group
        $groups = Group::get();

        // this includes the default ADMIN group that is created
        $this->assertEquals(2, $groups->count());
        $this->assertNotNull($groups->filter('Title', 'Okta group 1')->first());

        // re-run the save job
        $result = $method->invokeArgs($job, $args);

        // assert it has not been re-created
        $this->assertFalse($result);
        $this->assertEquals(2, Group::get()->count());
    }

    public function testCheckMatchesFilter()
    {
        $method = new ReflectionMethod(SyncOktaGroupsJob::class, 'checkMatchesFilter');
        $method->setAccessible(true);

        // instantiate job to invoke method with
        $job = Injector::inst()->get(SyncOktaGroupsJob::class);

        // use mocked gateway to get a mocked group to use
        $response = $this->OktaService->getGroups($this->mockOktaApiService->limit,'');

        // setup some group filters
        $filter1 = new OktaGroupFilter();
        $filter1->Filter = 'id';
        $filter1->Value = '1';
        $filter1->write();

        $filter2 = new OktaGroupFilter();
        $filter2->Filter = 'profile.name';
        $filter2->Value = 'Okta group 2';
        $filter2->write();

        // get the filters we just setup
        $filters = OktaGroupFilter::get();

        // check if first mocked group matches one of our filters
        $result = $method->invokeArgs(
            $job,
            [
                $response['Contents'][0],
                $filters,
            ]
        );

        // first filter should match this group
        $this->assertTrue($result);

        // check if second mocked group matches one of our filters
        $result = $method->invokeArgs(
            $job,
            [
                $response['Contents'][1],
                $filters,
            ]
        );

        // second filter should match this group
        $this->assertTrue($result);

        // check if third mocked group matches one of our filters
        $result = $method->invokeArgs(
            $job,
            [
                $response['Contents'][2],
                $filters,
            ]
        );

        // no filters should match this group
        $this->assertFalse($result);
    }
}
