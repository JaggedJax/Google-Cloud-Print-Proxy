#!/usr/local/bin/php
<?php
/*****************************************************************
 *                                                                *
 * System:  Standalone                                            *
 * Object:  CIOCloudPrintXMPPService.php                          *
 * Author:  William Wynn / CIO Technologies                       *
 * Date: 10/05/11                                                 *
 *                                                                *
 * Script for receiving cloud print job notifications             *
 *                                                                *
 * Sign Date     Change                                           *
 * XXXX XX/XX/XX XXXXXXXXXXXXXXXXXXXXXXXX                         *
 *****************************************************************/
require_once 'XMPPHP/XMPP.php';
require_once 'Zend/Loader.php';
require_once 'CIOCloudPrint.php';
require_once '../CIOLog.php';

Zend_Loader::loadClass('Zend_Http_Client');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');

$verbose = false;
$maxForks = 10;
$numForks = 0;
$useDesc = true; // True means printer name stored in tag field of cloud printer
$errors = "";
$log_obj = new CIOLog("./log/","CloudPrint","monthly");

if (count($argv) > 2){
	$G_Email = trim($argv[1]);
	$G_Pass = base64_decode(trim($argv[2]));
	if (!strpos($G_Email, '@'))
		$G_Email .= "@gmail.com";
		
	for($i=3; $i<count($argv); $i++){
		switch(substr($argv[$i], 0, 2)){
			case '-v': $verbose = true; echo "Verbose\n"; break; // verbose
			case '-n': $numForks = substr($argv[$i], 2); break; // Number of Forks
		}
	}
}
else{
	$log_obj->write_log("Must pass login parameters");
	die("Must pass login parameters");
}
$cp = new CIOCloudPrint($log_obj);
if (!$cp->authenticate($G_Email, $G_Pass))
	die($cp->errorMessage);

$maxReconnects = 10;
$numReconnects = 0;

//Print any queued files
// Fork so we don't miss any new incoming jobs
//$log_obj->write_log("Checking for waiting print files.");
if ($verbose){
	echo "Checking for waiting print files.\n";
	$errors = "\n";	
}
if (stristr(PHP_OS, 'WIN')){
	if (!$cp->printAllFiles(null, null, null, null, null, null, null, null, $useDesc, $errors)){
		$log_obj->write_log("Printing Error: "+$cp->errorMessage);
		if ($verbose) echo $cp->errorMessage;
	}
}
else{
	$pid = pcntl_fork();
	if ($pid == 0){
		if (!$cp->printAllFiles(null, null, null, null, null, null, null, null, $useDesc, $errors)){
			$log_obj->write_log("Printing Error: "+$cp->errorMessage);
			if ($verbose) echo $cp->errorMessage;
		}
		exit(0);
	}
}

// Check that another instance isn't already running
$tempFile = "xmpprunning.txt";
$fh = fopen($tempFile, 'w+');
$gotLock = flock($fh, LOCK_EX|LOCK_NB);
if (!$fh || !$gotLock){
	//if ($verbose)
		echo "Couldn't get lock. Quitting.";
	fclose($fh);
	exit(1);
}
$log_obj->write_log("Got lock, connecting to Google.");
//else if ($verbose)
	echo "Got lock, connecting to Google.\n";

// Begin XMPP
$conn = new XMPPHP_XMPP('talk.google.com', 5222, $G_Email, $G_Pass, 'CIO-Techno', 'gmail.com', $printlog=true, $loglevel=XMPPHP_Log::LEVEL_ERROR);
$conn->autoSubscribe();

