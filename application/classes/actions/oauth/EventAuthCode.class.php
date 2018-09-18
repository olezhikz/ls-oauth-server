<?php

use \League\OAuth2\Server\Exception\OAuthServerException;
/**
 * Description of EventAuthCode
 *
 * @author oleg
 */
class ActionOauth_EventAuthCode extends Event {
    
    public function Init() {
        /*
         * Инициализируем сервер и тип гранта
         */
        $this->oServer = $this->Oauth_GetServer('authorization_code');      
        /*
         * Добавляем параметр тип гранта в запрос
         */
        $this->oRequest = $this->oRequest->withQueryParams(
            array_merge(
                $this->oRequest->getQueryParams(),
                [
                    'response_type' => 'code'
                ]
            )
        );
        
       
    }        
    
    public function EventAuth() {

        try {
            /*
             * Определяем ключ для AuthRedirect
             */
            $iAuthRequestKey = 'oAuthRequest';
            /*
             * Дополнительные параметры для редиректов
             */
            $sQuery = http_build_query([
                'return_path' => urlencode( Router::GetPath('oauth/authorization_code')),
                'auth_request_key' => $iAuthRequestKey
            ]);
            /*
            * Проверяем нет ли уже AuthRequest в сессии
            */
            if(!$sAuthRequest = $this->Session_Get($iAuthRequestKey)){
                /*
                 * Если нет запускаем новую авторизацию
                 */
                $this->oAuthRequest = $this->oServer->validateAuthorizationRequest($this->oRequest);
            }else{
                $this->oAuthRequest = unserialize($sAuthRequest);
                /*
                 * Если state передан и  иной чем в сессии обновляем AuthRequest
                 */
                if(getRequest('state', $this->Session_Get('state')) != $this->oAuthRequest->getState()){
                    $this->Session_Drop('oAuthRequest');
                    $this->Session_Drop('state');
                    $this->oAuthRequest = $this->oServer->validateAuthorizationRequest($this->oRequest);
                }
            }
            /*
             * Устанавливаем redirect_uri чтобы не был обязательным в запросе
             */
            $this->oAuthRequest->setRedirectUri(getRequest('redirect_uri', $this->oAuthRequest->getClient()->getRedirectUri()));
            /*
             * Устанавливаем state если пуст
             */ 
            if(!$this->oAuthRequest->getState()){
               
                $aScopes = [];
                foreach($this->oAuthRequest->getScopes() as $eScope){
                    $aScopes[] = $eScope->getIdentifier();
                }
                
                $sState = $this->Oauth_GenerateState(
                    $this->oAuthRequest->getClient()->getIdentifier(),
                    join(',', $aScopes)
                );
                
                $this->oAuthRequest->setState( $sState );
                
            }
            
            $this->Session_Set('state', $this->oAuthRequest->getState());
            
            /*
             * Проверяем на авторизацию
             */
            if($this->User_IsAuthorization()){
                /*
                 * Конвертируем пользователя
                 */
                $eUser = $this->Oauth_GetUserEntity( $this->User_GetUserCurrent() );
                $this->oAuthRequest->setUser($eUser);
                
                $this->Session_Set($iAuthRequestKey, serialize($this->oAuthRequest));
            }else{
                $this->Session_Set($iAuthRequestKey, serialize($this->oAuthRequest));
                /*
                 * Отправляем на авторизацию
                 */
                Router::Location(Router::GetPath('auth'). '?' . $sQuery);
            }
            /*
             * Проверка подтверждал ли пользователь запрашиваемые скоупы для этого приложения
             * если да то setAuthorizationApproved(true)
             */
            $this->AuthCodeExists();
            /*
             * Отправляем на проверку приложения и прав
             */
            if(!$this->oAuthRequest->isAuthorizationApproved()){
                Router::Location(Router::GetPath('oauth/client_approve'). '?' . $sQuery);
            }          

            /*
             * Отправка кода
             */
            $this->Session_Drop('oAuthRequest');
            $this->Session_Drop('state');
                    
            $oResponse = new \Slim\Http\Response();
            $oResponse = $this->oServer->completeAuthorizationRequest($this->oAuthRequest, $oResponse);
            /*
             * Перенаправление с кодом
             */
            $aLocation = $oResponse->getHeader('Location');
            if(!is_array($aLocation) and !count($aLocation)){
                throw OAuthServerException::serverError("Unknown error");
            }
            
            Router::Location(array_shift($aLocation));
            
            $this->SetTemplate(false);

        }  catch (\Exception $exception) {
            return Router::ActionError($exception->getHint(),$exception->getMessage());
            
        }
        
    }
    
    
    public function AuthCodeExists() {
        $aFilter = [
            'user_id' => $this->oAuthRequest->getUser()->getIdentifier(),
            'client_id'=> $this->oAuthRequest->getClient()->getIdentifier()
        ];
        
        $aScopes = $this->oAuthRequest->getScopes();
        /*
         * Выбираем скоупы с необходимостью подтверждения из тех что запрошены
         */
        $aScopesRequested = [];
        /*
         * Дополнительно выбрать объекты запрашиваемых скоупов для соответствия 
         * oAuthRequest как будто он прошел client_approve
         */
        $aScopesApprove = []; 
        foreach ($aScopes as $oScope) {
            if($oScope->getRequested()){
                $aScopesRequested[] = $oScope->getIdentifier();
                $aScopesApprove[] = $oScope;
            }
        }
        $this->oAuthRequest->setScopes($aScopesApprove);
        
        if(count($aScopesRequested)){
            $aFilter['scopes'] = json_encode($aScopesRequested);
        }
        /*
         * Ищем код для приложения и пользователя с подтвержденными скоупами выше
         */
        $oAuthCode = $this->Oauth_GetAuthCodeByFilter($aFilter);
        
        $oClient = $this->oAuthRequest->getClient();
        
        if($oAuthCode and $oClient){
            /*
             * Удаляем старый код, так как все равно создастся новый
             */
            $oAuthCode->Delete();
            $this->oAuthRequest->setAuthorizationApproved(true);
        }
    }
}
