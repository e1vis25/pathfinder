<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 23.01.2016
 * Time: 17:18
 *
 * Handles access to EVE-Online "ESI API" and "SSO" auth functions
 * - Add your API credentials in "environment.ini"
 * - Check "PATHFINDER.API" in "pathfinder.ini" for correct API URLs
 * Hint: \Web::instance()->request automatically caches responses by their response "Cache-Control" header!
 */

namespace Controller\Ccp;
use Controller;
use Controller\Api as Api;
use Model;
use Lib;

class Sso extends Api\User{

    /**
     * @var int timeout (seconds) for API calls
     */
    const SSO_TIMEOUT                               = 4;

    /**
     * @var int expire time (seconds) for an valid "accessToken"
     */
    const ACCESS_KEY_EXPIRE_TIME                    = 20 * 60;

    // SSO specific session keys
    const SESSION_KEY_SSO                           = 'SESSION.SSO';
    const SESSION_KEY_SSO_ERROR                     = 'SESSION.SSO.ERROR';
    const SESSION_KEY_SSO_STATE                     = 'SESSION.SSO.STATE';
    const SESSION_KEY_SSO_FROM                      = 'SESSION.SSO.FROM';

    // error messages
    const ERROR_CCP_SSO_URL                         = 'Invalid "ENVIRONMENT.[ENVIRONMENT].CCP_SSO_URL" url. %s';
    const ERROR_CCP_CLIENT_ID                       = 'Missing "ENVIRONMENT.[ENVIRONMENT].CCP_SSO_CLIENT_ID".';
    const ERROR_ACCESS_TOKEN                        = 'Unable to get a valid "access_token. %s';
    const ERROR_VERIFY_CHARACTER                    = 'Unable to verify character data. %s';
    const ERROR_LOGIN_FAILED                        = 'Failed authentication due to technical problems: %s';
    const ERROR_CHARACTER_VERIFICATION              = 'Character verification failed by SSP SSO';
    const ERROR_CHARACTER_DATA                      = 'Failed to load characterData from ESI';
    const ERROR_CHARACTER_FORBIDDEN                 = 'Character "%s" is not authorized to log in. Reason: %s';
    const ERROR_SERVICE_TIMEOUT                     = 'CCP SSO service timeout (%ss). Try again later';
    const ERROR_COOKIE_LOGIN                        = 'Login from Cookie failed. Please retry by CCP SSO';


    /**
     * redirect user to CCP SSO page and request authorization
     * -> cf. Controller->getCookieCharacters() ( equivalent cookie based login)
     * @param \Base $f3
     */
    public function requestAdminAuthorization($f3){
        // store browser tabId to be "targeted" after login
        $f3->set(self::SESSION_KEY_SSO_FROM, 'admin');

        $scopes = self::getScopesByAuthType('admin');
        $this->rerouteAuthorization($f3, $scopes, 'admin');
    }

    /**
     * redirect user to CCP SSO page and request authorization
     * -> cf. Controller->getCookieCharacters() ( equivalent cookie based login)
     * @param \Base $f3
     * @throws \Exception
     */
    public function requestAuthorization($f3){
        $params = $f3->get('GET');

        if(
            isset($params['characterId']) &&
            ( $activeCharacter = $this->getCharacter(0) )
        ){
            // authentication restricted to a characterId -----------------------------------------------
            // restrict login to this characterId e.g. for character switch on map page
            $characterId = (int)trim((string)$params['characterId']);

            /**
             * @var Model\CharacterModel $character
             */
            $character = Model\BasicModel::getNew('CharacterModel');
            $character->getById($characterId, 0);

            // check if character is valid and exists
            if(
                !$character->dry() &&
                $character->hasUserCharacter() &&
                ($activeCharacter->getUser()->_id === $character->getUser()->_id)
            ){
                // requested character belongs to current user
                // -> update character vom ESI (e.g. corp changed,..)
                $updateStatus = $character->updateFromESI();

                if( empty($updateStatus) ){

                    // make sure character data is up2date!
                    // -> this is not the case if e.g. userCharacters was removed "ownerHash" changed...
                    $character->getById($character->_id);

                    if(
                        $character->hasUserCharacter() &&
                        ($character->isAuthorized() === 'OK')
                    ){
                        $loginCheck = $this->loginByCharacter($character);

                        if($loginCheck){
                            // set "login" cookie
                            $this->setLoginCookie($character);

                            // route to "map"
                            $f3->reroute(['map', ['*' => '']]);
                        }
                    }
                }
            }

            // redirect to map map page on successful login
            $f3->set(self::SESSION_KEY_SSO_FROM, 'map');
        }

        // redirect to CCP SSO ----------------------------------------------------------------------
        $scopes = self::getScopesByAuthType();
        $this->rerouteAuthorization($f3, $scopes);
    }

