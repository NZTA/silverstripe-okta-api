<?php

namespace NZTA\OktaAPI\Services;

use NZTA\OktaAPI\Gateway\OktaGateway;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;

/**
 * A service class used to interact with the Okta API via an {@link OktaGateway}
 * dependency. This class is used to format the data back into SilverStripe
 * objects that can be used to loop through in a SilverStripe fashion.
 *
 * @package okta-api
 */
class OktaService
{

    /**
     * The lifetime of the cache for the getUsers call, in seconds.
     *
     * @var integer
     */
    private static $users_cache_lifetime = 86400;

    /**
     * The lifetime of the cache for the getAllGroups call, in seconds.
     *
     * @var integer
     */
    private static $groups_cache_lifetime = 86400;

    /**
     * The lifetime of the cache for the getUsersFromGroup call, in seconds.
     *
     * @var integer
     */
    private static $group_users_cache_lifetime = 86400;

    /**
     * This defines which DB field uniquely identifies an Okta member so when
     * the sync queued job is run, we can determine if they already exist or
     * not.
     *
     * @var string
     */
    private static $member_unique_identifier = 'Email';

    /**
     * @var OktaGateway
     */
    public $OktaGateway;

    /**
     * @var array
     */
    private static $dependencies = [
        'OktaGateway' =>  '%$' . OktaGateway::class,
    ];

    /**
     * Get the full list of users, with the help of the getUsers method.
     *
     * @param int $limit
     * @param array $statuses
     * @param int $lastUpdated
     *
     * @return array
     * @throws \Exception
     */
    public function getAllUsers($limit = 100, $statuses = [], $lastUpdated = null)
    {
        $data = [];
        $after = '';

        while (!is_null($after)) {
            $usersData = $this->getUsers($limit, $after, $statuses, $lastUpdated);

            // this returns null if request fails
            if (is_array($usersData)) {
                $data = array_merge($data, $usersData['Contents']);
            }

            // check to see if we have an "after" value from the Link header
            $after = $this->getAfterFromLinkHeader($usersData);
        }

        return $data;
    }

    /**
     * Get a subset list of users, starting from a particular cursor point if
     * necessary. This will return both the response headers and contents from
     * the request.
     *
     * @param int $limit
     * @param string $after
     * @param array $statuses
     * @param int $lastUpdated
     *
     * @return array
     * @throws \Exception
     */
    public function getUsers($limit = 100, $after = '', $statuses = [], $lastUpdated = null)
    {
        // get the cache for this service
        $cache = Injector::inst()->get(CacheInterface::class . '.OktaService');

        // base cache key on group id and limit specified
        $cacheKey = md5(sprintf('getUsers-%d-%s-%s-%d', $limit, $after, implode('-', $statuses), $lastUpdated));

        // attempt to retrieve ArrayList of posts from cache
        if (!($response = $cache->get($cacheKey))) {
            $response = $this->OktaGateway->getUsers($limit, $after, $statuses, $lastUpdated);

            if (!is_array($response)) {
                return null;
            }

            // decode the json data before storing into the cache
            $response['Contents'] = json_decode($response['Contents'] ?? '');

            $cache->set($cacheKey, $response, Config::inst()->get('OktaService', 'users_cache_lifetime'));
        }

        return $response;
    }

    /**
     * Get all the groups from the Okta Group API, setting a limit per "page"
     * of results returned.
     *
     * @param integer $limit
     *
     * @return array
     * @throws \Exception
     */
    public function getAllGroups($limit = 100)
    {
        $data = [];
        $after = '';

        while (!is_null($after)) {
            $groupsData = $this->getGroups($limit, $after);

            // this returns null if request fails
            if (is_array($groupsData)) {
                $data = array_merge($data, $groupsData['Contents']);
            }

            // check to see if we have an "after" value from the Link header
            $after = $this->getAfterFromLinkHeader($groupsData);
        }

        return $data;
    }

