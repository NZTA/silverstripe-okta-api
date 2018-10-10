<?php

namespace NZTA\OktaAPI\Gateway;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use GuzzleHttp\Exception\ClientException;
use SilverStripe\Core\Injector\Injector;
use Exception;

/**
 * This class is used as the gateway to the Okta API and handles the actual
 * requests made to the API.
 */
class OktaGateway
{
    /**
     * Hits the users API endpoint to get a list of users, using the given
     * limit and after parameters. If the statuses parameter is provided, it
     * will filter users by the given statuses.
     *
     * @param int $limit
     * @param string $after
     * @param array $statuses
     * @param int $lastUpdated
     *
     * @return array
     * @throws Exception
     */
    public function getUsers($limit, $after, $statuses, $lastUpdated = null)
    {
        $statusParam = '';
        $afterParam = $after ? sprintf('&after=%s', $after) : '';

        if (count($statuses)) {
            $statusParam .= '&filter=';

            if ($lastUpdated) {
                $statusParam .= rawurlencode('(');
            }

            // create the status filters and add to the filter parameter
            foreach ($statuses as $status) {
                // add the "or" if not the last item
                if (next($statuses) !== false) {
                    $statusParam .= rawurlencode(sprintf('status eq "%s" or ', $status));
                } else {
                    $statusParam .= rawurlencode(sprintf('status eq "%s"', $status));
                }
            }

            if ($lastUpdated) {
                $statusParam .= rawurlencode(')');
            }
        }

        if ($lastUpdated) {
            $statusParam .= rawurlencode(' and lastUpdated gt "') . gmdate('Y-m-d\TH:i:s.000\Z', $lastUpdated);
            $statusParam .= rawurlencode('"');
        }

        return $this->get(sprintf(
            'users?limit=%d%s%s',
            $limit,
            $statusParam,
            $afterParam
        ));
    }

    /**
     * Makes the GET request to the Okta Groups API, sending the "after" header
     * to get the next paginated list of results if defined.
     *
     * @param integer $limit
     * @param string $after
     *
     * @return array
     * @throws Exception
     */
    public function getGroups($limit, $after)
    {
        $afterParam = $after ? sprintf('&after=%s', $after) : '';

        return $this->get(sprintf(
            'groups?limit=%d%s',
            $limit,
            $afterParam
        ));
    }

    /**
     * Hits the groups API endpoint to get a list of users, using the given
     * limit, after parameters and Group ID.
     *
     * @param int $limit
     * @param string $after
     * @param string $groupID
     *
     * @return array
     * @throws Exception
     */
    public function getUsersFromGroup($limit, $after, $groupID)
    {
        $afterParam = $after ? sprintf('&after=%s', $after) : '';

        return $this->get(sprintf(
            'groups/%s/users?limit=%d%s',
            $groupID,
            $limit,
            $afterParam
        ));
    }

    /**
     * Make a GET call to the Okta API and return the response headers and
     * contents.
     *
     * @param string $endpoint The API endpoint including the GET parameters.
     *
     * @return array
     *
     * @throws Exception
     */
    public function get($endpoint)
    {
        $client = new Client([
            'base_uri' => Environment::getEnv('SS_OKTA_GATEWAY_REST_URL'),
            'headers' => [
                'Authorization' => sprintf('SSWS %s', Environment::getEnv('SS_OKTA_API_TOKEN')),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);

        try {
            $response = $client->get($endpoint);

            // check what status code we get
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return [
                    'Contents' => $response->getBody()->getContents(),
                    'Headers' => $response->getHeaders()
                ];
            } else {
                throw new Exception(sprintf(
                    'StatusCode: %s. StatusDescription: %s.',
                    $statusCode,
                    $response->getStatusDescription()
                ));
            }

        } catch (ClientException $e) {
            $this->getLogger()->error(
                sprintf(
                    'Error in OktaGateway::call(%s). %s',
                    $endpoint,
                    $e->getMessage()
                ),
                [
                    'Body' => $e
                ]
            );
        } catch (Exception $e) {
            $this->getLogger()->error(
                sprintf(
                    'Error in OktaGateway::call(%s). %s',
                    $endpoint,
                    $e->getMessage()
                ),
                [
                    'Body' => $e
                ]
            );
        }

        return null;
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
