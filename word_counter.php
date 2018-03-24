<?php

//use this class to iterate recursively over a path, and count all the words on the files found
//
class WordCounter
{
	private $mode;
	private $extension;
	private $path;
	private $threshold;
	private $secondary_threshold;
	
	private $find_files_output;
	private $processed_files;
	
	//constructor
	//
	function __construct($mode, $extension, $path, $threshold, $secondary_threshold)
	{
		$this->mode = $mode;
		$this->extension = $extension;
		$this->path = $path;
		$this->threshold = $threshold;
		$this->secondary_threshold = $secondary_threshold;
		
		$this->find_files_output = "";
		$this->processed_files = array();
	}
	
	//main functionality of WordCounter class: count words
	//it will return an array of paths (all the files of target extension) with their wordcount attached
	//also, if some of the files have more words than threshold, check words concordance and return the words with higher concordance than secondary_threshold
	//
	function countWords()
	{
		$is_preprocessed = false;
		
		switch($this->mode)
		{
			case "locate-wc":
			case "find-wc":
				$is_preprocessed = true; //those are the modes that return also the wordcount preprocessed by shell
				break;
			case "locate":
			case "locate-awk":
			case "find":
			case "find-awk":
			case "php-search":
				$is_preprocessed = false; //standard search modes NOTE: -awk versions return only the files with more words than threshold
				break;
			default:
				return ["error"=>"incorrect mode or mode not found!"];
		}

		$startstamp = microtime(true);
		
		$this->findFilesWithMode();
		
		$searchstamp = microtime(true);

		$this->processFoundFilesOutput($is_preprocessed);

		$endstamp = microtime(true);
		
		//$benchmark = "Processing time of ".$this->mode.": ".($endstamp-$startstamp)." (search time:".($searchstamp - $startstamp)." | process time:".($endstamp - $searchstamp).") \n"; 
		//echo $benchmark; //uncomment those two lines to benchmark the processing time
		
		return $this->processed_files;
	}
	
	//find files using shell commands or by PHP iterators only
	//
	// MODES:
	// - Find: standard command for finding files in unix
	// - Locate: locate can be faster than find in some enviroments as it uses a DB of files for searching (NOTE: locate needs to have its DB refreshed by the server, so the data returned is not in realtime)
	// - PHP: use PHP iterators to recursively search the files (slower)
	//
	// SECONDARY MODES:
	// - wc (wordcount): precomputes word count of files found by find/locate (via piping)
	// - awk: after receiving word count from wc, filter the output showing only files with word count larger than threshold (if file count and average file size are huge, this makes a real improvement if those are the only files needed)
	//
	function findFilesWithMode()
	{
		switch($this->mode)
		{
			case "locate": //using locate, no shell preprocessing
			$this->find_files_output = shell_exec("locate '".$this->path."/*".$this->extension."' -q"); 
			break;
			case "locate-wc": //using locate and wc (wordcount command)
			$this->find_files_output = shell_exec("locate -0 '".$this->path."/*".$this->extension."' -q | xargs -0 wc -w ");
			break;
			case "locate-awk": //using locate and awk filtering
			$this->find_files_output = shell_exec("locate -0 '".$this->path."/*".$this->extension."' -q | xargs -0 wc -w  | awk '{if(\$1>".$this->threshold.")print \$2}'"); 
			break;
			case "find": //using find, no shell preprocessing
			$this->find_files_output = shell_exec("find ".$this->path." -name '".$this->extension."'"); 
			break;
			case "find-wc": //using find and wc (wordcount command)
			$this->find_files_output = shell_exec("find ".$this->path." -name '".$this->extension."' -print0 | wc -w --files0-from=- ");
			break;
			case "find-awk": //using find and awk filtering
			$this->find_files_output = shell_exec("find ".$this->path." -name '".$this->extension."' -print0 | wc -w --files0-from=- | awk '{if(\$1>".$this->threshold.")print \$2}'"); 
			break;
			case "php-search": //if shell file finding can't be used (p.e: non Unix server), use PHP iterators instead
			{
				$this->find_files_output = ""; 

				$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath($this->path),  RecursiveDirectoryIterator::SKIP_DOTS)); //ignore dots on RecursiveDirectoryIterator (seriously, why it isn't a default flag?)
				foreach($objects as $file)
				{
					if ($this->extension == "*.*" || "*.".$file->getExtension() == $this->extension) //if current file matches our target extension, or if the extension is super-wildcard (*.*)
					{
						$this->find_files_output .= $file->getPathname().PHP_EOL;
					}
				}
			}
			break;
			default:
			$this->find_files_output = ["error"=>"incorrect mode or mode not found!"]; //as findFilesWithMode() is called only if search mode is known, this is somewhat redundant (better safe than sorry)
		}
	}
	
	//read collected files line by line, and count its words
	// $is_preprocessed indicates if the file list has been preprocessed by wc (wordcount)
	//
	function processFoundFilesOutput($is_preprocessed)
	{
		$this->processed_files = array(); //clean the array
			
		$lines = explode(PHP_EOL, $this->find_files_output); //as our output from shell is a collection of paths separated by EOL, explode it to create an array.
		
		foreach($lines as &$line)
		{
			$line = trim($line); //trim empty spaces
			
			if($line)
			{
				if($is_preprocessed)
				{
					$file_with_wc = explode(" ", $line); //if no regex is needed, explode is faster than preg_split
					
					$this->processed_files[] = ["path"=> $file_with_wc[1], "total_count"=>(int)$file_with_wc[0]]; //here we don't use intval(), as int casting is faster (and we know that the value returned by shell is an int)
				}
				else
				{
					$this->processed_files[] = ["path"=>$line, "total_count"=>0];
				}
			}			
		}
		
		foreach($this->processed_files as &$file_object)
		{
			if(!$is_preprocessed || ($is_preprocessed && $file_object["total_count"] > $this->threshold)) //if wordcount is preprocessed, ignore files with less words than threshold
			{
				$file = fopen($file_object["path"], 'r');
				
				$words_found = array();
				$word_count = 0;
				
				while (false !== ($line = fgets($file))) //read the file loaded in the stream line by line
				{
					$words = str_word_count($line, 1); //str_word_count() is 2x-3x faster than preg_split, but keep in mind that it uses as a divider anything that's not a letter, so it won't be consistent with the results thrown by wc (wordcount)
					
					foreach($words as $word)
					{
						if(empty($word)) //nothing to do here, move along
						{
							continue; 
						}
						
						$word_count++;
						
						if (!array_key_exists($word,$words_found)) //is faster to check first if the entry exists than accessing directly to its key and creating it on the fly
						{
							$words_found[$word] = 1;
							continue;
						}
						$words_found[$word]+=1;
					}
				}
				
				fclose($file);
				
				$file_object["total_count"] = $word_count;
				
				if($word_count > $this->threshold) //if word count is higher than threshold, filter all the words that surpass the secondary threshold
				{
					$words_found = array_filter($words_found, function($v, $k) {
						return $v >= $this->secondary_threshold;
					}, ARRAY_FILTER_USE_BOTH);
					
					/*
					foreach($words_found as $word => $word_counter) //depending on use case, a simple foreach+unset can be slighty faster than array_filter()
					{
						if($word_counter<$this->secondary_threshold){
							unset($words_found[$word]);
						}
					}
					*/
					
					if($words_found)
					{
						$file_object["words_array"] = $words_found; //if $words_found is not empty, append it to our $file_object
					}
				}
			}
		}
	}
}
?>