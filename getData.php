<?php


require_once ('settings.php');
require_once ('phpToolkit/SforcePartnerClient.php');
require_once ('phpToolkit/SforceHeaderOptions.php');
require_once ('rackspace/cloudfiles.php');


downloadBackupsFromSalesforce();
doRackspaceUploadNewDeleteOld();


function downloadBackupsFromSalesforce() {


	//get a session ID via the php toolkit API
	$sessionId = getSession();

	//build the cookie info out of session ID and org ID
	$sc = 'Cookie=';
	$sc .= 'oid='.ORGID.'; '; 
	$sc .= 'sid='.$sessionId.'; ';

	//request the data export page in the frontend UI
	$response = getExportPageRaw($sc);

	//print($response);

	$doc = new DOMDocument();
	@$doc->loadHTML($response);

	@$tags = $doc->getElementsByTagName('a'); //get all the href's on the page

	$arrFilenames = array();

	foreach ($tags as $tag) {		//filter to just the href's for the file downloads
		if (strpos($tag->getAttribute('href'), 'servlet.OrgExport?fileName=') !== false) {
			array_push($arrFilenames, 'https://'.SFURL.$tag->getAttribute('href'));	
		}
	}

	//print_r($arrFilenames);
	
	if (count($arrFilenames) > 0) {
		multiRequestBatched($arrFilenames, $sc, 5);
	}

	//locally, we only want to keep 2 weeks worth of files.  So delete anything other than the most recent 2 weeks.
	deleteOldLocalFiles();	
	
}



function doRackspaceUploadNewDeleteOld() {
	//get the most recent grouping of files.
	$arrRecentFiles = getMostRecentFiles();
	sort($arrRecentFiles);

	if (count($arrRecentFiles > 0)) {

		
		//============================================ get our rackspace auth, connection, and container set up =====================================
		//Create the authentication instance
		$auth = new CF_Authentication(RS_USERNAME, RS_KEY);

		//Perform authentication request
		$auth->authenticate();

		//Create a connection to the storage/cdn system(s) and pass in the validated CF_Authentication instance.
		$conn = new CF_Connection($auth);

		//get the "salesforce_backups" container
		$cont = $conn->get_container('dreamforce');
		//============================================ end get our rackspace auth, connection, and container set up =====================================

		
		//get the date prefix off of the most recent grouping of local files
		@$recentFileDate = getDatePrefix($arrRecentFiles[0]);

		//get the listing of files from rackspace cloud files
		$arrRackspaceFiles = $cont->list_objects();
		sort($arrRackspaceFiles);
		
		
		//get a distinct listing off all the rackspace prefixes (Dates)
		$arrRackspaceDistinctPrefixes = filterDistinctPrefixes($arrRackspaceFiles);
		
		//see if the most recent local date is in rackspace or not
		if (!in_array($recentFileDate, $arrRackspaceDistinctPrefixes)) {
			//we haven't uploaded our most recent local files yet to rackspace.  Let's do it.
			uploadToRackspace($cont, $arrRecentFiles);		
		}
		
		//refresh our container and objects so we are sure the newly included files are in them
		$cont = $conn->get_container('salesforce_backups');
			
		//if we have more than 4 distinct date range prefixes (more than 4 weekly backups), delete the older ones so we are just left with the 4 most recent.	
		deleteOlderBackups($conn, $cont);
	}
}




function deleteOldLocalFiles() {
	
	$intBackupsToKeep = 2; //keep 2 weekly backups locally.  Locally is really just a staging area.  the real backups are kept on rackspace cloud files
	
	$arrAllFiles = getAllFiles();	
	$arrDistinctPrefixes = filterDistinctPrefixes($arrAllFiles);
	
	if (count($arrDistinctPrefixes) > $intBackupsToKeep) { //if we have more backups than we want to keep, build a deletion list
		//rsort the array so we keep the 0-1 array positions, and chop off the rest.		
		rsort($arrDistinctPrefixes); 
		$arrPrefixesToKeep = array_slice($arrDistinctPrefixes, 0, $intBackupsToKeep);

		$arrFilesToDelete = array();		
		
		foreach ($arrAllFiles as $value) {		
			$dateVal = getDatePrefix($value);			
			if ($dateVal != '') {				
				if (!in_array($dateVal, $arrPrefixesToKeep)) { //if the file has a prefix which isn't in the list of prefixes to keep, add it to the delete list
					array_push($arrFilesToDelete, $value);
				}				
			}			
		}

		echo "Deleting older LOCAL backups.  There are currently ".count($arrDistinctPrefixes)." weeks worth of an allowed ".$intBackupsToKeep.".  Deleting " .(count($arrDistinctPrefixes)-$intBackupsToKeep). " weeks worth.\n";
		
		$intDelete = 1;
		$intTotalDeletes = count($arrFilesToDelete);
		foreach ($arrFilesToDelete as $value) {		
			echo "Deleting Object: ".$value." (".$intDelete." of ".$intTotalDeletes.")\n";
			unlink('data/'.$value);
			$intDelete ++;		
		}
		
	}
	
}




