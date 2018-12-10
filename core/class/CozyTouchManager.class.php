<?php

require_once dirname(__FILE__) . "/../../3rdparty/cozytouch/constants/CozyTouchConstants.class.php";
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

if (!class_exists('CozyTouchApiClient')) {
	require_once dirname(__FILE__) . "/../../3rdparty/cozytouch/client/CozyTouchApiClient.class.php";
}

if (!class_exists('AbstractCozytouchDevice')) {
	require_once dirname(__FILE__) . "/../devices/AbstractCozytouchDevice.class.php";
}

if (!class_exists('CozytouchAtlanticHeatSystemWithAjustTemp')) {
	require_once dirname(__FILE__) . "/../devices/CozytouchAtlanticHeatSystemWithAjustTemp.class.php";
}

if (!class_exists('CozytouchAtlanticHotWater')) {
	require_once dirname(__FILE__) . "/../devices/CozytouchAtlanticHotWater.class.php";
}

class CozyTouchManager
{
    private static $_client = null;

    public static function getClient($_force=false) 
    {
        if (self::$_client == null || $_force) 
        {
			
			self::$_client = new CozyTouchApiClient(array(
					'userId' => config::byKey('username', 'cozytouch'),
					'userPassword' => config::byKey('password', 'cozytouch')
			));
		}
		return self::$_client;
	}
	
	public static function syncWithCozyTouch() 
	{
		$client = self::getClient();
		$devices = $client->getSetup();
		log::add('cozytouch', 'debug', 'Recupération des données ok '); 

        foreach ($devices as $device) 
        {
			
			$deviceModel = $device->getVar(CozyTouchDeviceInfo::CTDI_CONTROLLABLENAME);
			switch ($deviceModel)
			{
				case CozyTouchDeviceToDisplay::CTDTD_ATLANTICELECTRICHEATERAJUSTTEMP:
					CozytouchAtlanticHeatSystemWithAjustTemp::BuildEqLogic($device);
					break;
				case CozyTouchDeviceToDisplay::CTDTD_ATLANTICHOTWATER:
					CozytouchAtlanticHotWater::BuildEqLogic($device);
					break;
                default:
                    AbstractCozytouchDevice::BuildDefaultEqLogic($device);
					break;
			}
		}
		
		$cron = cron::byClassAndFunction('cozytouch', 'cron15');
		if (!is_object($cron)) {

			log::add('cozytouch', 'info', 'cron non existant : creation en cours cron15');
			$cron = new cron();
			$cron->setClass('cozytouch');
			$cron->setFunction('cron15');
			$cron->setEnable(1);
			$cron->setDeamon(0);
			$cron->setSchedule('*/5 * * * * *');
			$cron->save();
		}
		
		CozyTouchManager::refresh_all();
	}
	
	public static function cron15() {
    	CozyTouchManager::refresh_all();
	}
	
    public static function refresh_all() 
	{
    	try {
    		
    		$clientApi = self::getClient();
    		$devices = $clientApi->getDevices();
			foreach ($devices as $device)
			{
				$device_url = $device->getURL();
				// state du device
				foreach ($device->getStates() as $state)
				{
					$cmd_array = Cmd::byLogicalId($device_url.'_'.$state->name);
					if(is_array($cmd_array) && $cmd_array!=null)
					{
						$cmd=$cmd_array[0];
						if($state->name==CozyTouchStateName::CTSN_ONOFF)
						{
							$value = ($state->value=='on');
						}
						else
						{
							$value = $state->value;
						}
						if (is_object($cmd) && $cmd->execCmd() !== $cmd->formatValue($value)) {
    						$cmd->setCollectDate('');
							$cmd->event($value);
						}
					}
				}
				
				
				// Liste des capteurs du device
				foreach ($device->getSensors() as $sensor)
				{
					$sensor_url=$sensor->getURL();
					// state du capteur
					foreach ($sensor->getStates() as $state)
					{
						$cmd_array = Cmd::byLogicalId($sensor_url.'_'.$state->name);
						if(is_array($cmd_array) && $cmd_array!=null)
						{
							$cmd=$cmd_array[0];
							if($state->name==CozyTouchStateName::CTSN_OCCUPANCY)
							{
								$value = ($state->value=='noPersonInside');
							}
							else
							{
								$value = $state->value;
							}
							if (is_object($cmd) && $cmd->execCmd() !== $cmd->formatValue($value)) {
    							$cmd->setCollectDate('');
								$cmd->event($value);
							}
							
						}
					}
				}
				log::add('cozytouch','debug','Refresh info : '.$device->getVar(CozyTouchDeviceInfo::CTDI_OID));
				$eqLogicTmp = eqLogic::byLogicalId($device->getVar(CozyTouchDeviceInfo::CTDI_OID), 'cozytouch');
				if (is_object($eqLogicTmp)) {
					$device_type = $eqLogicTmp->getConfiguration('device_model');
					switch($device_type){
						case CozyTouchDeviceToDisplay::CTDTD_ATLANTICELECTRICHEATERAJUSTTEMP:
							CozytouchAtlanticHeatSystemWithAjustTemp::refresh_thermostat($eqLogicTmp);
							break;
						case CozyTouchDeviceToDisplay::CTDTD_ATLANTICHOTWATER:
							CozytouchAtlanticHotWater::refresh_hotwatercoeff($eqLogicTmp);
							break;	
					}
				}
			}
        } 
		catch (Exception $e) 
		{
    
    	}
	}
	
	public static function execute($cmd,$_options)
	{
    	$eqLogic = $cmd->getEqLogic();
		$device_type = $eqLogic->getConfiguration('device_model');
		switch($device_type){
			case CozyTouchDeviceToDisplay::CTDTD_ATLANTICELECTRICHEATERAJUSTTEMP:
				CozytouchAtlanticHeatSystemWithAjustTemp::execute($cmd,$_options);
    			break;
    			
    		case CozyTouchDeviceToDisplay::CTDTD_ATLANTICHOTWATER :
				CozyTouchAtlanticHotWater::execute($cmd,$_options);
    			break;
    			
    	}
	}
}
?>