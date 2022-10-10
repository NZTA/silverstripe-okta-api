<?php

namespace NZTA\OktaAPI\Tests;

use NZTA\OktaAPI\Extensions\OktaProfileMemberExtension;
use NZTA\OktaAPI\Jobs\SyncOktaUsersJob;
use ReflectionMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use NZTA\OktaAPI\Services\OktaService;
use SilverStripe\Security\Member;

class OktaApiServiceTest extends SapphireTest
{

    /**
     * @var string
     */
    protected static $fixture_file = './OktaApiServiceTest.yml';

    /**
     * @var OktaService
     */
    private $oktaService;

    /**
     * @var MockOktaApiService
     */
    private $mockOktaApiService;

    /**
     * To include Hamcrest
     */
    public function setUpOnce(): void
    {
        parent::setUpOnce();
    }

    /**
     * Get mock service and {@link OktaService} for unit tests
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->mockOktaApiService = Injector::inst()->get(MockOktaApiService::class);
        $this->oktaService = Injector::inst()->get(OktaService::class);
        $this->oktaService->OktaGateway = $this->mockOktaApiService->getGateway();
    }

    public function testGetOktaUsers()
    {
        $response = $this
            ->oktaService
            ->getUsers(
                $this->mockOktaApiService->limit,
                '',
                $this->mockOktaApiService->statuses
            );

        $users = $response['Contents'];

        // assert the shape of the response
        $this->assertTrue(is_array($response));
        $this->assertTrue(is_array($users));

        // ensure the returned data does not exceed the limit used
        $this->assertTrue($this->mockOktaApiService->limit <= count($users));

        // ensure expected keys are present
        foreach ($users as $user) {
            $this->assertTrue(isset($user->id));
            $this->assertTrue(isset($user->status));
            $this->assertTrue(isset($user->created));

            // we know these fields exist because it is mock data, but they don't
            // always contain each of these keys
            $this->assertTrue(isset($user->profile->firstName));
            $this->assertTrue(isset($user->profile->lastName));
            $this->assertTrue(isset($user->profile->primaryPhone));
            $this->assertTrue(isset($user->profile->email));
            $this->assertTrue(isset($user->profile->title));
            $this->assertTrue(isset($user->profile->thumbnailPhoto));
        }

        // get the first user for some assertions
        $firstUser = $users[0];

        $this->assertEquals('123', $firstUser->id);
        $this->assertEquals('Active', $firstUser->status);
        $this->assertEquals('2016-05-06T00:52:58.000Z', $firstUser->created);
        $this->assertEquals('user1', $firstUser->profile->firstName);
        $this->assertEquals('', $firstUser->profile->lastName);
        $this->assertTrue(empty($firstUser->profile->lastName));
        $this->assertEquals('01111111', $firstUser->profile->primaryPhone);
        $this->assertEquals('user1@test.com', $firstUser->profile->email);
        $this->assertEquals('Manager', $firstUser->profile->title);
        $this->assertEquals(
            'b58feP8A4zal8Fda1Q6t4QsfDdzrumx3OZJdMkiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k=',
            $firstUser->profile->thumbnailPhoto
        );

        // since we set a limit, ensure value of last user is as expected
        $this->assertEquals('758', $users[2]->id);
    }

    public function testSplitUsersIntoCategories()
    {
        $oktaJob = Injector::inst()->create(SyncOktaUsersJob::class);

        $method = new ReflectionMethod(SyncOktaUsersJob::class, 'splitUsersIntoCategories');
        $method->setAccessible(true);

        $categories = $method->invokeArgs(
            $oktaJob,
            [
                $this->oktaService->getAllUsers(
                    $this->mockOktaApiService->limit,
                    $this->mockOktaApiService->statuses
                ),
                'Email'
            ]
        );

        // ensure keys expected keys exist
        $this->assertTrue(is_array($categories));
        $this->assertTrue(isset($categories['Insert']));
        $this->assertTrue(isset($categories['Update']));
        $this->assertTrue(isset($categories['Delete']));

        $this->assertEquals(4, count($categories['Insert']));
        $this->assertEquals(1, count($categories['Update']));
        $this->assertEquals(1, count($categories['Delete']));

        // ensure the right people are inserted, updated and deleted
        $firstInsert = $categories['Insert'][0];

        $this->assertEquals('456', $firstInsert->id);

        $firstUpdate = $categories['Update'][0];

        $this->assertEquals('123', $firstUpdate->id);

        $toDeleteEmails = $categories['Delete'];

        $this->assertStringContainsString('membertwo@example.org', $toDeleteEmails);
    }

    public function testGetOktaAllUsers()
    {
        $users = $this
            ->oktaService
            ->getAllUsers($this->mockOktaApiService->limit, $this->mockOktaApiService->statuses);

        $this->assertEquals(5, count($users));
    }
}