function deleteOlderBackups($conn, $cont) {
	
	$intBackupsToKeep = 4; //keep 4 weekly backups	
	
	$arrRackspaceFiles = $cont->list_objects();
	$arrDistinctPrefixes = filterDistinctPrefixes($arrRackspaceFiles);	
	
	if (count($arrDistinctPrefixes) > $intBackupsToKeep) { //if we have more backups than we want to keep, build a deletion list
		//rsort the array so we keep the 0-3 array positions, and chop off the rest.		
		rsort($arrDistinctPrefixes); 
		$arrPrefixesToKeep = array_slice($arrDistinctPrefixes, 0, $intBackupsToKeep);

		$arrFilesToDelete = array();		
		
		foreach ($arrRackspaceFiles as $value) {		
			$dateVal = getDatePrefix($value);			
			if ($dateVal != '') {				
				if (!in_array($dateVal, $arrPrefixesToKeep)) { //if the file has a prefix which isn't in the list of prefixes to keep, add it to the delete list
					array_push($arrFilesToDelete, $value);
				}				
			}			
		}

		echo "Deleting older backups.  There are currently ".count($arrDistinctPrefixes)." weeks worth of an allowed ".$intBackupsToKeep.".  Deleting " .(count($arrDistinctPrefixes)-$intBackupsToKeep). " weeks worth.\n";
		
		$intDelete = 1;
		$intTotalDeletes = count($arrFilesToDelete);
		foreach ($arrFilesToDelete as $value) {		
			echo "Deleting Object: ".$value." (".$intDelete." of ".$intTotalDeletes.")\n";
			$cont->delete_object($value);
			$intDelete ++;		
		}
		
	}


}



function uploadToRackspace($cont, $arrFiles) {
	foreach ($arrFiles as $value) {
		echo "Uploading: ".$value." to rackspace.\n";
		
		//create a new object
		$obj = $cont->create_object($value);

		//read in the local file
		$handle = fopen('data/'.$value, "r");

		//write the file
		$obj->write($handle);

		//close the file
		fclose($handle);
	}
}




function getRackspaceFiles($cont) {
	
	$arrRackspaceFiles = array();
	$all_objects = $cont->list_objects();	

	return $arrRackspaceFiles;
}



function getDatePrefix($sInput) {
	//turns this: 20120423-WE_00D00000000hgdLEAQ_1.ZIP
	//into this: 20120423
	$dateVal = '';
	$arrDate = explode('-', $sInput, 2);
	if (count($arrDate) == 2) {
		$dateVal = $arrDate[0];
	}	
	return $dateVal;
}



function getAllFiles() {

	$arrAllFiles = array();
	
	if ($handle = opendir('data')) {
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				array_push($arrAllFiles, $entry);
			}
		}
		closedir($handle);
	}	
	
	return $arrAllFiles;
}




function getMostRecentFiles() {

	$arrAllFiles = array();
	$arrRecentFiles = array();
	$arrDates = array();	
	
	if ($handle = opendir('data')) {
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				array_push($arrAllFiles, $entry);

				$dateVal = getDatePrefix($entry);
				if ($dateVal != '') {
					array_push($arrDates, $dateVal);
				}				

			}
		}
		closedir($handle);
	}	
	
	
	//filter the array down to the unique dates
	$arrDates = array_unique($arrDates);	
	
	//sort the array so we can get the most recent date
	rsort($arrDates);	
	
	if (count($arrDates) > 0) {
		$mostRecentDate = $arrDates[0];

		//loop through all the items, and get the ones which have the most recent date prefix
		foreach ($arrAllFiles as $value) {		
				
			$dateVal = getDatePrefix($value);
			
			if ($dateVal == $mostRecentDate) {
				array_push($arrRecentFiles, $value);
			}
			
		}
	
	}
	
	return $arrRecentFiles;
}

function filterDistinctPrefixes($arrInput) {
	$arrDistinct = array();
	
	foreach ($arrInput as $value) {
		$dateVal = getDatePrefix($value);
		if ($dateVal != '') {
			array_push($arrDistinct, $dateVal);
		}	
	}	

	//filter the array down to the unique dates
	$arrDistinct = array_unique($arrDistinct);	
	
	//sort the array so we can get the most recent date
	rsort($arrDistinct);	
	
	return $arrDistinct;
}




