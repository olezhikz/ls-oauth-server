<?php
/**
 * Сущность кода
 *
 * @package modules.user
 * @since 1.0
 */
class ModuleOauth_EntityAuthCode extends EntityORM
{

    protected $aJsonFields = ['scopes'];
            
    protected $aRelations = array(
        'client' => array(self::RELATION_TYPE_BELONGS_TO, 'ModuleOauth_EntityClient', 'client_id'),
        'user' => array(self::RELATION_TYPE_BELONGS_TO, 'ModuleUser_EntityUser', 'user_id')
    );
    
    public function addScope($sScope) {
        if(!isset($this->_aData['scopes'])){
            $this->_aData['scopes'] = [];
        }
        $this->_aData['scopes'][] = $sScope;
    }
}