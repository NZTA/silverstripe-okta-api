<?php

namespace NZTA\OktaAPI\Tests;

use Phake;
use NZTA\OktaAPI\Gateway\OktaGateway;

class MockOktaApiService
{
    /**
     * @var int
     */
    public $limit = 3;

    /**
     * @var array
     */
    public $statuses = [
        'ACTIVE',
        'PASSWORD_EXPIRED',
        'LOCKED_OUT',
        'RECOVERY'
    ];

    /**
     * @var int
     */
    public $after = '758';

    /**
     * @var string
     */
    public $filter = 'status eq "ACTIVE" or status eq "PASSWORD_EXPIRED" or status eq "LOCKED_OUT" or status eq "RECOVERY"';

    /**
     * @var string
     */
    public $groupID = '4324fdsa24';

    /**
     * @return OktaGateway
     */
    public function getGateway()
    {
        $gateway = Phake::mock(OktaGateway::class);

        Phake::when($gateway)
            ->getUsers($this->limit, '', $this->statuses, null)
            ->thenReturn($this->getMockResponseGetUsers());

        Phake::when($gateway)
            ->getUsers($this->limit, $this->after, $this->statuses, null)
            ->thenReturn($this->getMockResponseGetUsers($this->after));

        Phake::when($gateway)
            ->getGroups($this->limit, '')
            ->thenReturn($this->getMockResponseGetGroups());

        Phake::when($gateway)
            ->getUsersFromGroup($this->limit, '', $this->groupID)
            ->thenReturn($this->getMockResponseGetUsers());

        Phake::when($gateway)
            ->getUsersFromGroup($this->limit, $this->after, $this->groupID)
            ->thenReturn($this->getMockResponseGetUsers($this->after));

        return $gateway;
    }

    /**
     * Mocked response when hitting API endpoint used to retrieve users from okta api
     *
     * @param string $after
     *
     * @return array
     */
    public function getMockResponseGetUsers($after = '')
    {
        $users = [
            [
                'id'          => '123',
                'status'      => 'Active',
                'created'     => '2016-05-06T00:52:58.000Z',
                'lastUpdated' => '2017-06-07T02:35:15.000Z',
                'profile'     => [
                    'firstName'      => 'user1',
                    'lastName'       => '',
                    'primaryPhone'   => '01111111',
                    'email'          => 'user1@test.com',
                    'title'          => 'Manager',
                    'thumbnailPhoto' => 'b58feP8A4zal8Fda1Q6t4QsfDdzrumx3OZJdMkiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k='
                ]

            ],
            [
                'id'          => '456',
                'status'      => 'Active',
                'created'     => '2016-06-06T00:52:58.000Z',
                'lastUpdated' => '2017-06-07T02:35:15.000Z',
                'profile'     => [
                    'firstName'      => 'user2',
                    'lastName'       => 'surname2',
                    'primaryPhone'   => '02242242',
                    'email'          => 'user2@test.com',
                    'title'          => 'Developer',
                    'thumbnailPhoto' => 'fdsfdal8Fda1QadafdafdafdafdsafdsafwsLgkxnKqygrty2eCUU9UdkZNaM//9k='
                ]
            ],
            [
                'id'          => '758',
                'status'      => 'Active',
                'created'     => '2016-09-06T00:52:58.000Z',
                'lastUpdated' => 'now',
                'profile'     => [
                    'firstName'      => 'user3',
                    'lastName'       => 'surname3',
                    'primaryPhone'   => '043243242',
                    'email'          => 'user3@test.com',
                    'title'          => 'Marketing Manager',
                    'thumbnailPhoto' => 'b58feP8A4zal8Fda1Q6t4QsfDdzrumx3OZJdMkiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k='
                ]
            ],
            [
                'id'          => '624',
                'status'      => 'PASSWORD_EXPIRED',
                'created'     => '2016-04-06T00:52:58.000Z',
                'lastUpdated' => 'now',
                'profile'     => [
                    'firstName'      => 'user4',
                    'lastName'       => 'surname4',
                    'primaryPhone'   => '04524584',
                    'email'          => 'user4@test.com',
                    'title'          => 'Designer',
                    'thumbnailPhoto' => 'sdfdasfdsfdasfdsfdadsfasfdsfsdiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k='
                ]
            ],
            [
                'id'          => '842',
                'status'      => 'LOCKED_OUT',
                'created'     => '2016-08-06T00:52:58.000Z',
                'lastUpdated' => 'now',
                'profile'     => [
                    'firstName'      => 'user5',
                    'lastName'       => 'surname5',
                    'primaryPhone'   => '04632565',
                    'email'          => 'user5@test.com',
                    'title'          => 'Administrator',
                    'thumbnailPhoto' => 'fdsafdsasfdsfadfaafdsafdafdfdsarumx3OZJdMkiuLWIxwvnKwsLgkxnKqygrty2eCUU9UdkZNaM//9k='
                ]
            ]
        ];

        $headers = [];

        // get return first $limit items from content array.
        if ($after) {
            $contents = array_slice($users, array_search($this->after, array_column($users, 'id')) + 1, $this->limit);
        } else {
            $contents = array_slice($users, 0, $this->limit);

            // pass back a next Link header so the service knows there is more if they want it
            $headers = [
                'Link' => [
                    sprintf(
                        '<http://example.org/api/v1/users?limit=%d&filter=%s>; rel="self"',
                        $this->limit,
                        $this->filter
                    ),
                    sprintf(
                        '<http://example.org/api/v1/users?after=%s&limit=%d&filter=%s>; rel="next"',
                        $this->after,
                        $this->limit,
                        $this->filter
                    )
                ]
            ];
        }

        $data = [
            'Contents' => json_encode($contents),
            'Headers'  => $headers
        ];

        return $data;
    }

    /**
     * Mock the groups that come from the Okta Groups API.
     *
     * @return array
     */
    public function getMockResponseGetGroups()
    {
        $contents = [];

        for ($i = 1; $i <= 3; $i++) {
            // create new group
            $group = (object)[
                'id'      => sprintf('%s', $i),
                'profile' => (object)[
                    'name' => sprintf('Okta group %d', $i)
                ]
            ];

            // add group to response
            array_push($contents, $group);
        }

        return [
            'Contents' => json_encode($contents),
            'Headers'  => []
        ];
    }
}