function getExportPageRaw($sc) {	
	$url = 'https://'.SFURL.'/ui/setup/export/DataExportPage/d';
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_COOKIE, $sc);	
	
	$response = curl_exec($ch);
	curl_close($ch);
	
	return $response;
}


function getSession() {
	$sessionId = '';	
	try {
		$mySforceConnection = new SforcePartnerClient();
		$mySoapClient = $mySforceConnection->createConnection('phpToolkit/partner.wsdl.xml');
		$mylogin = $mySforceConnection->login(USERNAME, PASSWORD);
		$sessionId = $mylogin->sessionId;
	} catch (Exception $e) {
		print_r($mySforceConnection->getLastRequest());
		echo $e->faultstring;
	}
	return $sessionId;
}




function multiRequestBatched($data, $sCookie, $batchSize) {
	
	$result = array();

	$intCount = count($data);		
	
	$numBatches = intval($intCount / $batchSize); //the number of whole batches we can get out of our input
	$leftover = intval($intCount % $batchSize); //anything leftover will be our last batch
	
	if ($intCount <= $batchSize) { //if the batch is smaller than the batchsize, just get it all
		$result = multiRequest($data, $sCookie);
		
		$progressMessage = "Request less than batch.  Getting ".$intCount." records.\n";		
		echo $progressMessage;
	
	} else {
		//slice up the array into a 2D array where the second array is the length of the batchsize
		$arrBatches = array();
		$arrBatch = array();
		
		$sliceIndex = 0;
		for ($i = 0; $i < $numBatches; $i++) {
			$arrBatch = array_slice($data, $sliceIndex, $batchSize);
			array_push($arrBatches, $arrBatch);
			$sliceIndex += $batchSize;

			$progressMessage = "Batch ". ($i+1) ." of " .$numBatches.".  ".$batchSize." files per batch. \n";			
			echo $progressMessage;
		}
		
		if ($leftover > 0) {
			$arrBatch = array_slice($data, $intCount-$leftover, $leftover);
			array_push($arrBatches, $arrBatch);
			
			$progressMessage = "Last, leftover batch of ".$leftover." files.";
			echo $progressMessage;			
		}
		
		$batchCounter = 1;		
		foreach ($arrBatches as $theBatch) {
			echo "Downloading batch ".$batchCounter." of ".count($arrBatches)."(".count($theBatch)." in batch)\n";			
			multiRequest($theBatch, $sCookie);			
			$batchCounter ++;
		}
	
	}		

}

//from: http://www.phpied.com/simultaneuos-http-requests-in-php-with-curl/
function multiRequest($data, $sCookie, $options = array()) {

  // array of curl handles
  $curly = array();
  // data to be returned
  $result = array();
  
  // array of file handles
  $arrFH = array();

  // multi handle
  $mh = curl_multi_init();

  // loop through $data and create curl handles
  // then add them to the multi-handle
  foreach ($data as $id => $d) {

    $curly[$id] = curl_init();

    $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
    curl_setopt($curly[$id], CURLOPT_URL,            $url);
	curl_setopt($curly[$id], CURLOPT_BINARYTRANSFER, 1);    
	//curl_setopt($curly[$id], CURLOPT_HEADER,         0);
    curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curly[$id], CURLOPT_COOKIE, $sCookie);
	
	//change this: https://ssl.salesforce.com/servlet/servlet.OrgExport?fileName=WE_00D00000000hgdLEAQ_1.ZIP&id=09200000000cD3X
	//to this: 20120423-WE_00D00000000hgdLEAQ_1.ZIP
	$fname = str_replace('https://'.SFURL.'/servlet/servlet.OrgExport?fileName=', '', $url);
	$arrTemp = explode('.ZIP', $fname);
	$fname = $arrTemp[0].'.ZIP';
	$fname = date('Ymd').'-'.$fname;	
	
	$arrFH[$id] = fopen(DATA_DIR.$fname, 'w'); 
	curl_setopt($curly[$id], CURLOPT_FILE, $arrFH[$id]); 	
	
    // post?
    if (is_array($d)) {
      if (!empty($d['post'])) {
        curl_setopt($curly[$id], CURLOPT_POST,       1);
        curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
      }
    }

    // extra options?
    if (!empty($options)) {
      curl_setopt_array($curly[$id], $options);
    }

    curl_multi_add_handle($mh, $curly[$id]);
  }

  // execute the handles
  $running = null;
  do {
    curl_multi_exec($mh, $running);
  } while($running > 0);

  // get content and remove handles
  foreach($curly as $id => $c) {
    //$result[$id] = curl_multi_getcontent($c);
    curl_multi_remove_handle($mh, $c);
	fclose($arrFH[$id]);	
  }

  // all done
  curl_multi_close($mh);

}



?>






