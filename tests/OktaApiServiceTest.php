<?php

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
    public function setUpOnce()
    {
        parent::setUpOnce();

        Phockito::include_hamcrest();
    }

    /**
     * Get mock service and {@link OktaService} for unit tests
     */
    public function setUp()
    {
        parent::setUp();

        $this->mockOktaApiService = Injector::inst()->get('MockOktaApiService');
        $this->oktaService = Injector::inst()->get('OktaService');
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
        $oktaJob = Injector::inst()->create('SyncOktaUsersJob');

        $method = new ReflectionMethod('SyncOktaUsersJob', 'splitUsersIntoCategories');
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

        $this->assertContains('membertwo@example.org', $toDeleteEmails);
    }

    public function testGetOktaAllUsers()
    {
        $users = $this
            ->oktaService
            ->getAllUsers($this->mockOktaApiService->limit, $this->mockOktaApiService->statuses);

        $this->assertEquals(5, count($users));
    }

    public function testInsertUsers()
    {
        $users = $this->oktaService->getAllUsers(
            $this->mockOktaApiService->limit,
            $this->mockOktaApiService->statuses
        );

        // get a user we know should be inserted
        $insertUser = $users[1];

        $job = Injector::inst()->create('SyncOktaUsersJob');

        $method = new ReflectionMethod('SyncOktaUsersJob', 'insertUsers');
        $method->setAccessible(true);
        $method->invokeArgs($job, [[$insertUser]]);

        $member = Member::get()->filter('Email', 'user2@test.com')->first();

        $this->assertTrue($member instanceof Member);
        $this->assertEquals(true, $member->IsOktaMember);
        $this->assertEquals('2017-06-07 02:35:15', $member->LastEdited);
        $this->assertEquals('user2', $member->FirstName);
        $this->assertEquals('surname2', $member->Surname);
        $this->assertEquals('02242242', $member->PrimaryPhone);
        $this->assertEquals('Developer', $member->JobTitle);
        $this->assertEquals('fdsfdal8Fda1QadafdafdafdafdsafdsafwsLgkxnKqygrty2eCUU9UdkZNaM//9k=', $member->EncodedProfilePicture);
    }

    public function testUpdateUsers()
    {
        // reset back to default for testing otherwise any custom fields added will create invalid UPDATE sql
        Config::inst()->remove('OktaProfileMemberExtension', 'okta_ss_member_fields_name_map');
        Config::inst()->update('OktaProfileMemberExtension', 'okta_ss_member_fields_name_map', [
            'FirstName'             => 'profile.firstName',
            'Surname'               => 'profile.lastName',
            'Email'                 => 'profile.email',
            'PrimaryPhone'          => 'profile.primaryPhone',
            'JobTitle'              => 'profile.title',
            'EncodedProfilePicture' => 'profile.thumbnailPhoto'
        ]);

        $member = Member::get()->filter('Email', 'user1@test.com')->first();

        $this->assertEquals(1, $member->ID);
        $this->assertEquals('Member', $member->FirstName);
        $this->assertEquals('One', $member->Surname);

        $users = $this->oktaService->getAllUsers(
            $this->mockOktaApiService->limit,
            $this->mockOktaApiService->statuses
        );

        // get a user we know should be inserted
        $updateUser = $users[0];

        $job = Injector::inst()->create('SyncOktaUsersJob');

        $method = new ReflectionMethod('SyncOktaUsersJob', 'updateUsers');
        $method->setAccessible(true);
        $method->invokeArgs($job, [[$updateUser], 'Email']);

        $member = Member::get()->filter('Email', 'user1@test.com')->first();

        $this->assertTrue($member instanceof Member);
        $this->assertEquals(true, $member->IsOktaMember);
        $this->assertEquals('user1', $member->FirstName);
        $this->assertEquals('', $member->Surname);
        $this->assertTrue(empty($member->lastName));
        $this->assertEquals(1, $member->ID);
    }

    public function testDeleteUsers()
    {
        $member = Member::get()->filter('Email', 'membertwo@example.org')->first();

        $this->assertTrue($member instanceof Member);

        $job = Injector::inst()->create('SyncOktaUsersJob');

        $method = new ReflectionMethod('SyncOktaUsersJob', 'deleteUsers');
        $method->setAccessible(true);
        $method->invokeArgs($job, [['membertwo@example.org'], 'Email']);

        $member = Member::get()->filter('Email', 'membertwo@example.org')->first();

        $this->assertNull($member);
    }

    public function testGetGroups()
    {
        $service = $this->oktaService;

        // make the getGroups call
        $response = $service->getGroups();

        // assert the expected keys exist
        $this->assertTrue(isset($response['Headers']));
        $this->assertTrue(isset($response['Contents']));

        // assert the values from the response are the expected values
        $this->assertEquals(3, count($response['Contents']));

        // assert the properties we expect are available
        foreach ($response['Contents'] as $group) {
            $this->assertTrue(isset($group->id));
            $this->assertTrue(isset($group->profile->name));
        }

        // assert values from first group
        $group1 = $response['Contents'][0];

        $this->assertEquals('1', $group1->id);
        $this->assertEquals('Okta group 1', $group1->profile->name);
    }

    public function testGetUsersFromGroup()
    {
        $users = $this
            ->oktaService
            ->getAllUsersFromGroup($this->mockOktaApiService->limit, $this->mockOktaApiService->groupID);

        $this->assertTrue(is_array($users));
        $this->assertEquals(5, count($users));
    }

    public function testGetOktaGroupUsers()
    {
        $response = $this
            ->oktaService
            ->getUsersFromGroup(
                $this->mockOktaApiService->limit,
                '',
                $this->mockOktaApiService->groupID
            );

        $users = $response['Contents'];

        // assert the shape of the response
        $this->assertTrue(is_array($response));
        $this->assertTrue(is_array($users));

        // ensure the returned data does not exceed the limit used
        $this->assertTrue($this->mockOktaApiService->limit <= count($users));

        // ensure expected keys are present
        foreach ($users as $user) {
            $this->assertTrue(isset($user['id']));
            $this->assertTrue(isset($user['status']));
            $this->assertTrue(isset($user['created']));

            // we know these fields exist because it is mock data, but they don't
            // always contain each of these keys
            $this->assertTrue(isset($user['profile']['firstName']));
            $this->assertTrue(isset($user['profile']['lastName']));
            $this->assertTrue(isset($user['profile']['primaryPhone']));
            $this->assertTrue(isset($user['profile']['email']));
            $this->assertTrue(isset($user['profile']['title']));
            $this->assertTrue(isset($user['profile']['thumbnailPhoto']));
        }

        // get the first user for some assertions
        $firstUser = $users[0];

        $this->assertEquals('123', $firstUser['id']);
        $this->assertEquals('Active', $firstUser['status']);
        $this->assertEquals('2016-05-06T00:52:58.000Z', $firstUser['created']);
        $this->assertEquals('user1', $firstUser['profile']['firstName']);
        $this->assertEquals('', $firstUser['profile']['lastName']);
        $this->assertTrue(empty($firstUser['profile']['lastName']));
        $this->assertEquals('01111111', $firstUser['profile']['primaryPhone']);
        $this->assertEquals('user1@test.com', $firstUser['profile']['email']);
        $this->assertEquals('Manager', $firstUser['profile']['title']);
        $this->assertEquals(
            'b58feP8A4zal8Fda1Q6t4QsfDdzrumx3OZJdMkiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k=',
            $firstUser['profile']['thumbnailPhoto']
        );
    }
}