    /**
     * Get a "page" of groups from the Okta Groups API, using the after GET
     * parameter.
     *
     * @param integer $limit
     * @param string $after
     *
     * @return array
     * @throws \Exception
     */
    public function getGroups($limit = 100, $after = '')
    {
        // get the cache for this service
        $cache = Injector::inst()->get(CacheInterface::class . '.OktaService');

        // base cache key on group id and limit specified
        $cacheKey = md5(sprintf('getGroups-%d-%s', $limit, $after));

        // attempt to retrieve ArrayList of groups from cache
        if (!($response = $cache->get($cacheKey))) {
            $response = $this->OktaGateway->getGroups($limit, $after);

            if (!is_array($response)) {
                return null;
            }

            // decode the json data before storing into the cache
            $response['Contents'] = json_decode($response['Contents'] ?? '');

            $cache->set($cacheKey, $response, Config::inst()->get('OktaService', 'groups_cache_lifetime'));
        }

        return $response;
    }

    /**
     * Get the full list of users for this $groupID group, with the help of the getUsersFromGroup method.
     *
     * @param int $limit
     * @param string $groupID
     *
     * @return array
     */
    public function getAllUsersFromGroup($limit, $groupID)
    {
        $data = [];
        $after = '';

        while (!is_null($after)) {
            $usersData = $this->getUsersFromGroup($limit, $after, $groupID);

            // this returns null if request fails
            if (is_array($usersData)) {
                $data = array_merge($data, $usersData['Contents']);
            }

            // check to see if we have an "after" value from the Link header
            $after = $this->getAfterFromLinkHeader($usersData);
        }

        return $data;
    }

    /**
     * Get a subset list of users for this $groupID group, starting from a particular cursor point if
     * necessary. This will return both the response headers and contents from
     * the request.
     *
     * @param int $limit
     * @param string $after
     * @param string $groupID
     *
     * @return array
     * @throws \Exception
     */
    public function getUsersFromGroup($limit, $after, $groupID)
    {
        // get the cache for this service
        $cache = Injector::inst()->get(CacheInterface::class . '.OktaService');

        // base cache key on group id and limit specified
        $cacheKey = md5(sprintf('getUsersGroup-%d-%s-%s', $limit, $after, $groupID));

        // attempt to retrieve ArrayList of posts from cache
        if (!($response = $cache->get($cacheKey))) {
            $response = $this->OktaGateway->getUsersFromGroup($limit, $after, $groupID);

            if (!is_array($response)) {
                return null;
            }

            // decode the json data before storing into the cache
            $response['Contents'] = json_decode($response['Contents'] ?? '', true);

            $cache->set($cacheKey, $response, Config::inst()->get('OktaService', 'group_users_cache_lifetime'));
        }

        return $response;
    }

    /**
     * Helper method to check if there is a "next" link provided from an API
     * response in the "Link" header. If found, return the "after" parameter
     * so that we can set the offset in a subsequent call if required.
     *
     * @param array $data
     *
     * @return string|null
     */
    private function getAfterFromLinkHeader($data)
    {
        $after = null;
        $linkHeader = (isset($data['Headers']['link'])) ? $data['Headers']['link'] : null;

        // parse the "Link" header
        if ($linkHeader) {
            $links = \GuzzleHttp\Psr7\Header::parse($linkHeader);

            // try and find the "rel=next" link
            foreach ($links as $link) {
                if (isset($link[0]) && isset($link['rel']) && $link['rel'] == 'next') {
                    // found, so now extract the GET parameters, trimming off the surrounding angle brackets
                    $url = rtrim(ltrim($link[0], '<'), '>');
                    $query = parse_url($url, PHP_URL_QUERY) ?? false;

                    // now extract the "after" parameter
                    if ($query !== false) {
                        parse_str($query, $getParams);

                        return (isset($getParams['after'])) ? $getParams['after'] : $after;
                    }
                }
            }
        }

        return $after;
    }
}