    /**
     * redirect user to CCPs SSO page
     * @param \Base $f3
     * @param array $scopes
     * @param string $rootAlias
     */
    private function rerouteAuthorization(\Base $f3, $scopes = [], $rootAlias = 'login'){
        if( !empty( Controller\Controller::getEnvironmentData('CCP_SSO_CLIENT_ID') ) ){
            // used for "state" check between request and callback
            $state = bin2hex( openssl_random_pseudo_bytes(12) );
            $f3->set(self::SESSION_KEY_SSO_STATE, $state);

            $urlParams = [
                'response_type' => 'code',
                'redirect_uri' => Controller\Controller::getEnvironmentData('URL') . Controller\Controller::getEnvironmentData('BASE') . $f3->build('/sso/callbackAuthorization'),
                'client_id' => Controller\Controller::getEnvironmentData('CCP_SSO_CLIENT_ID'),
                'scope' => implode(' ', $scopes),
                'state' => $state
            ];

            $ssoAuthUrl = self::getAuthorizationEndpoint() . '?' . http_build_query($urlParams, '', '&', PHP_QUERY_RFC3986 );

            $f3->status(302);
            $f3->reroute($ssoAuthUrl);
        }else{
            // SSO clientId missing
            $f3->set(self::SESSION_KEY_SSO_ERROR, self::ERROR_CCP_CLIENT_ID);
            self::getSSOLogger()->write(self::ERROR_CCP_CLIENT_ID);
            $f3->reroute([$rootAlias, ['*' => '']]);
        }
    }

