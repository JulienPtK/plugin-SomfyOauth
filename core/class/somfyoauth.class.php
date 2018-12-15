<?php

/*
 * This file is part of the NextDom software (https://github.com/NextDom or http://nextdom.github.io).
 * Copyright (c) 2018 NextDom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 2.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';


class somfyoauth extends eqLogic {
	
	public static function getDeviceType($somfyType) {
		$types = array( 
			'roller_shutter_positionable_stateful_generic' => 'Volet IO',
			'roller_shutter_positionable_stateful_rs100' => 'Volet IO Comfort',
			'roller_shutter_positionable_stateful_roof' => 'Volet Velux IO',
			'hub_connexoon' => 'Hub Connexoon'
		);
		if (isset ($types[$somfyType])) {
			$result = $types[$somfyType];
		} else {
			log::add('somfyoauth', 'debug', 'Type inconnu de device => ' . $somfyType);
			$result = 'inconnu';
		}
		return $result;
	}
	
	public static function convertCommandType($somfyType) {
		$types = array( 
			'integer' => 'numeric'
		);
		if (isset ($types[$somfyType])) {
			$result = $types[$somfyType];
		} else {
			log::add('somfyoauth', 'debug', 'Type inconnu de type de commande => ' . $somfyType);
			$result = 'string';
		}
		return $result;
	}

  	public static function executeQuery($url, $params = array(), $includeAuthentificationHeaders = true) {
		try {
			$ch = curl_init();
			
			//Initiate cURL.
			$defaults = array( 
				CURLOPT_HEADER => 0, 
				CURLOPT_URL => $url, 
				CURLOPT_FRESH_CONNECT => 1, 
				CURLOPT_RETURNTRANSFER => 1, 
				CURLOPT_FORBID_REUSE => 1, 
				CURLOPT_TIMEOUT => 4
			); 
			
			curl_setopt_array($ch, ($defaults + $params)); 
			
			if ($includeAuthentificationHeaders == true) {
				// on récupère les codes et clés
		    	$accessToken = config::byKey('OAuthAccessToken', 'somfyoauth');
				$headers = array();
				$headers[] = "Content-Type: application/json";
				$headers[] = "Authorization: Bearer " . $accessToken . "";
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			} 
			 
			if ( ! $result = curl_exec($ch)) { 
				log::add('somfyoauth', 'debug', 'erreur résultat');
				trigger_error(curl_error($ch)); 
			} 

			curl_close ($ch);  
			log::add('somfyoauth', 'debug', 'Fin de lexecution de la requete sur l API SOMFY. Résultat : ');
			log::add('somfyoauth', 'debug', print_r($result, true));
			$result = json_decode($result, TRUE);
			if (isset($result) && ! isset($result['fault'])) {
				return $result;
			} else if ($result['fault']['detail']['errorcode'] == 'oauth.v2.InvalidAccessToken') {
				trigger_error('Athentification error'); 
			} else if ($result['fault']['detail']['errorcode'] == 'keymanagement.service.access_token_expired') {
				self::getSomfyToken(true);
				return self::executeQuery($url, $params, $includeAuthentificationHeaders);
			}
		}
		catch (Exception $e) {
			log::add('somfyoauth', 'debug', $e->getMessage());
			var_dump($e->getMessage());
		}
    }
    
	public static function createEqFromSomfy($deviceParams) {
		$eqLogic = new somfyoauth();
		$eqLogic->setEqType_name('somfyoauth');
		$eqLogic->setIsEnable(1);
		$name = '';
		$detectedType = self::getDeviceType($deviceParams['type']);
		if ( $detectedType != 'inconnu' ) {
			$name = ucwords($detectedType);
		} else {
			$name = ucwords(str_replace('_', ' ', $deviceParams['type']));
		}
		if ( isset($deviceParams['name']) ) {
			$name .= ' ' . $deviceParams['name'];
		} 
		$eqLogic->setName($name);
		$pieceParent = object::byName($deviceParams['name']);
		if ( is_object($pieceParent) ) {
			$eqLogic->setObject_id($pieceParent->getId());
		}
		$eqLogic->setLogicalId($deviceParams['id']);
		$eqLogic->setIsVisible(1);
		if ( isset($deviceParams['parent_id']) ) {
			$eqLogic->setConfiguration('parentId', $deviceParams['parent_id']);
		}
		$eqLogic->setConfiguration('somfyType', $deviceParams['type']);
		$eqLogic->setConfiguration('siteId', $deviceParams['site_id']);
		$eqLogic->save();
		return $eqLogic;
	}
	
	public static function createCapabilityCommand($eqLogic, $capability) {
		
		$actionCommand = $eqLogic->getCmd('action', $capability['name']);
		if (!is_object($actionCommand)) {
			$actionCommand = new somfyoauthCmd();
			$actionCommand->setName(__(ucfirst($capability['name']), __FILE__));
		}
		$actionCommand->setLogicalId($capability['name']);
		log::add('somfyoauth', 'debug', 'Rattachement de la commande Action à ' . $eqLogic->getId());

		$actionCommand->setEqLogic_id($eqLogic->getId());
		$actionCommand->setType('action');
		if (isset($capability['parameters']) && $capability['parameters']['name'] == 'position' && $capability['parameters']['type'] == 'integer') {
			$actionCommand->setSubType('slider');
		} else {
			$actionCommand->setSubType('other');
		}
		$actionCommand->save();	 
		log::add('somfyoauth', 'debug', 'Création de la commande Action' . $capability['name']);
		
	}
	
	public static function createStateCommand($eqLogic, $name, $type) {

		// création de la commande position
		$infoCommand = $eqLogic->getCmd(null, $name . "_state");
		if (!is_object($infoCommand)) {
			$infoCommand = new somfyoauthCmd();
			$infoCommand->setName(__(lcfirst($name) . "_state", __FILE__));
		}
		$infoCommand->setLogicalId($name . "_state");
		$infoCommand->setEqLogic_id($eqLogic->getId());
		$infoCommand->setType('info');
		$infoCommand->setSubType($type);
		$infoCommand->save();	 
		log::add('somfyoauth', 'info', 'Création de la commande info ' . $name);
	}
   
	public static function syncEQWithSomfy() {
		
		$urlSites = "https://api.somfy.com/api/v1/site";
		$sites = self::executeQuery($urlSites);

		foreach ($sites as $site) {
			log::add('somfyoauth', 'debug', print_r($array, true));
			$siteId = $site['id']; 
			$label = $site['label'];
			log::add('somfyoauth', 'debug', 'Site found : Id  ' . $siteId . ' - Label : ' . $label);

			self::syncSite($siteId);
		}
	}
  
	public static function getSomfyToken($refresh = false) {
		
		try {
			// on récupère les codes et clés
			$oAuthClientID = config::byKey('OAuthClientID', 'somfyoauth');
			$oAuthClientSecret = config::byKey('OAuthClientSecret', 'somfyoauth');
			
			if ($refresh == true) {
				$oAuthRefreshToken = config::byKey('OAuthRefreshToken', 'somfyoauth');
				$url = "https://accounts.somfy.com/oauth/oauth/v2/token?"
				. "client_id=" . $oAuthClientID
			    . "&client_secret=" . $oAuthClientSecret
			    . "&grant_type=refresh_token&refresh_token=" . $oAuthRefreshToken;
			} else {
				$oAuthAuthorizationCode = config::byKey('OAuthAuthorizationCode', 'somfyoauth');
				$url = "https://accounts.somfy.com/oauth/oauth/v2/token?"
				. "client_id=" . $oAuthClientID
			    . "&client_secret=" . $oAuthClientSecret
			    . "&grant_type=authorization_code&code=" . $oAuthAuthorizationCode 
			    . "&redirect_uri=" . urlencode (network::getNetworkAccess('external','proto:ip') . '/plugins/somfyoauth/desktop/modal/OauthReturn.php');
			}
	
			$array = self::executeQuery($url, [], false);

          	$accessToken = $array['access_token'];
			$refreshToken = $array['refresh_token'];
			config::save("OAuthAccessToken", $accessToken, "somfyoauth");
			config::save("OAuthRefreshToken", $refreshToken, "somfyoauth");
	
		} catch (Exception $e) {
			
			log::add('somfyoauth', 'debug', $e->getMessage());
			var_dump($e->getMessage());
		}
	}

	public function syncDevice($device) {
		try {
			log::add('somfyoauth', 'debug', 'Sync from device infos');
			
			foreach($device['states'] as $state) {
				$infoCommand = $this->getCmd('info', $state['name'] . "_state");
				if ($state['type'] == 'integer' && !isset($state['value'])) {
					$state['value'] = 0;
				}
				log::add('somfyoauth', 'debug', 'detected ' .$state['name'] . '_state value for ' . $this->getName() . ' => ' . $state['value']);
				if (is_object($infoCommand) && $infoCommand->execCmd() != $infoCommand->formatValue($state['value'])) {
					log::add('somfyoauth', 'debug', 'update of ' .$state['name'] . '_state value for ' . $this->getName() . ' => ' . $state['value']);
					$infoCommand->setCollectDate('');
					$infoCommand->event($state['value']);
				}
			}
			$infoCommand = $this->getCmd('info', "available_state");
			log::add('somfyoauth', 'debug', 'detected available_state value for ' . $this->getName() . ' => ' . $device['available']);
	
			if (is_object($infoCommand) && $infoCommand->execCmd() != $infoCommand->formatValue($device['available'])) {
				log::add('somfyoauth', 'debug', 'update of available_state value for ' . $this->getName() . ' => ' . $device['available']);
				$infoCommand->setCollectDate('');
				$infoCommand->event($device['available']);
			}
		   	return 1;
    	} catch (Exception $e) {
    		log::add('somfyoauth', 'debug', print_r($e, true));
    	}		
	}

	public function refreshDevice($deviceId) {
		$urlExec = 'https://api.somfy.com/api/v1/device/'. $deviceId;
		$result = self::executeQuery($urlExec);	
		log::add('somfyoauth', 'debug', print_r($result, true));
		$this->syncDevice($result);
	}

	public static function syncSite($siteId) {
		$urlDevices = "https://api.somfy.com/api/v1/site/" . $siteId . "/device";
		$devices = self::executeQuery($urlDevices);
		log::add('somfyoauth', 'debug', 'Retour avec la liste des devices');
		if (isset($result['message']) && $result['message'] == 'site_not_found') {
		    throw new Exception('Site ' . $siteId . ' not found');
		}
		foreach ($devices as $device) {
			log::add('somfyoauth', 'debug', 'Traitement device : Id  ' . $device['id']);

			$logicId = $device['id'];
			$eqLogic = eqLogic::byLogicalId($logicId, 'somfyoauth');
			if (!is_object($eqLogic)) {
				$eqLogic = self::createEqFromSomfy($device);
				foreach($device['capabilities'] as $capability) {
					self::createCapabilityCommand ($eqLogic, $capability);
				}
				foreach($device['states'] as $state) {
					self::createStateCommand ($eqLogic, $state['name'], self::convertCommandType($state['type']));
				}
				self::createStateCommand ($eqLogic, 'available', 'binary');
			}
			$eqLogic->syncDevice($device);
			log::add('somfyoauth', 'debug', 'Fin Traitement device : Id  ' . $device['id']);
      	}
	}
	
	public static function refreshAll() {
		log::add('somfyoauth', 'debug', 'Starting refresh all');
    	try {
    		$eqs = eqLogic::byType('somfyoauth', true);
    		$siteArray = array();
    		foreach ($eqs as $eq) {
    			$siteId = $eq->getConfiguration('siteId');
    			if ( !in_array($siteId, $siteArray) ) {
					log::add('somfyoauth', 'debug', 'Syncing site ' . print_r($siteId, true));
    				self::syncSite(print_r($siteId, true));
	     			$siteArray[] = $siteId;
				}
    			//$eq->refreshState($eq->getLogicalId());
    		}
    	return 1;
    	
    	} catch (Exception $e) {
    		log::add('somfyoauth', 'debug', print_r($e, true));
    	}		
	}
		
	
	public static function executeCommandOnDevice($deviceId, $commandName, $parameters = array()) {
		
		$urlExec = 'https://api.somfy.com/api/v1/device/'. $deviceId .'/exec';

        $data = [
            "name" => $commandName,
            "parameters" => $parameters
		];
        //Encode the array into JSON.
        $jsonDataEncoded = json_encode($data);
		$paramsQuery = array(
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $jsonDataEncoded
		);

		$result = self::executeQuery($urlExec, $paramsQuery);
   		log::add('somfyoauth', 'debug', 'Fin requete ');

		return 1;
	}
	
    public static function cron30() {
    	return self::refreshAll();
    }
    
	public function getImage() {
		$somfyType = $this->getConfiguration('somfyType');
			if (isset($somfyType) && $this->getDeviceType($somfyType) != 'inconnu') {
			return 'plugins/somfyoauth/core/img/' . $somfyType . '.png';	
		} else {
			return null;	
		}
		return null;
	}

}

class somfyoauthCmd extends cmd {
	
    public function execute($_options = array()) {
	   	$eqLogic = $this->getEqLogic();
	   	$eqId = $eqLogic->getLogicalId();

	   	return $eqLogic->executeCommandOnDevice($eqId, $this->getLogicalId());
    }

}

?>
