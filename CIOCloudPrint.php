<?php
/*****************************************************************
 *                                                                *
 * System:  Standalone                                            *
 * Object:  CIOCloudPrint.php                                     *
 * Author:  William Wynn / CIO Technologies                       *
 * Date: 10/04/11                                                 *
 *                                                                *
 * Object for talking to Google Cloud Print                       *
 *  - Acts as server for submitting jobs & client for receiving    *
 * Uses: CIOPrinting (optional - only needed for printing)        *
 *                                                                *
 * Sign Date     Change                                           *
 * XXXX XX/XX/XX XXXXXXXXXXXXXXXXXXXXXXXX                         *
 *****************************************************************/
class CIOCloudPrint {
	
	private $G_Email;
	private $G_Pass;
	
	private $client;
	private $Client_Login_Token;
	
	private $apiUri;
	
	private $log_obj;
	
	var $errorMessage;
	
	//*************************************************************************
	// Constructor - Loads Zend
	//*************************************************************************
	function __construct(&$log=null){
		// Include Zend
		if (strpos(ini_get('include_path'), DIRECTORY_SEPARATOR."gcp") === false){
			$locDir = dirname(__FILE__);
			ini_set('include_path', ini_get('include_path').PATH_SEPARATOR."$locDir");
		}
		
		if ($log) $this->log_obj = $log;
		$this->errorMessage = "";
		$this->apiUri = "https://www.google.com/cloudprint/";
		require_once 'Zend/Loader.php';
		Zend_Loader::loadClass('Zend_Http_Client');
		Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
	}
	
	//*************************************************************************
	// Authenticate and Login with given username and password
	//*************************************************************************
	function authenticate($email, $pass){
		if ($email && $pass){
			$this->G_Email = $email;
			$this->G_Pass = $pass;
		}
		// Login
		try{
			$this->client = Zend_Gdata_ClientLogin::getHttpClient($this->G_Email,
				$this->G_Pass, 'cloudprint');
		}catch (Exception $e){
			$this->errorMessage = "Failed to login: ".$e->getMessage();
			return false;
		}
		// Get the Token 
		if ($this->client)
			$this->Client_Login_Token = $this->client->getClientLoginToken();
		if ($this->Client_Login_Token)
			return true;
		else{
			$this->errorMessage = "Failed to get login token";
			return false;
		}
	}
	
	//*************************************************************************
	// Get Login Token
	//*************************************************************************
	private function login(){
		$this->errorMessage = "";
		if (isset($this->client)){
			$this->client->resetParameters();
			$this->Client_Login_Token = $this->client->getClientLoginToken();
		}
		else{
			if(!$this->authenticate())
				return false;
		}
		return true;
	}
	
	//*************************************************************************
	// Delete Our Login Token
	//*************************************************************************
	public function deauthenticate(){
		$this->Client_Login_Token = null;
		$this->client = null;
		$this->G_Email = "";
		$this->G_Pass = "";
		$this->errorMessage = "";
	}
	
	
	/* CLIENT FEATURES */
	