    /**
     * callback handler for CCP SSO user Auth
     * -> see requestAuthorization()
     * @param \Base $f3
     * @throws \Exception
     */
    public function callbackAuthorization($f3){
        $getParams = (array)$f3->get('GET');

        // users can log in either from @login (new user) or @map (existing user) root alias
        // -> or from /admin page
        // -> in case login fails, users should be redirected differently
        $rootAlias = 'login';
        if( !empty($f3->get(self::SESSION_KEY_SSO_FROM)) ){
            $rootAlias = $f3->get(self::SESSION_KEY_SSO_FROM);
        }

        if($f3->exists(self::SESSION_KEY_SSO_STATE)){
            // check response and validate 'state'
            if(
                isset($getParams['code']) &&
                isset($getParams['state']) &&
                !empty($getParams['code']) &&
                !empty($getParams['state']) &&
                $f3->get(self::SESSION_KEY_SSO_STATE) === $getParams['state']
            ){
                // clear 'state' for new next login request
                $f3->clear(self::SESSION_KEY_SSO_STATE);
                $f3->clear(self::SESSION_KEY_SSO_FROM);

                $accessData = $this->getSsoAccessData($getParams['code']);

                if(
                    isset($accessData->accessToken) &&
                    isset($accessData->refreshToken)
                ){
                    // login succeeded -> get basic character data for current login
                    $verificationCharacterData = $this->verifyCharacterData($accessData->accessToken);

                    if( !is_null($verificationCharacterData)){

                        // check if login is restricted to a characterID

                        // verification available data. Data is needed for "ownerHash" check

                        // get character data from ESI
                        $characterData = $this->getCharacterData((int)$verificationCharacterData->CharacterID);

                        if( isset($characterData->character) ){
                            // add "ownerHash" and SSO tokens
                            $characterData->character['ownerHash']          = $verificationCharacterData->CharacterOwnerHash;
                            $characterData->character['crestAccessToken']   = $accessData->accessToken;
                            $characterData->character['crestRefreshToken']  = $accessData->refreshToken;
                            $characterData->character['esiScopes']          = Lib\Util::convertScopesString($verificationCharacterData->Scopes);

                            // add/update static character data
                            $characterModel = $this->updateCharacter($characterData);

                            if( !is_null($characterModel) ){
                                // check if character is authorized to log in
                                if( ($authStatus = $characterModel->isAuthorized()) === 'OK'){
                                    // character is authorized to log in
                                    // -> update character log (current location,...)
                                    $characterModel = $characterModel->updateLog();

                                    // connect character with current user
                                    if( is_null($user = $this->getUser()) ){
                                        // connect character with existing user (no changes)
                                        if( is_null( $user = $characterModel->getUser()) ){
                                            // no user found (new character) -> create new user and connect to character
                                            /**
                                             * @var $user Model\UserModel
                                             */
                                            $user = Model\BasicModel::getNew('UserModel');
                                            $user->name = $characterModel->name;
                                            $user->save();
                                        }
                                    }

                                    /**
                                     * @var $userCharactersModel Model\UserCharacterModel
                                     */
                                    if( is_null($userCharactersModel = $characterModel->userCharacter) ){
                                        $userCharactersModel = Model\BasicModel::getNew('UserCharacterModel');
                                        $userCharactersModel->characterId = $characterModel;
                                    }

                                    // user might have changed
                                    $userCharactersModel->userId = $user;
                                    $userCharactersModel->save();

                                    // get updated character model
                                    $characterModel = $userCharactersModel->getCharacter();

                                    // login by character
                                    $loginCheck = $this->loginByCharacter($characterModel);

                                    if($loginCheck){
                                        // set "login" cookie
                                        $this->setLoginCookie($characterModel);

                                        // -> pass current character data to target page
                                        $f3->set(Api\User::SESSION_KEY_TEMP_CHARACTER_DATA, $characterModel->_id);

                                        // route to "map"
                                        if($rootAlias == 'admin'){
                                            $f3->reroute([$rootAlias, ['*' => '']]);
                                        }else{
                                            $f3->reroute(['map', ['*' => '']]);
                                        }
                                    }else{
                                        $f3->set(self::SESSION_KEY_SSO_ERROR, sprintf(self::ERROR_LOGIN_FAILED, $characterModel->name));
                                    }
                                }else{
                                    // character is not authorized to log in
                                    $f3->set(self::SESSION_KEY_SSO_ERROR,
                                        sprintf(self::ERROR_CHARACTER_FORBIDDEN, $characterModel->name, Model\CharacterModel::AUTHORIZATION_STATUS[$authStatus])
                                    );
                                }
                            }
                        }else{
                            // failed to load characterData from API
                            $f3->set(self::SESSION_KEY_SSO_ERROR, self::ERROR_CHARACTER_DATA);
                        }
                    }else{
                        // failed to verify character by CCP SSO
                        $f3->set(self::SESSION_KEY_SSO_ERROR, self::ERROR_CHARACTER_VERIFICATION);
                    }
                }else{
                    // SSO "accessData" missing (e.g. timeout)
                    $f3->set(self::SESSION_KEY_SSO_ERROR, sprintf(self::ERROR_SERVICE_TIMEOUT, self::SSO_TIMEOUT));
                }
            }else{
                // invalid SSO response
                $f3->set(self::SESSION_KEY_SSO_ERROR, sprintf(self::ERROR_LOGIN_FAILED, 'Invalid response'));
            }
        }

        $f3->reroute([$rootAlias, ['*' => '']]);
    }

    /**
     * login by cookie name
     * @param \Base $f3
     * @throws \Exception
     */
    public function login(\Base $f3){
        $data = (array)$f3->get('GET');
        $cookieName = empty($data['cookie']) ? '' : $data['cookie'];
        $character = null;

        if( !empty($cookieName) ){
            if( !empty($cookieData = $this->getCookieByName($cookieName) )){
                // cookie data is valid -> validate data against DB (security check!)
                if( !empty($characters = $this->getCookieCharacters(array_slice($cookieData, 0, 1, true))) ){
                    // character is valid and allowed to login
                    $character = $characters[$cookieName];
                }
            }
        }

        if( is_object($character)){
            // login by character
            $loginCheck = $this->loginByCharacter($character);
            if($loginCheck){
                // route to "map"
                $f3->reroute(['map', ['*' => '']]);
            }
        }

        // on error -> route back to login form
        $f3->set(self::SESSION_KEY_SSO_ERROR, self::ERROR_COOKIE_LOGIN);
        $f3->reroute(['login']);
    }

