<?php
   /*****************************************************************
   *                                                                *
   * System:  Ship4u                                                *
   * Object:  CIOLog.php                                             *
   * Author:  Hans Backman / CIO Technologies        Date: 07/20/06 *
   *                                                                *
   * Object for creating and writing to log file.                   *
   *                                                                *
   * Uses: n/a                                                      *            
   *                                                                *
   * Sign Date     Change                                           *
   * xxxx xx/xx/xx xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx *
   *                                                                *
   *****************************************************************/

class CIOLog
{
	private $handle;

	//*************************************************************************
    // Open log file. Create if none exist.
    //*************************************************************************
	function __construct($inpath,$type,$duration)  
	{
	
		// Create path to log file
		if ($inpath == "")
		{  
			$ciopath = explode("\\", dirname(__FILE__)); 
			$logpath = $ciopath[0] . '/' . $ciopath[1] . '/Log/';
		}
		else
			$logpath = $inpath;
		if (!is_dir($logpath))
			mkdir($logpath);
		// Open file depending on rollover duration    
		if ($duration == 'daily')
			$this->handle = fopen($logpath.$type.date('Y')."-".date('m').
				"-".date('d').".log", "at");
		if ($duration == 'monthly')
			$this->handle = fopen($logpath.$type.date('Y')."-".date('m').
				".log", "at");  
		if ($duration == 'yearly')
			$this->handle = fopen($logpath.$type.date('Y').
				".log", "at"); 
	}

    //*************************************************************************
    // Write record to log file.
    //*************************************************************************
	public function write_log($text_in)
	{
	
		$text=date('Y')."-".date('m')."-".date('d')." ".
		   	strftime("%H").":".strftime("%M").":".strftime("%S").
		   	"  --  ".$text_in."\n";
		flock($this->handle, LOCK_EX);
		fwrite($this->handle, $text); 
		flock($this->handle, LOCK_UN);
	
    }

    //*************************************************************************
    // close log file.
    //*************************************************************************
   function __destruct() 
	{
	
    	fclose($this->handle);
	
    }
}

?>