	//*************************************************************************
	// Get list of all printers
	//*************************************************************************
	function getPrinterList($searchOption = ""){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Search URI
		$this->client->setUri($this->apiUri.'search');
		if ($searchOption)
			$this->client->setParameterPost('q', $searchOption);
		$this->client->setParameterPost('xyz', $this->rand_string(5));
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		
		if ($success){
			// Printer Information
			$results = $data->printers;
			$printers = array();
			foreach($results as $printer){
				// ID
				$temp['id'] = $printer->id;
				// Name
				$temp['name'] = $printer->name;
				// Display Name
				$temp['displayName'] = $printer->displayName;
				// Description
				$temp['description'] = $printer->description;
				// Proxy
				$temp['proxy'] = $printer->proxy;
				// Status
				$temp['status'] = $printer->status;
				// Tags
				$temp['tags'] = $printer->tags;
				
				$printers[] = $temp;
			}
			return $printers;
		}
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Get all submitted jobs for printer with specified id, or all printers if no id.
	// Lists jobs of all status.
	//*************************************************************************
	function listJobs($limit=0, $id=null, $status=null){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'jobs');
		if ($status && !is_array($status)){
			$status = array($status);
		}
		if ($id){
			$this->client->setParameterPost('printerid', $id);
		}
		$this->client->setParameterPost('xyz', $this->rand_string(5));
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		//print_r($data);
		$success = $data->success;
		if ($success){
			$results = $data->jobs;
			$jobs = array();
			$count = 0;
			foreach ($results as $job){
				$count++;
				$tempJob = $this->jobObjectToArray($job);
				if (!$status || in_array($tempJob['status'], $status)){
					$jobs[] = $tempJob;
				}
				if ($count == $limit){
					break;
				}
			}
			return $jobs;
		}
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Get job with specific ID
	//*************************************************************************
	function getJob($id){
		$jobs = $this->listJobs();
		if ($jobs === false)
			return false;
		foreach ($jobs as $job){
			if ($job['id'] == $id)
				return $job;
		}
		$this->errorMessage = "Job not found. It may be completed or removed.";
		return false;
	}
	
	//*************************************************************************
	// Submit a file to specified printer
	// Job tag is any string the submitter wants and can be retreived when fetching the job.
	//*************************************************************************
	function submitJob($filePath, $printerId, $orientation='PORTRAIT', $sides='ONE_SIDED', $margin=0.5, $width=8.5, $height=11, $mimeType=null, $jobTag=null){
		$binary = file_get_contents($filePath);
		$orientation = strtoupper($orientation);
		$sides = strtoupper($sides);
		if (!$binary){
			$this->errorMessage = "No such file, or file empty";
			return false;
		}
		if ($orientation != 'PORTRAIT' && $orientation != 'LANDSCAPE'){
			$this->errorMessage = "Invalid Orientation. Must be PORTRAIT, LANDSCAPE, or NULL";
			return false;
		}
		if ($sides != 'ONE_SIDED' && $sides != 'DUPLEX' && $sides != 'TUMBLE'){
			$this->errorMessage = "Invalid Sides. Must be ONE_SIDED, DUPLEX, TUMBLE, or NULL";
			return false;
		}
		$fileName = basename($filePath);
		
		if ($mimeType)
			$type = trim($mimeType);
		else{
			switch(strtolower(substr($filePath, strrpos($filePath, '.', -3)+1))){
				case 'pdf': $type = "application/pdf"; break;
				case 'png': $type = "image/png"; break;
				case 'bmp': $type = "image/bmp"; break;
				//case 'epl': $type = "application/octet-stream"; break;
				case 'epl': $type = "text/plain"; break;
				//case 'pcl': $type = "application/vnd.hp-pcl"; break;
				case 'pcl': $type = "text/plain"; break;
				case 'jpeg':
				case 'jpg': $type = "image/jpeg"; break;
				default: $this->errorMessage = "Unsupported file. Please specify a MIME type. Use 'text/plain' for specialty files."; return false;
			}
		}
		// Create tag string for job
		$temp = array();
		if ($orientation)
			$temp[] = "o=".trim($orientation);
		if ($sides)
			$temp[] = "s=".trim($sides);
		if ($margin)
			$temp[] = "m=".trim($margin);
		if ($width)
			$temp[] = "w=".trim($width);
		if ($height)
			$temp[] = "h=".trim($height);
		$temp[] = "t=".$type;
		if ($jobTag)
			$temp[] = "j=".trim($jobTag);
		$tags = implode(',', $temp);
		
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Submit URI
		$this->client->setUri($this->apiUri.'submit');
		$this->client->setParameterPost('printerid', $printerId);
		$this->client->setParameterPost('capabilities', '');
		$this->client->setParameterPost('contentType', 'dataUrl');
		$this->client->setParameterPost('title', $fileName);
		$this->client->setParameterPost('tag', $tags);
		$this->client->setParameterPost('content', "data:$type;base64,".base64_encode($binary)); // base64_encode
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		if ($success)
			return true;
		else{
			$this->errorMessage = "Failed to send: ".$data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Delete job with given id
	//*************************************************************************
	function deleteJob($id){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'deletejob');
		$this->client->setParameterPost('jobid', $id);
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		if ($success)
			return true;
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	/* SERVER FEATURES */
	
	//*************************************************************************
	// Register a new printer with Google Cloud Print
	//*************************************************************************
	function registerPrinter($name, $uniqueID, $description, $PPD_File, $tag=null){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token);
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		try{
			$capabilities = file_get_contents($PPD_File);
		}catch(Exception $e){
			$this->errorMessage = "Couldn't open PPD File: ".$e->getMessage;
			return false;
		}
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'register');
		$this->client->setParameterPost('printer', $name);
		if ($capabilities){
			$this->client->setParameterPost('capabilities', $capabilities);
			$this->client->setParameterPost('defaults', $Capabilities);
		}
		$this->client->setParameterPost('proxy', $uniqueID);
		$this->client->setParameterPost('status', 'Online');
		$this->client->setParameterPost('description', $description);
		if ($tag)
			$this->client->setParameterPost('tag', $tag);
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		$Printer = $data->printers[0];
		$printerID = $Printer->id;
		if ($success && $printerID)
			return $printerID;
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Get all details for printer with specified id
	//*************************************************************************
	function getPrinter($id){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'printer');
		$this->client->setParameterPost('printerid', $id);
		$this->client->setParameterPost('xyz', $this->rand_string(5));
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		if ($success)
			return $data->printers[0];
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Remove printer with specified id
	//*************************************************************************
	function deletePrinter($id){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'delete');
		$this->client->setParameterPost('printerid', $id);
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		$success = $data->success;
		if ($success)
			return true;
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Update details for printer with specified id
	//*************************************************************************
	function updatePrinter($id, $name=null, $description=null, $tag=null, $status=null, $PPD_File=null){
		if(!$this->login())
			return false;
		try{
			if ($PPD_File)
				$capabilities = file_get_contents($PPD_File);
		}catch(Exception $e){
			$this->errorMessage = "Couldn't open PPD File: ".$e->getMessage;
			return false;
		}
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'update');
		$this->client->setParameterPost('printerid', $id);
		if ($name){
			$this->client->setParameterPost('display_name', $name);
		//	$this->client->setParameterPost('proxy', $name);
		}
		if ($description)
			$this->client->setParameterPost('description', $description);
		if ($tag)
			$this->client->setParameterPost('tag', $tag);
		if ($status)
			$this->client->setParameterPost('status', $status);
		if ($capabilities)
			$this->client->setParameterPost('capabilities', $capabilities);
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		//print_r($data); echo "<br>";
		$success = $data->success;
		if ($success)
			return true;
		else{
			$this->errorMessage = $data->message;
			return false;
		}
		
	}
	
	//*************************************************************************
	// Get printer name from printer id
	//*************************************************************************
	function getPrinterName($id){
		return $this->getPrinter($id)->name;
	}
	
	//*************************************************************************
	// Get printer tag from printer id
	//*************************************************************************
	function getPrinterDisplayName($id){
		return $this->getPrinter($id)->displayName;
	}
	
	
	//*************************************************************************
	// Get queued jobs for printer with specified id, or all printers if no id.
	//*************************************************************************
	function getQueuedJobsList($id){
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'fetch');
		if ($id)
			$this->client->setParameterPost('printerid', $id);
		$this->client->setParameterPost('xyz', $this->rand_string(5));
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		//print_r($data); echo "/fetch<br><br>"; // TODO REMOVE
		$success = $data->success;
		if ($success){
			$jobs = array();
			foreach($data->jobs as $job){
				$jobs[] = $this->jobObjectToArray($job);
			}
			return $jobs;
		}
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Print all files queued for this printer with defaults or given settings
	//*************************************************************************
	function printAllFiles($id=null, $printer=null, $type=null, $orientation='PORTRAIT', $sides='ONE_SIDED', $margin=0.5, $width=8.5, $height=11, $useDisplayName=false, $errors=''){
		$printerArray = array();
		if (!$id){
			$printer=null; $type=null; $orientation='PORTRAIT';  $sides='ONE_SIDED';  $margin=0.5; $width=8.5; $height=11;
			$printerList = $this->getPrinterList();
			foreach ($printerList as $somePrinter)
				$printerArray[] = $somePrinter['id'];
		}
		else
			$printerArray[] = $id;
		foreach ($printerArray as $id){
			$jobs = $this->getQueuedJobsList($id);
			if (!$jobs){
				return false;
			}
			foreach($jobs as $job){
				$result = $this->printGivenJob($job, $printer, $type, $orientation, $sides, $margin, $width, $height, $useDisplayName);
				if (!$result && strpos(strtolower($this->errorMessage), 'no print job') === false){
					echo $this->errorMessage.$errors."\n";
				}
			}
		}
		if (strtolower(strpos($this->errorMessage, 'no print job')) !== false )
			return true;
		else{
			$this->errorMessage = 'Not all files printed: '.$this->errorMessage;
			return false;
		}
	}
	
	//*************************************************************************
	// Print the next queued file for the specified printer
	//*************************************************************************
	function printNextFile($id, $printer=null, $type=null, $orientation='PORTRAIT', $sides='ONE_SIDED', $margin=0.5, $width=8.5, $height=11, $useDisplayName=false){
		$jobs = $this->getQueuedJobsList($id);
		if ($jobs){
			$job = $jobs[0];
			$this->printGivenJob($job, $printer, $type, $orientation, $sides, $margin, $width, $height, $useDisplayName);
		}
		else{
			$this->errorMessage = "Couldn't get file: ".$this->errorMessage;
			return false;
		}
	}
	
	//*************************************************************************
	// Print job for the given job data array. Get job array from getQueuedJobsList
	// Pass results from getQueuedJobsList here one by one. Not all at once.
	//*************************************************************************
	private function printGivenJob($job, $printer=null, $type=null, $orientation='PORTRAIT', $sides='ONE_SIDED', $margin=0.5, $width=8.5, $height=11, $useDisplayName=false){
		// Include CIO Printing Object
		$dir = dirname(__FILE__).DIRECTORY_SEPARATOR.'CIOPrinting'.DIRECTORY_SEPARATOR.'CIOPrintFile.php';
		if (!file_exists($dir)){
			$this->errorMessage = "Cannot Print! Missing Print Object $dir";
			return false;
		}			
		require_once($dir);
		
		$this->errorMessage = '';
		$fileUrl = $job['fileUrl'];
		$jobID = $job['id'];
		$id = $job['printerid']; // printer ID
		if ($job['orientation']) $orientation = $job['orientation'];
		if ($job['sides']) $sides = $job['sides'];
		if ($job['margin']) $margin = $job['margin'];
		if ($job['width']) $width = $job['width'];
		if ($job['height']) $height = $job['height'];
		if (!$type && !$job['acceptType'])
			$type = 'application/pdf';
		else if ($job['acceptType'])
			$type = $job['acceptType'];
		if (!$orientation) $orientation='PORTRAIT';
		if (!$sides) $sides='ONE_SIDED';
		if (!$margin) $margin=0.5;
		if (!$width) $width=8.5;
		if (!$height) $height=11;
		if (!$printer)
			$printer = ($useDisplayName && substr(trim($this->getPrinterName($id)), 0, 4) == 'CIO_') ? trim($this->getPrinterDisplayName($id)) : trim($this->getPrinterName($id));
		// Temp Filename and location
		$tempFile = '';
		if (!file_exists(dirname(__FILE__).DIRECTORY_SEPARATOR.$job['title'])){
			$tempFile = $job['title'];
		}
		else{
			$end = substr($job['title'], strrpos($job['title'], '.'));
			$name = substr($job['title'], 0, strrpos($job['title'], '.'));
			if (strrpos($job['title'], '.') === FALSE){
				$end = ".pdf";
			}
			do{
				$tempFile = $name.'-'.$this->rand_string(3).$end;
			} while (file_exists(dirname(__FILE__).DIRECTORY_SEPARATOR.$tempFile));
		}
		$toPrint = dirname(__FILE__).DIRECTORY_SEPARATOR.$tempFile;
		$this->client->resetParameters();
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		$this->client->setHeaders('Accept', $type);
		//echo $type."\n";
		$this->client->setHeaders('Accept-encoding', 'gzip,deflate');
		$this->client->setUri($fileUrl);
		$response = $this->client->request(Zend_Http_Client::POST);
		// Save file
		$fp = fopen($toPrint, 'w');
		fwrite($fp, $response->getBody());
		fclose($fp);
		if ($response->isSuccessful()){
			// Send to printer
			$this->setJobStatus($jobID, 'IN_PROGRESS'); // Mark IN_PROGRESS
			if($this->log_obj) $this->log_obj->write_log("Printing <$toPrint> to <$printer> with Orientation <$orientation> and Sides <$sides>");
			$result = CIOPrintFile::printFile($toPrint, $printer, $orientation, $sides, $margin, $width, $height);
			if ($result === false){
				unlink($toPrint);
				if (substr(CIOPrintFile::$errorMessage, 0, 14) == "Error: Printer")
					$this->setJobStatus($jobID, 'QUEUED'); // Mark QUEUED
				else
					$this->setJobStatus($jobID, 'ERROR', 'PNF-500', CIOPrintFile::$errorMessage); // Mark ERROR
				$this->errorMessage = "Couldn't print file: ".CIOPrintFile::$errorMessage;
				return false;
			}
			else{
				if ($result === true || strpos($result[1], $tempFile) === false){
					$this->setJobStatus($jobID, 'DONE'); // Mark DONE
				}
				else{
					$this->errorMessage = "Failed to print: ".$result[1];
					$this->setJobStatus($jobID, 'ERROR', 'PNF-501', $result[1]); // Mark ERROR
					return false;
				}
			}
		}	
		else{
			unlink($toPrint);
			$this->errorMessage = "Found job, but could not download. Check file type. Status: ".$response->getStatus()." - ".$response->getMessage();
			return false;
		}
		unlink($toPrint);
		return true;
	}
	
	//*************************************************************************
	// Set the printing status of the specified job
	//		Status of the job can be one of the following:
	//		QUEUED: Job just added and has not yet been downloaded.
	//		IN_PROGRESS: Job downloaded and has been added to the client-side native printer queue.
	//		DONE: Job printed successfully.
	//		ERROR: Job cannot be printed due to an error.
	//*************************************************************************
	function setJobStatus($id, $status, $code='', $message=''){
		$status = strtoupper($status);
		$acceptable = array('QUEUED', 'IN_PROGRESS', 'DONE', 'ERROR');
		if (!in_array($status, $acceptable)){
			$this->errorMessage = "Invalid job status.";
			return false;
		}
		if(!$this->login())
			return false;
		$this->client->setHeaders('Authorization','GoogleLogin auth='.$this->Client_Login_Token); 
		$this->client->setHeaders('X-CloudPrint-Proxy','CIO-Technologies');
		//GCP Services - Printer URI
		$this->client->setUri($this->apiUri.'control');
		$this->client->setParameterPost('jobid', $id);
		$this->client->setParameterPost('status', $status);
		if ($status == 'ERROR' && $code && $status){
			$this->client->setParameterPost('code', $code);
			$this->client->setParameterPost('message', $message);
		}
		$this->client->setParameterPost('xyz', $this->rand_string(5));
		$response = $this->client->request(Zend_Http_Client::POST);
		$data = json_decode($response->getBody());
		//print_r($data); echo "/control<br><br>";
		$success = $data->success;
		if ($success)
			return true;
		else{
			$this->errorMessage = $data->message;
			return false;
		}
	}
	
	//*************************************************************************
	// Convert a job object into a more friendly array
	//*************************************************************************
	private function jobObjectToArray($jobObject){
		// ID
		$jobArray['id'] = (string)$jobObject->id;
		// Printer ID
		$jobArray['printerid'] = $jobObject->printerid;
		// Title
		$jobArray['title'] = $jobObject->title;
		// Content Type
		$jobArray['contentType'] = $jobObject->contentType;
		// File URL
		$jobArray['fileUrl'] = $jobObject->fileUrl;
		// Ticket Url
		$jobArray['ticketUrl'] = $jobObject->ticketUrl;
		// Created Time
		$jobArray['createTime'] = (string)$jobObject->createTime;
		// Update Time
		$jobArray['updateTime'] = (string)$jobObject->updateTime;
		// Status
		$jobArray['status'] = $jobObject->status;
		// Error Code
		$jobArray['errorCode'] = $jobObject->errorCode;
		// Message
		$jobArray['message'] = $jobObject->message;
		// Num Pages
		$jobArray['numberOfPages'] = (string)$jobObject->numberOfPages;
		// Tags
		$jobArray['orientation'] = 'PORTRAIT';
		$jobArray['sides'] = 'ONE_SIDED';
		$jobArray['margin'] = 0;
		$jobArray['width'] = null;
		$jobArray['height'] = null;
		$jobArray['acceptType'] = null;
		$jobArray['tag'] = null;
		$tag = $jobObject->tags[1];
		$tags = explode(',', $tag);
		foreach ($tags as $tag){
			$tag = trim($tag);
			switch($tag{0}){
				case 'o': $jobArray['orientation'] = substr($tag, 2); break;
				case 's': $jobArray['sides'] = substr($tag, 2); break;
				case 'm': $jobArray['margin'] = substr($tag, 2); break;
				case 'w': $jobArray['width'] = substr($tag, 2); break;
				case 'h': $jobArray['height'] = substr($tag, 2); break;
				case 't': $jobArray['acceptType'] = substr($tag, 2); break;
				case 'j': $jobArray['tag'] = substr($tag, 2); break;
			}
		}
		$jobArray['gTag'] = $jobObject->tags[0];
		
		return $jobArray;
	}
	
	//*************************************************************************
	// Generate random alpha-numeric string of specified length
	//*************************************************************************
	private static function rand_string($length) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$string = '';
		mt_srand(self::make_seed());
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}
		return $string;
	}
	//*************************************************************************
	// Generate a seed based off the time
	//*************************************************************************
	private static function make_seed() {
		list($usec, $sec) = explode(' ', microtime());
		return (float) $sec + ((float) $usec * 100000);
	}
}
?>