    /**
     * get a valid "access_token" for oAuth 2.0 verification
     * -> if $authCode is set -> request NEW "access_token"
     * -> else check for existing (not expired) "access_token"
     * -> else try to refresh auth and get fresh "access_token"
     * @param bool $authCode
     * @return null|\stdClass
     */
    public function getSsoAccessData($authCode){
        $accessData = null;

        if( !empty($authCode) ){
            // Authentication Code is set -> request new "accessToken"
            $accessData = $this->verifyAuthorizationCode($authCode);
        }else{
            // Unable to get Token -> trigger error
            self::getSSOLogger()->write(sprintf(self::ERROR_ACCESS_TOKEN, $authCode));
        }

        return $accessData;
    }

    /**
     * verify authorization code, and get an "access_token" data
     * @param $authCode
     * @return \stdClass
     */
    protected function verifyAuthorizationCode($authCode){
        $requestParams = [
            'grant_type' => 'authorization_code',
            'code' => $authCode
        ];

        return $this->requestAccessData($requestParams);
    }

    /**
     * get new "access_token" by an existing "refresh_token"
     * -> if "access_token" is expired, this function gets a fresh one
     * @param $refreshToken
     * @return \stdClass
     */
    public function refreshAccessToken($refreshToken){
        $requestParams = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        return $this->requestAccessData($requestParams);
    }

    /**
     * request an "access_token" AND "refresh_token" data
     * -> this can either be done by sending a valid "authorization code"
     * OR by providing a valid "refresh_token"
     * @param $requestParams
     * @return \stdClass
     */
    protected function requestAccessData($requestParams){
        $verifyAuthCodeUrl = self::getVerifyAuthorizationCodeEndpoint();
        $verifyAuthCodeUrlParts = parse_url($verifyAuthCodeUrl);

        $accessData = (object) [];
        $accessData->accessToken = null;
        $accessData->refreshToken = null;

        if($verifyAuthCodeUrlParts){
            $contentType = 'application/x-www-form-urlencoded';
            $requestOptions = [
                'timeout' => self::SSO_TIMEOUT,
                'method' => 'POST',
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Basic ' . $this->getAuthorizationHeader(),
                    'Content-Type: ' . $contentType,
                    'Host: ' . $verifyAuthCodeUrlParts['host']
                ]
            ];

            // content (parameters to send with)
            $requestOptions['content'] = http_build_query($requestParams);

            $apiResponse = Lib\Web::instance()->request($verifyAuthCodeUrl, $requestOptions);

            if($apiResponse['body']){
                $authCodeRequestData = json_decode($apiResponse['body'], true);

                if( !empty($authCodeRequestData) ){
                    if( isset($authCodeRequestData['access_token']) ){
                        // this token is required for endpoints that require Auth
                        $accessData->accessToken =  $authCodeRequestData['access_token'];
                    }

                    if(isset($authCodeRequestData['refresh_token'])){
                        // this token is used to refresh/get a new access_token when expires
                        $accessData->refreshToken =  $authCodeRequestData['refresh_token'];
                    }
                }
            }else{
                self::getSSOLogger()->write(
                    sprintf(
                        self::ERROR_ACCESS_TOKEN,
                        print_r($requestParams, true)
                    )
                );
            }
        }else{
            self::getSSOLogger()->write(
                sprintf(self::ERROR_CCP_SSO_URL, __METHOD__)
            );
        }

