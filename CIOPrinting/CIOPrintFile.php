<?php
/*****************************************************************
 *                                                                *
 * System:  Standalone                                            *
 * Object:  CIOPrintFile.php                                      *
 * Author:  William Wynn / CIO Technologies                       *
 * Date: 10/05/11                                                 *
 *                                                                *
 * Object for printing using in-house java object                 *
 * Designed to be platform independent                            *
 *                                                                *
 * Sign Date     Change                                           *
 * XXXX XX/XX/XX XXXXXXXXXXXXXXXXXXXXXXXX                         *
 *****************************************************************/
class CIOPrintFile {
	public static $errorMessage = "";

	//*************************************************************************
	// Send a file or array of files to a local printer
	// Returns:	TRUE if everything was successful
	//			FALSE if it couldn't connect to the printer
	//			ARRAY() of output lines if there were printing errors
	//*************************************************************************
	public static function printFile($files, $printer, $orientation="PORTRAIT", $sides="ONE_SIDED", $margin=0.25, $width=8.5, $height=11, $copies=1){
		if ($orientation != 'PORTRAIT' && $orientation != 'LANDSCAPE'){
			self::$errorMessage = "Invalid Orientation. Must be PORTRAIT, LANDSCAPE, or NULL";
			return false;
		}
		if ($sides != 'ONE_SIDED' && $sides != 'DUPLEX' && $sides != 'TUMBLE'){
			self::$errorMessage = "Invalid Sides. Must be ONE_SIDED, DUPLEX, TUMBLE, or NULL";
			return false;
		}
		self::$errorMessage = "";
		list($code, $message) = self::checkPrinter($printer);
		if (!$message){
/*			if (!is_array($files) && strtolower(substr($files, -3)) == "pdf"){
				$printFile = substr($files, 0, -4).'.bat';
				$adobe_path = "\"C:\\Program Files\\ciophp\\Foxit Reader.exe\"";
				$command = $adobe_path.' -NoRegister -t "'.$files.'" "'.$printer.'"';
				$result = `$command`;
				echo $result;
				return true;
			}
			else{
*/				// Build run command
				if (!$margin || $margin==0) $margin='0';
				$command = "java -jar PrintFile.jar \"-p$printer\" -o$orientation -s$sides -m$margin -w$width -h$height -c$copies";
				if (is_array($files)){
					foreach ($files as $file)
						$command .= " \"-f$file\"";
				}
				else
					$command .= " \"-f$files\"";
				//echo $command."\n";
				// Run command
				if (stristr(PHP_OS, 'WIN')){ // Windows
					try{
						$result = "";
						$descriptorspec = array(
							0 => array("pipe", "r"),  // stdin is a pipe
							1 => array("pipe", "w"),  // stdout is a pipe
							2 => array("file", "error-output.txt", "a") // stderr is a file
						);
						$process = proc_open($command, $descriptorspec, $pipes, dirname(__FILE__), null);
						if (is_resource($process)) {
							$result = stream_get_contents($pipes[1]);
							fclose($pipes[1]);
							$return_value = proc_close($process);
							//echo "command returned: $return_value";
							$resultArray = preg_split("/((\r\n)|(\n))/", trim($result));
							if ($resultArray[1] == "DONE")
								return true;
							else if (substr($resultArray[1], 0, 5) == "Error"){
								self::$errorMessage = $resultArray[1];
								return false;
							}
							else
								return $resultArray;
						}
						else{
							self::$errorMessage = "Failed to contact printer driver with: ".$command;
							return false;
						}
					} catch(Exception $e){
						self::$errorMessage = $e->getmessage();
						return false;
					}
				}
				else{ // Unix (TODO - Same as windows?)
					exec($command . " > /dev/null &");
				}
				return true;
			}
//		}
		self::$errorMessage = $message;
		return false;
	}
	
	//*************************************************************************
	// Check if printer is ready
	//*************************************************************************
	private static function checkPrinter($printerName){
		$isUnix = true;
		if (stristr(PHP_OS, 'WIN'))
			$isUnix = false;
			
		$error_message = "";
		$code = 0;
			
		if ($isUnix){ // Unix Check
			
		}
		else{ // Windows Check
			try{
				$output = shell_exec('Cscript.exe .\\Windows\\printer_status.vbs');
			}catch (Exception $e){}
			$result = explode("\n", $output);
			if (count($result) > 4){
				for ($i=3; $i<count($result)-1; $i++){
					$temp = explode("x::x", $result[$i]);
					if (trim($temp[0]) == trim($printerName)){
						switch ($temp[1]){
							case 4:
								$error_message = "Printer is Out of Paper";
								break;
							case 6:
								$error_message = "Printer is Out of Toner";
								break;
							case 7:
								$error_message = "Printer Door is Open";
								break;
							case 8:
								$error_message = "Printer is Jammed";
								break;
							case 10:
								$error_message = "Printer Output Bin is Full";
								break;
							case 11:
								$error_message = "Printer Has a Paper Problem";
								break;
							case 12:
								$error_message = "Printer Cannot Print Page";
								break;
							case 13:
								$error_message = "Printer Needs User Intervention";
								break;
							case 14:
								$error_message = "Printer is Out of Memory";
								break;
							case 15:
								$error_message = "Printer Server Cannot be Found";
								break;
						}
						$code = $temp[1];
					}
				}
			}
		}
		return array($code, $error_message);
	}
	
	//*************************************************************************
	// Generate random alpha-numeric string of specified length
	//*************************************************************************
	public static function rand_string($length) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$string = '';
		mt_srand(self::make_seed());
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters))];
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