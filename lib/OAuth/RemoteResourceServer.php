<?php

/**
 *  Copyright 2013 François Kooman <fkooman@tuxed.net>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OAuth;

class RemoteResourceServer
{
    private $_config;

    public function __construct(array $c)
    {
        $this->_config = $c;
    }

    public function verifyAndHandleRequest()
    {
        try {
            $headerBearerToken = NULL;
            $queryBearerToken = NULL;

            // look for headers
            if (function_exists("apache_request_headers")) {
                $headers = apache_request_headers();
            } elseif (isset($_SERVER)) {
                $headers = $_SERVER;
            } else {
                $headers = array();
            }

            // look for query parameters
            $query = (isset($_GET) && is_array($_GET)) ? $_GET : array();

            return $this->verifyRequest($headers, $query);

        } catch (RemoteResourceServerException $e) {
            // send response directly to client, halt execution of calling script as well
            $e->setRealm($this->_getRequiredConfigParameter("realm"));
            header("HTTP/1.1 " . $e->getResponseCode());
            header("WWW-Authenticate: " . $e->getAuthenticateHeader());
            header("Content-Type: application/json");
            die($e->getContent());
        }
    }

    public function verifyRequest(array $headers, array $query)
    {
        // extract token from authorization header
        $authorizationHeader = self::_getAuthorizationHeader($headers);
        $ah = FALSE !== $authorizationHeader ? self::_getTokenFromHeader($authorizationHeader) : FALSE;

        // extract token from query parameters
        $aq = self::_getTokenFromQuery($query);

        if (FALSE === $ah && FALSE === $aq) {
            // no token at all provided
            throw new RemoteResourceServerException("no_token", "missing token");
        }
        if (FALSE !== $ah && FALSE !== $aq) {
            // two tokens provided
            throw new RemoteResourceServerException("invalid_request", "more than one method for including an access token used");
        }
        if (FALSE !== $ah) {
            return $this->verifyBearerToken($ah);
        }
        if (FALSE !== $aq) {
            return $this->verifyBearerToken($aq);
        }
    }

    private static function _getAuthorizationHeader(array $headers)
    {
        $headerKeys = array_keys($headers);
        foreach (array("X-Authorization", "Authorization") as $h) {
            $keyPositionInArray = array_search(strtolower($h), array_map('strtolower', $headerKeys));
            if (FALSE === $keyPositionInArray) {
                continue;
            }

            return $headers[$headerKeys[$keyPositionInArray]];
        }

        return FALSE;
    }

    private static function _getTokenFromHeader($authorizationHeader)
    {
        if (0 !== strpos($authorizationHeader, "Bearer ")) {
            return FALSE;
        }

        return substr($authorizationHeader, 7);
    }

    private static function _getTokenFromQuery(array $queryParameters)
    {
        if (!isset($queryParameters) || empty($queryParameters['access_token'])) {
            return FALSE;
        }

        return $queryParameters['access_token'];
    }

    public function verifyBearerToken($token)
    {
        // b64token = 1*( ALPHA / DIGIT / "-" / "." / "_" / "~" / "+" / "/" ) *"="
        if ( 1 !== preg_match('|^[[:alpha:][:digit:]-._~+/]+=*$|', $token)) {
            throw new RemoteResourceServerException("invalid_token", "the access token is not a valid b64token");
        }

        $introspectionEndpoint = $this->_getRequiredConfigParameter("introspectionEndpoint");
        $get = array("token" => $token);

        $curlChannel = curl_init();

        if (0 !== strpos($introspectionEndpoint, "file://")) {
            $separator = (FALSE === strpos($introspectionEndpoint, "?")) ? "?" : "&";
            $introspectionEndpoint .= $separator . http_build_query($get);
        } else {
            // file cannot have query parameter, use accesstoken as JSON file instead
            $introspectionEndpoint .= $token . ".json";
        }
        curl_setopt_array($curlChannel, array (
            CURLOPT_URL => $introspectionEndpoint,
            //CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));

        $output = curl_exec($curlChannel);

        if (FALSE === $output) {
            $error = curl_error($curlChannel);
            throw new RemoteResourceServerException("internal_server_error", "unable to contact introspection endpoint (" . $error . ")");
        }

        $httpCode = curl_getinfo($curlChannel, CURLINFO_HTTP_CODE);
        curl_close($curlChannel);

        if (0 !== strpos($introspectionEndpoint, "file://")) {
            // not a file
            if (200 !== $httpCode) {
                throw new RemoteResourceServerException("internal_server_error", "malformed request to introspection endpoint");
            }
        }

        $data = json_decode($output, TRUE);
        $jsonError = json_last_error();
        if (JSON_ERROR_NONE !== $jsonError) {
            throw new RemoteResourceServerException("internal_server_error", "unable to decode response from introspection endpoint");
        }
        if (!is_array($data) || !isset($data['active']) || !is_bool($data['active'])) {
            throw new RemoteResourceServerException("internal_server_error", "malformed response from introspection endpoint");
        }
        if (!$data['active']) {
            throw new RemoteResourceServerException("invalid_token", "the token is not active");
        }

        return new TokenIntrospection($data);
    }

    private function _getRequiredConfigParameter($key)
    {
        if (!array_key_exists($key, $this->_config)) {
            throw new RemoteResourceServerException("internal_server_error", "missing configuration parameter (" . $key . ")");
        }

        return $this->_config[$key];
    }
}

class TokenIntrospection
{
    private $_response;

    public function __construct(array $response)
    {
        if (!isset($response['active']) || !is_bool($response['active'])) {
            throw new RemoteResourceServerException("internal_server_error", "malformed response from introspection endpoint");
        }
        $this->_response = $response;
    }

    /**
     * REQUIRED.  Boolean indicator of whether or not the presented
     * token is currently active.
     */
    public function getActive()
    {
        return $this->_response['active'];
    }

    /**
     * OPTIONAL.  Integer timestamp, measured in the number of
     * seconds since January 1 1970 UTC, indicating when this token will
     * expire.
     */
    public function getExpiresAt()
    {
        return $this->_getKeyValue('expires_at');
    }

    /**
     * OPTIONAL.  Integer timestamp, measured in the number of
     * seconds since January 1 1970 UTC, indicating when this token was
     * originally issued.
     */
    public function getIssuedAt()
    {
        return $this->_getKeyValue('issued_at');
    }

    /**
     * OPTIONAL.  A space-separated list of strings representing the
     * scopes associated with this token, in the format described in
     * Section 3.3 of OAuth 2.0 [RFC6749].
     */
    public function getScope()
    {
        return $this->_getKeyValue('scope');
    }

    /**
     * OPTIONAL.  Client Identifier for the OAuth Client that
     * requested this token.
     */
    public function getClientId()
    {
        return $this->_getKeyValue('client_id');
    }

    /**
     * OPTIONAL.  Local identifier of the Resource Owner who authorized
     * this token.
     */
    public function getSub()
    {
        return $this->_getKeyValue('sub');
    }

    /**
     * OPTIONAL.  Service-specific string identifier or list of string
     * identifiers representing the intended audience for this token.
     */
    public function getAud()
    {
        return $this->_getKeyValue('aud');
    }

    private function _getKeyValue($key)
    {
        return isset($this->_response[$key]) ? $this->_response[$key] : FALSE;
    }

    /* ADDITIONAL HELPER METHODS */

    public function getResourceOwnerId()
    {
        return $this->getSub();
    }

    public function getScopeAsArray()
    {
        if (FALSE === $this->getScope()) {
            return array();
        } else {
            return explode(" ", $this->getScope());
        }
    }

    public function hasScope($scope)
    {
        if (FALSE === $this->getScopeAsArray()) {
            return FALSE;
        }

        return in_array($scope, $this->getScopeAsArray());
    }

    public function requireScope($scope)
    {
        if (FALSE === $this->hasScope($scope)) {
            throw new RemoteResourceServerException("insufficient_scope", "no permission for this call with granted scope");
        }
    }

    /**
     * At least one of the scopes should be granted.
     *
     * @param  array $scope the list of scopes of which one should be granted
     * @return TRUE  when at least one of the requested scopes was granted,
     *         FALSE when none were granted.
     */
    public function hasAnyScope(array $scope)
    {
        foreach ($scope as $s) {
            if (in_array($s, $this->hasScope())) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /* PROPRIETARY EXTENSION "X-ENTITLEMENT" */
    public function getEntitlement()
    {
        return $this->_getKeyValue('x-entitlement');
    }

    public function getEntitlementAsArray()
    {
        if (FALSE === $this->getEntitlement()) {
            return array();
        } else {
            return explode(" ", $this->getEntitlement());
        }
    }

    public function hasEntitlement($entitlement)
    {
        if (FALSE === $this->getEntitlementAsArray()) {
            return FALSE;
        }

        return in_array($entitlement, $this->getEntitlementAsArray());
    }

    public function requireEntitlement($scope)
    {
        if (FALSE === $this->hasEntitlement($scope)) {
            throw new RemoteResourceServerException("insufficient_scope", "no permission for this call with granted entitlement");
        }
    }
}

class RemoteResourceServerException extends \Exception
{
    private $description;
    private $responseCode;
    private $realm;

    public function __construct($message, $description, $code = 0, Exception $previous = null)
    {
       switch ($message) {
            case "no_token":
            case "invalid_token":
                $this->responseCode = 401;
                break;
            case "insufficient_scope":
            case "insufficient_entitlement":
                $this->responseCode = 403;
                break;
            case "internal_server_error":
                $this->responseCode = 500;
                break;
            case "invalid_request":
            default:
                $this->responseCode = 400;
                break;
        }

        $this->description = $description;
        $this->realm = "Resource Server";

        parent::__construct($message, $code, $previous);
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setRealm($resourceServerRealm)
    {
        $this->realm = (is_string($resourceServerRealm) && !empty($resourceServerRealm)) ? $resourceServerRealm : "Resource Server";
    }

    public function getResponseCode()
    {
        return $this->responseCode;
    }

    public function getAuthenticateHeader()
    {
        $authenticateHeader = NULL;
        if (500 !== $this->responseCode) {
            if ("no_token" === $this->message) {
                // no authorization header is a special case, the client did not know
                // authentication was required, so tell it now without giving error message
                $authenticateHeader = sprintf('Bearer realm="%s"', $this->realm);
            } else {
                $authenticateHeader = sprintf('Bearer realm="%s",error="%s",error_description="%s"', $this->realm, $this->message, $this->description);
            }
        }

        return $authenticateHeader;
    }

    public function getContent()
    {
        return json_encode(array("error" => $this->message, "error_description" => $this->description));
    }

}
