<?php
header("Content-Type:application/json");
require "word_counter.php";

//prevent XSS vulnerabilities
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

//init variables with defaut values
$search_mode = "find-wc"; //generally, is the faster mode all around. Take a look at WordCounter class for more info about search modes
$file_extension = "txt"; //gimme those texts!
$base_path = $_SERVER["DOCUMENT_ROOT"];
$threshold = 1000; //word count must surpass this threshold in order to search for word concordance
$secondary_threshold = 50; //words must have a concordance higher than this threshold to be returned

//assign values received from request
if(isset($_POST["searchmode"]))
{
	$search_mode = $_POST["searchmode"]; //searchmode can be: "locate", "locate-wc", "locate-awk", "find", "find-wc", "find-awk", "php-search". Any other value will return an error by WordCounter class
}
if(isset($_POST["filetype"])) 
{
	$file_extension = $_POST["filetype"]; //filetype only needs to be provided as is (without dot prefix)
}
if(isset($_POST["basepath"])) 
{
	$base_path .= "/".$_POST["basepath"]; //basepath only needs to be provided as is (without / as prefix/suffix)
}
if(isset($_POST["threshold"]))
{
	$threshold = intval($_POST["threshold"]); //cast it as int
}
if(isset($_POST["secondarythreshold"]))
{
	$secondary_threshold = intval($_POST["secondarythreshold"]); //cast it as int
}

//create a new instance of WordCounter with target params
$wordCounter = new WordCounter($search_mode, $file_extension, $base_path, $threshold, $secondary_threshold);

//lets count those words!
$response = $wordCounter->countWords();

//send response to requesting client
if(isset($response["error"]))
{
	response(400,"Invalid Request", $response);
}
else
{
	response(200,"OK", $response);
}

//auxiliary funtion to build the response object
//
function response($status,$status_message,$data)
{
	header("HTTP/1.1 ".$status);
	
	$response['status']=$status;
	$response['status_message']=$status_message;
	$response['data']=$data;
	
	$json_response = json_encode($response);
	echo $json_response;
}