$vcard_request = array();
//var_dump($conn);
do{
	try {
		$conn->connect(30, true); 
		while(!$conn->isDisconnected()) 
		{
			if ($verbose)
				echo "Listening for Notifications\n";
			$payloads = $conn->processUntil(array('message', 'session_start'));
			foreach($payloads as $event) 
			{
				$pl = $event[1];
				switch($event[0]) 
				{
					case 'message': 
						$Full_JID =  $conn->fulljid;
						$Bare_JID = $conn->jid;
						$temp = $pl['xml']->sub(0);
						$printerID = base64_decode($temp->subs[1]->data);
						//echo  "Message from: " . $pl['from'] . "<br />";   		
						if($pl['from'] == 'cloudprint.google.com')
						{
							// We have received a push for this Print Proxy ID + User.   
							// Now we can /fetch print jobs for this Proxy, User, Printer ID
							$log_obj->write_log("Print Job Notification Received for printer " . $printerID);
							if ($verbose)
								echo "Print Job Notification Received for printer " . $printerID . "\n";
							$result = $cp->printAllFiles($printerID, null, null, null, null, null, null, null, $useDesc, $errors);
							if (!$result){
								$log_obj->write_log("Error printing: ".$cp->errorMessage);
								if ($verbose)
									echo "Error printing: ".$cp->errorMessage."\n";
							}
						}   				
					break;
					case 'session_start': 			
						$Full_JID =  $conn->fulljid;
						$Bare_JID = $conn->jid;
						$Body = "<iq type='set' to='" . $Bare_JID . "' id='3'><subscribe xmlns='google:push'><item channel='cloudprint.google.com' from='cloudprint.google.com'/></subscribe></iq>";				
						$conn->send($Body);
					break;
				}
			}
		}
	} catch(Exception $e) {
		if ($numForks < $maxForks){
			$numForks++;
			$log_obj->write_log("Forking because of error: ".$e->getMessage());
			if ($verbose)
				echo "Forking because of error: ".$e->getMessage()."\n";
			if (stristr(PHP_OS, 'WIN')){ // Windows
				// Ugly fork since windows doesn't have any good backgrounding or php forking
				$command = "php CIOCloudPrintXMPPService.php $G_Email ".base64_encode($G_Pass);
				if ($verbose)
					$command .= " -v";
				$command .= " -n$numForks";
				$batFile = "runXMPP.bat";
				try{
					$FileHandle = fopen($batFile, 'w');
					fwrite($FileHandle, $command);
					fclose($FileHandle);
				}catch(Exception $e){
					$batFile = "runXMPP2.bat";
					$FileHandle = fopen($batFile, 'w');
					fwrite($FileHandle, $command);
					fclose($FileHandle);
				}
				// Launch script
				try{
					$WshShell = new COM("WScript.Shell");
					$oExec = $WshShell->Run("cmd /c \"".dirname(__FILE__)."\\".$batFile."\"", 0, false);
				}catch (Exception $e){
					pclose(popen("start \"Cloud Print XMPP Service\" \"".dirname(__FILE__)."/".$batFile."\" " . escapeshellarg($args), "r"));
				}
				exit(1);
			}
			else{ // Unix
				$pid = pcntl_fork();
				if ($pid == -1){ // Couldn't fork
					flock($fh, LOCK_UN);
					fclose($fh);
					unset($tempFile);
					die('Could not fork off original error: '.$e->getMessage());
				}
				else if ($pid){ // End Parent Process
					flock($fh, LOCK_UN);
					fclose($fh);
					unset($tempFile);
					die($e->getMessage());
				}
				// Continue Child Process
				$fh = fopen($tempFile, 'w');
				$gotLock = flock($fh, LOCK_EX); // Blocking lock to wait for parent.
				if (!$fh || !$gotLock){
					exit(1);
				}
			}
		}
		else{
			flock($fh, LOCK_UN);
			fclose($fh);
			unset($tempFile);
			die('Too many fork attempts after error: '.$e->getMessage());
		}
	}
	if ($numReconnects < $maxReconnects){
		$cp = null; $conn = null;
		$cp = new CIOCloudPrint();
		if (!$cp->authenticate($G_Email, $G_Pass))
			die($cp->errorMessage);
		$conn = new XMPPHP_XMPP('talk.google.com', 5222, $G_Email."@gmail.com", $G_Pass, 'CIO-Techno', 'gmail.com', $printlog=true, $loglevel=XMPPHP_Log::LEVEL_ERROR);
		$conn->autoSubscribe();
		$numReconnects++;
	}
} while($numReconnects <= $maxReconnects);
flock($fh, LOCK_UN);
fclose($fh);
unset($tempFile);