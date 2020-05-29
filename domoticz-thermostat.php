<?php

class DomoticzThermostat
{

	public $domoticz_url; //URL of your domoticz ex.: http://192.168.1.100:8080
	public $mainheater_idx; //Main heater switch IDX
	public $out_temp_idx; // IDX of outside thermometer
	public $out_turnoff_temp; // Max Temp when thermostat works
	public $histeresis; //Histeresis

	public $zones = [];
	public $mainheater_time=0; 	
	public $out_temp=20;
	public $default_temp=20;
	public $status_mainheater=0;
	public $temps=[];


	public function __construct($domoticz_url, $mainheater_idx,$out_temp_idx,$out_turnoff_temp,$histeresis) {
		$this->domoticz_url=$domoticz_url; 
		$this->mainheater_idx=$mainheater_idx; 
		$this->out_temp_idx=$out_temp_idx;
		$this->out_turnoff_temp=$out_turnoff_temp; 
		$this->histeresis=$histeresis;
		echo "DomoticzThermostat 1.0\n";
		echo "szymoki - szymoki@icloud.com\n"; 
	}

	function updateDomoticz(){
		echo "Updating domoticz data...\n";
		foreach($this->zones as $name=>$zone){
			$suma=0;//suma sredniej
			foreach($zone["temp_idx"] as $idx){
				$temp=$this->getDomoticzTemp($idx);
				if(!$temp) $temp=$this->default_temp;
				$this->temps[$idx]=$temp;//dodawanie do wspolnej tablicy
				$suma+=$temp;//dodawanie do sredniej
			}
			if(count($zone["temp_idx"])!=0){ 
				$this->zones[$name]["temp"]=$suma/count($zone["temp_idx"]);
				echo $name." zone temp ".$suma/count($zone["temp_idx"])."\n";
			}
			$setpoint=$this->getDomoticzSetpoint($this->zones[$name]["setpoint_idx"]);//ustawianie setpointu
			if($setpoint) $this->zones[$name]["setpoint"]=$setpoint;
			echo $name." zone setpoint - ".$setpoint."\n";
			$dev=$this->getDomoticzSwitch($this->zones[$name]["dev_idx"]);
			$this->zones[$name]["dev_status"]=$dev[0];
			$this->zones[$name]["dev_time"]=$dev[1];
			$onoff=$this->getDomoticzSwitch($this->zones[$name]["onoff_idx"]);
			$this->zones[$name]["onoff"]=$onoff[0];
		}
		$mainheater=$this->getDomoticzSwitch($this->mainheater_idx);
		$this->status_mainheater=$mainheater[0];
		$this->mainheater_time=$mainheater[1];
		$this->out_temp=$this->getDomoticzTemp($this->out_temp_idx);	
		$status=$this->getDomoticzSwitch($this->mainheater_idx);
		echo "Done.\n";
	}


	function getDomoticzTemp($idx){
		$data = json_decode($this->curl($this->domoticz_url."/json.htm?type=devices&rid=".$idx));
		if(!isset($data->result[0]->Temp)) return false;
		return $data->result[0]->Temp;
	}
	function getDomoticzSetpoint($idx){
		$data = json_decode($this->curl($this->domoticz_url."/json.htm?type=devices&rid=".$idx));
		if(!isset($data->result[0]->SetPoint)) return false;
		return $data->result[0]->SetPoint;
	}

	function getDomoticzSwitch($idx){
		$data = json_decode($this->curl($this->domoticz_url."/json.htm?type=devices&rid=".$idx));
		if(!isset($data->result[0]->Status)) return [0,0];
		$time=time()- strtotime($data->result[0]->LastUpdate);
		return [$data->result[0]->Status=="On" ? 1 : 0,$time];
	}
	function curl($url){
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	function setSwitch($idx,$status){
		$status = $status==1 ? "On":"Off";
		echo "[domoticz] Setting idx ".$idx." to ".$status."\n";
		$data = json_decode($this->curl($this->domoticz_url."/json.htm?type=command&param=switchlight&idx=".$idx."&switchcmd=".$status));
		if($data->status=="OK") return $status; else return false;
	}


	function validate_zone($zone_name){
		$zone=$this->zones[$zone_name];
		if($zone["onoff"]==1 and $this->out_temp<$this->out_turnoff_temp){//czy ogrzewanie w strefie wlaczone i temperatura na zewnatrz 
			$temp=$zone["temp"];
			$temp_start = $zone["setpoint"]-$this->histeresis;
			$temp_stop = $zone["setpoint"]+$this->histeresis;
			if($temp<$temp_start){//wlacz ogrzewanie
				if($zone["dev_status"]==0){
					echo "Heating turn ON ".$zone_name."\n";
					$this->zones[$zone_name]["new_dev_status"]=1;
				}else{
					echo "Still heating - ".$zone_name."\n";
					$this->zones[$zone_name]["new_dev_status"]=$zone["dev_status"];
				}
				
			}elseif($temp>$temp_stop){//wylacz ogrzewanie

				if($zone["dev_status"]==1){ 
					echo "Heating turn OFF ".$zone_name."\n";
					$this->zones[$zone_name]["new_status"]=0;
				}
				else{
					echo "No action needed - ".$zone_name."\n";
					$this->zones[$zone_name]["new_dev_status"]=$zone["dev_status"];
				}
			}else{
				echo "No action needed - " .$zone_name."\n";
				$this->zones[$zone_name]["new_dev_status"]=$zone["dev_status"];
			}
		}else{
			echo $zone_name." - zone off\n";
			$this->zones[$zone_name]["new_dev_status"]=0;
		}		
		if($this->zones[$zone_name]["new_dev_status"]!=$this->zones[$zone_name]["dev_status"]){
			$this->setSwitch($zone["dev_idx"],$this->zones[$zone_name]["new_dev_status"]);
		}
	}


	function validate_zones(){
		echo "Checking zones..\n";
		foreach($this->zones as $name=>$zone){
			$this->validate_zone($name);
		}
		echo "Done.\n";
	}


	function validate_mainheater(){
		$new_status=0;
		foreach($this->zones as $zone){
			if($zone["new_dev_status"]==1){
				$new_status=1;
			}
		}
		if($new_status !=$this->status_mainheater){
			$this->setSwitch($this->mainheater_idx,$new_status);
			echo "New mainheater status - ".$new_status."\n";
		}else{
			echo "No mainheater change status\n";
		}

	}


// zone_name - name of the zone
// temp_idx - array of temp sensors IDX
// setpoint_idx - zone setpoint IDX
// dev_idx - IDX of switch to start heating in zone
// onoff_id - IDX of switch which turn off heating control
	function addZone($zone_name,$temp_idx,$setpoint_idx,$dev_idx,$onoff_idx){
		return	$this->zones[$zone_name]=[
			"temp_idx"=>$temp_idx,
			"setpoint"=>20,
			"setpoint_idx"=>$setpoint_idx,
			"dev_idx"=>$dev_idx,
			"dev_status"=>0,
			"dev_time"=>0,
			"temp"=>20,
			"onoff_idx"=>$onoff_idx,
			"onoff"=>0,
			"new_dev_status"=>0];
		}

	}


	$DomoticzThermostat = new DomoticzThermostat("http://192.168.88.10:8080",116,49,13,0.2);
//	$DomoticzThermostat->addZone("pokoje",[0],122,158,160);
	$DomoticzThermostat->addZone("korytarze",[129,132,154],162,159,160);
	$DomoticzThermostat->updateDomoticz();
	$DomoticzThermostat->validate_zones();
	$DomoticzThermostat->validate_mainheater();


	?>