        return $accessData;
    }

    /**
     * verify character data by "access_token"
     * -> get some basic information (like character id)
     * -> if more character information is required, use ESI "characters" endpoints request instead
     * @param $accessToken
     * @return mixed|null
     */
    public function verifyCharacterData($accessToken){
        $verifyUserUrl = self::getVerifyUserEndpoint();
        $verifyUrlParts = parse_url($verifyUserUrl);
        $characterData = null;

        if($verifyUrlParts){
            $requestOptions = [
                'timeout' => self::SSO_TIMEOUT,
                'method' => 'GET',
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Bearer ' . $accessToken,
                    'Host: ' . $verifyUrlParts['host']
                ]
            ];

            $apiResponse = Lib\Web::instance()->request($verifyUserUrl, $requestOptions);

            if($apiResponse['body']){
                $characterData = json_decode($apiResponse['body']);
            }else{
                self::getSSOLogger()->write(sprintf(self::ERROR_VERIFY_CHARACTER, __METHOD__));
            }
        }else{
            self::getSSOLogger()->write(sprintf(self::ERROR_CCP_SSO_URL, __METHOD__));
        }

        return $characterData;
    }

    /**
     * get character data
     * @param int $characterId
     * @return \stdClass
     * @throws \Exception
     */
    public function getCharacterData(int $characterId) : \stdClass{
        $characterData = (object) [];

        if($characterId){
            $characterDataBasic =  $this->getF3()->ccpClient->getCharacterData($characterId);

            if( !empty($characterDataBasic) ){
                // remove some "unwanted" data -> not relevant for Pathfinder
                $characterData->character = array_filter($characterDataBasic, function($key){
                    return in_array($key, ['id', 'name', 'securityStatus']);
                }, ARRAY_FILTER_USE_KEY);

                $characterData->corporation = null;
                $characterData->alliance = null;

                if($corporationId = (int)$characterDataBasic['corporation']['id']){
                    /**
                     * @var Model\CorporationModel $corporation
                     */
                    $corporation = Model\BasicModel::getNew('CorporationModel');
                    $corporation->getById($corporationId, 0);
                    if( !$corporation->dry() ){
                        $characterData->corporation = $corporation;
                    }
                }

                if($allianceId = (int)$characterDataBasic['alliance']['id']){
                    /**
                     * @var Model\AllianceModel $allianceModel
                     */
                    $alliance = Model\BasicModel::getNew('AllianceModel');
                    $alliance->getById($allianceId, 0);
                    if( !$alliance->dry() ){
                        $characterData->alliance = $alliance;
                    }
                }
            }
        }

        return $characterData;
    }

    /**
     * update character
     * @param \stdClass $characterData
     * @return \Model\CharacterModel|null
     * @throws \Exception
     */
    protected function updateCharacter(\stdClass $characterData){
        $character = null;

        if( !empty($characterData->character) ){
            /**
             * @var Model\CharacterModel $character
             */
            $character = Model\BasicModel::getNew('CharacterModel');
            $character->getById((int)$characterData->character['id'], 0);
            $character->copyfrom($characterData->character, [
                'id', 'name', 'ownerHash', 'crestAccessToken', 'crestRefreshToken', 'esiScopes', 'securityStatus'
            ]);

            $character->corporationId = $characterData->corporation;
            $character->allianceId = $characterData->alliance;
            $character = $character->save();
        }

        return $character;
    }

    /**
     * get "Authorization:" Header data
     * -> This header is required for any Auth-required endpoints!
     * @return string
     */
    protected function getAuthorizationHeader(){
        return base64_encode(
            Controller\Controller::getEnvironmentData('CCP_SSO_CLIENT_ID') . ':'
            . Controller\Controller::getEnvironmentData('CCP_SSO_SECRET_KEY')
        );
    }

    /**
     * get CCP SSO url from configuration file
     * -> throw error if url is broken/missing
     * @return string
     */
    static function getSsoUrlRoot(){
        $url = '';
        if( \Audit::instance()->url(self::getEnvironmentData('CCP_SSO_URL')) ){
            $url = self::getEnvironmentData('CCP_SSO_URL');
        }else{
            $error = sprintf(self::ERROR_CCP_SSO_URL, __METHOD__);
            self::getSSOLogger()->write($error);
            \Base::instance()->error(502, $error);
        }

        return $url;
    }

    static function getAuthorizationEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/authorize';
    }

    static function getVerifyAuthorizationCodeEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/token';
    }

    static function getVerifyUserEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/verify';
    }

    /**
     * get logger for SSO logging
     * @return \Log
     */
    static function getSSOLogger(){
        return parent::getLogger('SSO');
    }
}