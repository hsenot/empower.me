<?php
/**
 * Returns the entire dataset for a given client
 */

# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# For communication of updates to the calling script
require_once 'AJAX_PROGRESS.class.php';
$pb=new AJAX_PROGRESS();

# Performs the query and returns XML or JSON
try {
	$p_url = $_REQUEST['url'];

	// This script orchestrate the processing of a smart meter data file
    // 1) file detection
    //  a. Type (PDF/txt)
    $pb->advance(0.15,'Detecting file type...');

	$file_in_a_string = file_get_contents($p_url);
	$file_info = new finfo(FILEINFO_MIME);  // object oriented approach!
	$mime_type = $file_info->buffer($file_in_a_string);  // e.g. gives "image/jpeg"
	# Note: we should probably use stricter URL detection to only consider files we trust (i.e. from our server or other trusted location)

	// Writing the file to staging area. Do we loose anything in this process?
	$staged_file_path = realpath('../staging').'/'.basename($p_url);
	file_put_contents($staged_file_path , $file_in_a_string);

	# First part of mime-type is interesting, second part is character set
	$mt = explode(";",$mime_type)[0];

	// Purpose of the switch is to determine the method for loading the data in the DB
	$method = "UNKNOWN";
    //  b. Format (heuristics to recognise distributor or retailer)
    switch($mt) {
	   case "application/pdf":
	        //echo "PDF detected - processing as Origin file ..";
	        $method = "ORIGIN1";
	        break;
	    case "text/plain":
	        //echo "Plain text - needs further analysis to determine source ..";
	        // Sniffing the first line of the file
			$first_line = strtok($file_in_a_string, "\r\n");

			// Library of smart meter data file headers
	        $line1_origin2 = 'NMI,METER SERIAL NUMBER,CON/GEN,DATE,ESTIMATED?,00:00 - 00:30,00:30 - 01:00,01:00 - 01:30,01:30 - 02:00,02:00 - 02:30,02:30 - 03:00,03:00 - 03:30,03:30 - 04:00,04:00 - 04:30,04:30 - 05:00,05:00 - 05:30,05:30 - 06:00,06:00 - 06:30,06:30 - 07:00,07:00 - 07:30,07:30 - 08:00,08:00 - 08:30,08:30 - 09:00,09:00 - 09:30,09:30 - 10:00,10:00 - 10:30,10:30 - 11:00,11:00 - 11:30,11:30 - 12:00,12:00 - 12:30,12:30 - 13:00,13:00 - 13:30,13:30 - 14:00,14:00 - 14:30,14:30 - 15:00,15:00 - 15:30,15:30 - 16:00,16:00 - 16:30,16:30 - 17:00,17:00 - 17:30,17:30 - 18:00,18:00 - 18:30,18:30 - 19:00,19:00 - 19:30,19:30 - 20:00,20:00 - 20:30,20:30 - 21:00,21:00 - 21:30,21:30 - 22:00,22:00 - 22:30,22:30 - 23:00,23:00 - 23:30,23:30 - 00:00';
			$line1_agl = '"AccountNumber","NMI","DeviceNumber","DeviceType","RegisterCode","RateTypeDescription","StartDate","EndDate","ProfileReadValue","RegisterReadValue","QualityFlag",';

			switch($first_line)
			{
				case $line1_origin2:
					$method = "ORIGIN2";
					break;
				case $line1_agl:
					$method = "AGL";
					break;
				default:
					$message = "Un-recognised text file!";
					break;
			}
	        break;
	    default:
	        $message = "Un-recognised MIME type!";
	        break;
    }

	// Client ID
    //echo "Obtaining a client ID from the DB ... \n";
    $pb->advance(0.2,'Obtaining an ID from database...');
	$sql = "select nextval('client_id_seq')";
	$pgconn = pgConnection();
    $recordSet = $pgconn->prepare($sql);
    $recordSet->execute();
    while ($row  = $recordSet->fetch())
    {
        $client_id = $row[0];
    }

    // 2) upload in our DB
    switch($method){
    	case "ORIGIN1":
		    //  a. Into staging
    		//echo "Loading ".$staged_file_path." ...\n";
		    $pb->advance(0.3,'Extracting info from PDF to database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin.sh '.$staged_file_path.' '.realpath('../staging'));
    		//echo "Returned output from staging script:".$staging_script."\n";

    		// Extracting the penultimate line, it contains the start date
			$lines=explode("\n", $staging_script);
			$date_start = $lines[count($lines)-2];
			//echo "Extracted date start:".$date_start."\n";

		    // Properly formatted date
		    //echo "Obtaining a client ID from the DB ... \n";
		    $pb->advance(0.5,'Formatting the start date...');
			$sql = "select to_char(to_date('".$date_start."','DD-Mon-YYYY'),'DD/MM/YYYY')";
			$pgconn = pgConnection();
		    $recordSet = $pgconn->prepare($sql);
		    $recordSet->execute();
		    while ($row  = $recordSet->fetch())
		    {
		        $date_start = $row[0];
		    }

		    //  b. From staging to overall consumption table
		    if ($date_start && $client_id)
		    {
			    $pb->advance(0.6,'Loading staged data to main table...');
			    //echo "Running the parameterised query to populate the consumption table ... \n";
			    $query_in_a_string = file_get_contents(realpath('../heatmap').'/load/stc-origin1.sql');

			    // Variable substitution
				$vars = array(
				  '{$v_client_id}'	=> $client_id,
				  '{$v_date_start}'	=> $date_start
				);
				$sql = strtr($query_in_a_string, $vars);
				//echo $sql;

				// Query in a string
				$pgconn = pgConnection();
			    $recordSet = $pgconn->prepare($sql);
			    $recordSet->execute();
		    }
    		break;
    	case "ORIGIN2":
    		$pb->advance(0.6,'Loading into Origin2 staging table...');
    		break;
    	case "AGL":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading AGL CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/agl.sh '.$staged_file_path.' '.realpath('../staging'));

		    // Properly formatted start date
		    $pb->advance(0.4,'Formatting the start date...');
			$sql = "select substring(startdate,1,position(' ' in startdate)) as startdate from staging_agl1 where id=(select min(a.id) from staging_agl1 a)";
			$pgconn = pgConnection();
		    $recordSet = $pgconn->prepare($sql);
		    $recordSet->execute();
		    while ($row  = $recordSet->fetch())
		    {
		        $date_start = $row[0];
		    }

		    //  b. From staging to overall consumption table
		    if ($date_start && $client_id)
		    {
			    $pb->advance(0.5,'Loading staged data to main table...');
			    //echo "Running the parameterised query to populate the consumption table ... \n";
			    $query_in_a_string = file_get_contents(realpath('../heatmap').'/load/stc-agl.sql');

			    // Variable substitution
				$vars = array(
				  '{$v_client_id}'	=> $client_id,
				  '{$v_date_start}'	=> $date_start
				);
				$sql = strtr($query_in_a_string, $vars);
				//echo $sql;

				// Query in a string
				$pgconn = pgConnection();
			    $recordSet = $pgconn->prepare($sql);
			    $recordSet->execute();
		    }

    		break;
    	case "LUMO":
    		break;
    	default:
    		$message = "Unknown loading method";
    }

    //$pb->advance(0.8,'Cleaning up...');
    // 3) cleanup procedure (do not implement right now, to be able to see+fix errors)
    //  a. Remove uploaded file
    //  b. Remove staged data

    //$pb->advance(0.9,'Finalising...');
    // 4) User information
    //  a. Upload complete

	//this destroys the progress object, and ends the output to the client
	//it also emits a special call to the client-side function, with a progress value
	//of -1, which tells the client that the task is completed
    $pb->advance(1,'Finished');

    //  b. Link to the newly uploaded data
	// Will have to find a way to transfer the data that's not sending back a JSON

    // Nullifying the variable will send an end signal to the client
	$pb=null;

}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>