<?php

namespace NZTA\OktaAPI\Jobs;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DB;
use GuzzleHttp\Client;
use SilverStripe\Core\Environment;
use NZTA\OktaAPI\Extensions\OktaProfileMemberExtension;

class OktaUpdateUserTask extends BuildTask
{
    /**
     * @var string
     */
    protected $title = "Okta update user";

    /**
     * @var string
     */
    protected $description = "Update user data from Okta. ?okta_id=[OktaId]";

    /**
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        if (isset($request['okta_id']) && !empty($request['okta_id'])) {
            $user = $this->getUserFromApi($request['okta_id']);
            $this->processUser($user);
        }
    }

    private function processUser($user)
    {
        $updateFields = Config::inst()->get(OktaProfileMemberExtension::class, 'okta_ss_member_fields_name_map');

        $sql = 'UPDATE Member SET ';
        $params = [];

        foreach ($updateFields as $field => $map) {
            $value = $this->getValueFromUser($user, $map);

            if (!empty($value)) {
                $sql .= $field . '=?,';
                $params[] = $this->getValueFromUser($user, $map);
            }
        }

        $sql = rtrim($sql, ',');
        $sql .= ' WHERE OktaID = ?';
        $params[] = $user['id'];

        try {
            DB::prepared_query($sql, $params);
        } catch (\Exception $e) {
        }
    }

    private function getUserFromApi($id)
    {
        $client = $this->getHttpClient();

        try {
            $response = $client->get('users/' . $id);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param $user
     * @param $key
     * @return null|string
     */
    private function getValueFromUser($user, $key)
    {
        $value = null;

        $oktaFieldParts = explode('.', $key);
        $oktaFieldPartsCount = count($oktaFieldParts);

        if ($oktaFieldPartsCount == 2) {
            $value = isset($user[$oktaFieldParts[0]][$oktaFieldParts[1]])
                ? Convert::raw2sql($user[$oktaFieldParts[0]][$oktaFieldParts[1]])
                : '';
        } elseif ($oktaFieldPartsCount == 1) {
            $value = isset($user[$oktaFieldParts[0]]) ? Convert::raw2sql($user[$oktaFieldParts[0]]) : '';
        }

        return $value;
    }

    /**
     * @return Client
     */
    private function getHttpClient()
    {
        return new Client([
            'base_uri' => Environment::getEnv('SS_OKTA_GATEWAY_REST_URL'),
            'headers' => [
                'Authorization' => sprintf('SSWS %s', Environment::getEnv('SS_OKTA_API_TOKEN')),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }
}
