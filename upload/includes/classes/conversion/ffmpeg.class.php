<?php

	/**
	* File : FFMPEG Conversion Class
	* Description : Handles all FFMPEG and video conversion related process
	* like generating video qualities, generating thumbs, extracting meta data etc
	* @since : ClipBucket 1.0
	* @author : Arslan Hassan, Awais Tariq, Fawaz Tahir, Fahad Abass
	* @notice : File to be maintained and only by those who properly understand what they are doing
	*/

	define('FFMPEG_BINARY', get_binaries('ffmpeg'));
	define('MP4Box_BINARY', get_binaries('mp4box'));
	define('MEDIAINFO_BINARY', get_binaries('media_info'));
	define('FFPROBE', get_binaries('ffprobe_path'));

	define("thumbs_number",config('num_thumbs'));
	define('PROCESSESS_AT_ONCE',config('max_conversion'));

class FFMpeg
{
	public $defaultOptions = array();
	public $videoDetails = array();
	public $num = thumbs_number;
	private $options = array();
	private $outputFile = false;
	private $inputFile = false;
	public $ffMpegPath = FFMPEG_BINARY;
	private $mp4BoxPath = MP4Box_BINARY;
	private $flvTool2 = FLVTool2_BINARY;
	private $videosDirPath = VIDEOS_DIR;
	private $logDir = "";
	public $log = "";
	public $logs = "";
	private $logFile = "";
	private $sdFile = false;
	private $hdFile = false;
	public $file_name = "";
	public $ratio = "";
	public $log_file = "";
	public $raw_path = "";
	public $audio_track = false;
	public $file_directory = "";
	public $thumb_dim = "";
	public $big_thumb_dim = "";
	public $cb_combo_res = "";
	public $res_configurations = "";
	public $sprite_count = 0;
	public $thumbs_res_settings = array(
		"original" => "original",
		'105' => array('168','105'),
		'260' => array('416','260'),
		'320' => array('632','395'),
		'480' => array('768','432')
		);
	public $res169 = array(
		'240' => array('428','240'),
		'360' => array('640','360'),
		'480' => array('854','480'),
		'720' => array('1280','720'),
		'1080' => array('1920','1080'),
		);
	// this is test comment

	private $resolution4_3 = array(
		'240' => array('428','240'),
		'360' => array('640','360'),
		'480' => array('854','480'),
		'720' => array('1280','720'),
		'1080' => array('1920','1080'),
		);

	/*
	Coversion command example
	/usr/local/bin/ffmpeg 
	-i /var/www/clipbucket/files/conversion_queue/13928857226cc42.mp4  
	-f mp4  
	-vcodec libx264 
	-vpre normal 
	-r 30 
	-b:v 300000 
	-s 426x240 
	-aspect 1.7777777777778 
	-vf pad=0:0:0:0:black 
	-acodec libfaac 
	-ab 128000 
	-ar 22050  
	/var/www/clipbucket/files/videos/13928857226cc42-sd.mp4  
	2> /var/www/clipbucket/files/temp/139288572277710.tmp
	*/

	public function __construct($options = false, $log = false)
	{
		$this->setDefaults();
		if($options && !empty($options))
		{
			$this->setOptions($options);
		} else {
			$this->setOptions($this->defaultOptions);
		}
		if($log)
			$this->log = $log;
		$str = "/".date("Y")."/".date("m")."/".date("d")."/";
		//$this->logs->writeLine("in class", "ffmpeg");
		$this->logDir = BASEDIR . "/files/logs/".$str;
	}

	function exec( $cmd )
	{
		# use bash to execute the command
		# add common locations for bash to the PATH
		# this should work in virtually any *nix/BSD/Linux server on the planet
		# assuming we have execute permission
		//$cmd = "PATH=\$PATH:/bin:/usr/bin:/usr/local/bin bash -c \"$cmd\" ";
		return shell_exec($cmd);
	}

	function parse_format_info( $output )
	{
		$info = $this->raw_info;
		# search the output for specific patterns and extract info
		# check final encoding message
		if($args = self::pregMatch( 'Unknown format', $output) ) {
			$Unkown = "Unkown";
		} else {
			$Unkown = "";
		}
		if( $args = self::pregMatch( 'video:([0-9]+)kB audio:([0-9]+)kB global headers:[0-9]+kB muxing overhead', $output) ) {
			$video_size = (float)$args[1];
			$audio_size = (float)$args[2];
		} else {
			return false;
		}

		# check for last enconding update message
		if($args = self::pregMatch( '(frame=([^=]*) fps=[^=]* q=[^=]* L)?size=[^=]*kB time=([^=]*) bitrate=[^=]*kbits\/s[^=]*$', $output) ) {
			$frame_count = $args[2] ? (float)ltrim($args[2]) : 0;
			$duration    = (float)$args[3];
		} else {
			return false;
		}

		if(!$duration)
		{
			$duration = self::pregMatch( 'Duration: ([0-9.:]+),', $output );
			$duration = $duration[1];
			
			$duration = explode(':',$duration);
			//Convert Duration to seconds
			$hours = $duration[0];
			$minutes = $duration[1];
			$seconds = $duration[2];
			
			$hours = $hours * 60 * 60;
			$minutes = $minutes * 60;
			
			$duration = $hours+$minutes+$seconds;
		}

		$info['duration'] = $duration;
		if($duration)
		{
			$info['bitrate' ] = (integer)($info['size'] * 8 / 1024 / $duration);
			if( $frame_count > 0 )
				$info['video_rate']	= (float)$frame_count / (float)$duration;
			if( $video_size > 0 )
				$info['video_bitrate'] = (integer)($video_size * 8 / $duration);
			if( $audio_size > 0 )
				$info['audio_bitrate'] = (integer)($audio_size * 8 / $duration);
				# get format information
			if($args = self::pregMatch( "Input #0, ([^ ]+), from", $output) ) {
				$info['format'] = $args[1];
			}
		}

		# get video information
		if($args = self::pregMatch( '([0-9]{2,4})x([0-9]{2,4})', $output ) )
		{
			$info['video_width'   ] = $args[1];
			$info['video_height'  ] = $args[2];
			$info['video_wh_ratio'] = (float)$info['video_width'] / (float)$info['video_height'];
		}
		
		if($args= $this->pregMatch('Video: ([^ ^,]+)',$output))
		{
			$info['video_codec'] = $args[1];
		}

		# get audio information
		if($args = self::pregMatch( "Audio: ([^ ]+), ([0-9]+) Hz, ([^\n,]*)", $output) ) {
			$audio_codec = $info['audio_codec'] = $args[1];
			$audio_rate = $info['audio_rate'] = $args[2];
			$info['audio_channels'] = $args[3];
		}
		
		if(!$audio_codec || !$audio_rate)
		{
			$args = self::pregMatch( "Audio: ([a-zA-Z0-9]+)(.*), ([0-9]+) Hz, ([^\n,]*)", $output);
			$info['audio_codec'   ] = $args[1];
			$info['audio_rate'    ] = $args[3];
			$info['audio_channels'] = $args[4];
		}

		$this->raw_info = $info;
		# check if file contains a video stream
		return $video_size > 0;

		#TODO allow files with no video (only audio)?
		#return true;
	}

	/**
	 * Function used to get file information using FFPROBE
	 *
	 * @param FILE_PATH
	 *
	 * @return mixed
	 */
	function get_file_info($file_path=NULL)
	{
		if(!$file_path)
			$file_path = $this->input_file;

		$info['format']         = 'N/A';
		$info['duration']       = 'N/A';
		$info['size']           = 'N/A';
		$info['bitrate']        = 'N/A';
		$info['video_width']    = 'N/A';
		$info['video_height']   = 'N/A';
		$info['video_wh_ratio'] = 'N/A';
		$info['video_codec']    = 'N/A';
		$info['video_rate']     = 'N/A';
		$info['video_bitrate']  = 'N/A';
		$info['video_color']    = 'N/A';
		$info['audio_codec']    = 'N/A';
		$info['audio_bitrate']  = 'N/A';
		$info['audio_rate']     = 'N/A';
		$info['audio_channels'] = 'N/A';
		$info['path']           = $file_path;

		$cmd = FFPROBE. " -v quiet -print_format json -show_format -show_streams '".$file_path."' ";
		logData($cmd,'command');
		$output = shell_output($cmd);
		logData($cmd,'output');
		$output = preg_replace('/([a-zA-Z 0-9\r\n]+){/', '{', $output, 1);

		$data = json_decode($output,true);

		$video = NULL;
		$audio = NULL;
		foreach($data['streams'] as $stream)
		{
			if( $stream['codec_type'] == 'video' && empty($video) )
			{
				$video = $stream;
				continue;
			}

			if( $stream['codec_type'] == 'audio' && empty($audio) )
			{
				$audio = $stream;
				continue;
			}

			if( !empty($video) && !empty($audio) )
				break;
		}

		$info['format']         = $data['format']['format_name'];
		$info['duration']       = (float) round($video['duration'],2);

		$info['bitrate']        = (int) $data['format']['bit_rate'];
		$info['video_bitrate']  = (int) $video['bit_rate'];
		$info['video_width']    = (int) $video['width'];
		$info['video_height']   = (int) $video['height'];

		if($video['height'])
			$info['video_wh_ratio'] = (int) $video['width'] / (int) $video['height'];
		$info['video_codec']    = $video['codec_name'];
		$info['video_rate']     = $video['r_frame_rate'];
		$info['size']           = filesize($file_path);
		$info['audio_codec']    = $audio['codec_name'];
		$info['audio_bitrate']  = (int) $audio['bit_rate'];
		$info['audio_rate']     = (int) $audio['sample_rate'];
		$info['audio_channels'] = (float) $audio['channels'];
		$info['rotation']       = (float) $video['tags']['rotate'];

		if(!$info['duration'] || 1)
		{
			$CMD = MEDIAINFO_BINARY . "   '--Inform=General;%Duration%'  '". $file_path."' 2>&1 ";
			logData($CMD,'command');
			$info['duration'] = round(shell_output( $CMD )/1000,2);
		}

		$this->raw_info = $info;
		$video_rate = explode('/',$info['video_rate']);
		$int_1_video_rate = (int)$video_rate[0];
		$int_2_video_rate = (int)$video_rate[1];

		$CMD = MEDIAINFO_BINARY . "   '--Inform=Video;'  ". $file_path;
		logData($CMD,'command');

		$results = shell_output($CMD);
		$needle_start = "Original height";
		$needle_end = "pixels"; 
		$original_height = find_string($needle_start,$needle_end,$results);
		$original_height[1] = str_replace(' ', '', $original_height[1]);
		if(!empty($original_height)&&$original_height!=false)
		{
			$o_height = trim($original_height[1]);
			$o_height = (int)$o_height;
			if($o_height!=0&&!empty($o_height))
			{
				$info['video_height'] = $o_height;
			}
		}
		$needle_start = "Original width";
		$needle_end = "pixels"; 
		$original_width = find_string($needle_start,$needle_end,$results);
		$original_width[1] = str_replace(' ', '', $original_width[1]);
		if(!empty($original_width)&&$original_width!=false)
		{
			$o_width = trim($original_width[1]);
			$o_width = (int)$o_width;
			if($o_width!=0&&!empty($o_width))
			{
				$info['video_width'] = $o_width;
			}
		}
		
		if($int_2_video_rate!=0)
		{
			$info['video_rate'] = $int_1_video_rate/$int_2_video_rate;
		}

		logData(json_encode($info),$this->file_name.'_configs');
		return $info;
	}

	public function convertVideo($inputFile = false, $options = array(), $isHd = false)
	{
		$this->log->TemplogData = "";
		$this->log->TemplogData .= "\r\n======Converting Video=========\r\n";
		if($inputFile)
		{
			if(!empty($options)){
				$this->setOptions($options);
			}
			$this->inputFile = $inputFile;
			$this->outputFile = $this->videosDirPath . '/'. $this->options['outputPath'] . '/' . $this->getInputFileName($inputFile);
			$videoDetails = $this->getVideoDetails($inputFile);
			$this->videoDetails = $videoDetails;
			$this->output = new stdClass();
			$this->output->videoDetails = $videoDetails;

			/*
				Conversion Starts here
			*/
			$this->convertToLowResolutionVideo($videoDetails);
		}
	}

	private function convertToLowResolutionVideo($videoDetails = false)
	{
		if($videoDetails)
		{
			//logData(json_encode($videoDetails));
			$this->hdFile = "{$this->outputFile}-hd.{$this->options['format']}";
			$out= shell_exec($this->ffMpegPath ." -i {$this->inputFile} -acodec copy -vcodec copy -y -f null /dev/null 2>&1");
			$len = strlen($out);
			$findme = 'Video';
			$findme1 = 'fps';
			$pos = strpos($out, $findme);
			$pos = $pos + 48;
			$pos1 = strpos($out, $findme1);
			$bw = $len - ($pos1 - 5);
			$rest = substr($out, $pos, -$bw);
			$rest = ','.$rest;
			$dura = explode(',',$rest);
			$dura[1] = $dura[1].'x';
			$dura = explode('x',$dura[1]);
			if($dura[1] >= "720" || $videoDetails['video_height'] >= "720")
			{
				$this->log->TemplogData .="\r\n\r\n=======Low Resolution Conversion=======\r\n\r\n";
				$this->log->TemplogData .= "\r\n Sarting : Generating Low resolution video @ ".date("Y-m-d H:i:s")." \r\n";
				$this->log->TemplogData .= "\r\n Converting Video SD File  \r\n";
				$this->sdFile = "{$this->outputFile}-sd.{$this->options['format']}";
				$fullCommand = $this->ffMpegPath . " -i {$this->inputFile}" . $this->generateCommand($videoDetails, false) . " {$this->sdFile}";

				$this->log->TemplogData .= "\r\n Command : ".$fullCommand." \r\n";

				$conversionOutput = $this->executeCommand($fullCommand);
				$this->log->TemplogData .= "\r\n ffmpeg output : ".$conversionOutput." \r\n";

				$this->log->TemplogData .= "\r\n outputFile : ".$this->sdFile." \r\n";
				
				//$this->logs->writeLine("MP4Box Conversion for SD", "Starting");
				$this->log->TemplogData .= "\r\n Sarting : MP4Box Conversion for SD \r\n";
				$fullCommand = $this->mp4BoxPath . " -inter 0.5 {$this->sdFile}  -tmp ".TEMP_DIR;
				if (PHP_OS == "WINNT")
				{
					$fullCommand = str_replace("/","\\",$fullCommand);	
				}
				$this->log->TemplogData .= "\r\n MP4Box Command : ".$fullCommand." \r\n";
				$output = $this->executeCommand($fullCommand);
				$this->log->TemplogData .= "\r\n output : ".$output." \r\n";
				
				if (file_exists($this->sdFile))
				{
					$this->video_files[] = 'sd';
					$this->sdFile1 = "{$this->outputFile}.{$this->options['format']}";
					$status = "Successful";
					$this->log->TemplogData .= "\r\n Conversion Status : ".$status." @ ".date("Y-m-d H:i:s")." \r\n";
					$this->log->writeLine("Conversion Ouput",$this->log->TemplogData, true);
					
					$this->output_file = $this->sdFile;
					$this->output_details = $this->get_file_info($this->output_file);
					$this->log_ouput_file_info();
				}
				$this->log->TemplogData .="\r\n\r\n=======High Resolution Conversion=======\r\n\r\n";
				$this->log->TemplogData .= "\r\n Sarting : Generating high resolution video @ ".date("Y-m-d H:i:s")."\r\n";

				$this->log->TemplogData .= "\r\n Converting Video HD File   \r\n";
				
				$this->hdFile = "{$this->outputFile}-hd.{$this->options['format']}";
				$log_file_tmp = TEMP_DIR."/".$this->file_name.".tmp";
				$fullCommand = $this->ffMpegPath . " -i {$this->inputFile}" . $this->generateCommand($videoDetails, true) . " {$this->hdFile} > {$log_file_tmp}";
			
				$this->log->TemplogData .= "\r\n Command : ".$fullCommand." \r\n";
				//logData(json_encode($fullCommand));
				$conversionOutput = $this->executeCommand($fullCommand);
				if(file_exists($log_file_tmp))
				{
					$data = file_get_contents($log_file_tmp);
					unlink($log_file_tmp);
				}
				$this->log->TemplogData .= "\r\n ffmpeg output : ".$data." \r\n";

				$this->log->TemplogData .= "\r\n Sarting : MP4Box Conversion for HD \r\n";
				$fullCommand = $this->mp4BoxPath . " -inter 0.5 {$this->hdFile}  -tmp ".TEMP_DIR;
				//logData(json_encode($fullCommand));
				if (PHP_OS == "WINNT")
				{
					$fullCommand = str_replace("/","\\",$fullCommand);	
				}
				$this->log->TemplogData .= "\r\n MP4Box Command : ".$fullCommand." \r\n";
				$output = $this->executeCommand($fullCommand);
				$this->log->TemplogData .= "\r\n output : ".$output." \r\n";
				if (file_exists($this->hdFile))
				{
					$this->video_files[] = 'hd';
					$this->sdFile1 = "{$this->outputFile}.{$this->options['format']}";
					$status = "Successful";
					$this->log->TemplogData .= "\r\n Conversion Status : ".$status." @ ".date("Y-m-d H:i:s")."\r\n";
					$this->log->writeLine("Conversion Ouput",$this->log->TemplogData, true);

					$this->output_file = $this->hdFile;
					$this->output_details = $this->get_file_info($this->output_file);
					$this->log_ouput_file_info();
				}
			} else {
				$this->log->TemplogData .="\r\n\r\n=======Low Resolution Conversion=======\r\n\r\n";
				$this->log->TemplogData .= "\r\n Sarting : Generating Low resolution video @ ".date("Y-m-d H:i:s")." \r\n";
				$this->log->TemplogData .= "\r\n Converting Video SD File  \r\n";
				$this->sdFile = "{$this->outputFile}-sd.{$this->options['format']}";
				$fullCommand = $this->ffMpegPath . " -i {$this->inputFile}" . $this->generateCommand($videoDetails, false) . " {$this->sdFile}";
				logData(json_encode($fullCommand),"sd_vidoes");
				$this->log->TemplogData .= "\r\n Command : ".$fullCommand." \r\n";

				$conversionOutput = $this->executeCommand($fullCommand);
				//logData(json_encode($fullCommand));

				$this->log->TemplogData .= "\r\n ffmpeg output : ".$conversionOutput." \r\n";
				
				$this->log->TemplogData .= "\r\n Sarting : MP4Box Conversion for SD \r\n";
				$fullCommand = $this->mp4BoxPath . " -inter 0.5 {$this->sdFile}  -tmp ".TEMP_DIR;
				//logData(json_encode($fullCommand));
				if (PHP_OS == "WINNT")
				{
					$fullCommand = str_replace("/","\\",$fullCommand);	
				}
				$this->log->TemplogData .= "\r\n MP4Box Command : ".$fullCommand." \r\n";
				$output = $this->executeCommand($fullCommand);
				$this->log->TemplogData .= "\r\n output : ".$output." \r\n";
				if (file_exists($this->sdFile))
				{
					$this->video_files[] = 'sd';
					$this->sdFile1 = "{$this->outputFile}.{$this->options['format']}";
					$status = "Successful";
					$this->log->TemplogData .= "\r\n Conversion Status : ".$status." @ ".date("Y-m-d H:i:s")." \r\n";
					$this->log->writeLine("Conversion Ouput",$this->log->TemplogData, true);

					$this->output_file = $this->sdFile;
					$this->output_details = $this->get_file_info($this->output_file);
					$this->log_ouput_file_info();
				}
				
			}
			$this->log->TemplogData = "";
		}
	}

	private function getPadding($padding = array())
	{
		if(!empty($padding)){
			return " pad={$padding['top']}:{$padding['right']}:{$padding['bottom']}:{$padding['left']}:{$padding['color']} ";
		}
	}

	private function getInputFileName($filePath = false)
	{
		if($filePath)
		{
			$path = explode("/", $filePath);
			$name = array_pop($path);
			$name = substr($name, 0, strrpos($name, "."));
			return $name;
		}
		return false;
	}

	public function setOptions($options = array())
	{
		if(!empty($options))
		{
			if(is_array($options))
			{
				foreach ($options as $key => $value) 
				{
					if(isset($this->defaultOptions[$key]) && !empty($value)){
						$this->options[$key] = $value;
					}
				}
			} else {
				$this->options[0] = $options;
			}
		}
	}

	private function generateCommand($videoDetails = false, $isHd = false)
	{
		if($videoDetails)
		{
			$result = shell_output("ffmpeg -version");
			preg_match("/(?:ffmpeg\\s)(?:version\\s)?(\\d\\.\\d\\.(?:\\d|[\\w]+))/i", strtolower($result), $matches);
			if(count($matches) > 0)
			{
				$version = array_pop($matches);
			}

			$commandSwitches = "";
			if(isset($this->options['video_codec'])){
				$commandSwitches .= " -vcodec " .$this->options['video_codec'];
			}
			if(isset($this->options['audio_codec'])){
				$commandSwitches .= " -acodec " .$this->options['audio_codec'];
			}

			/*
				Setting Size Of output video
			*/
			if($isHd)
			{
				$height_tmp = min($videoDetails['video_height'],1080);
				$width_tmp = min($videoDetails['video_width'],1920);
			} else {
				$height_tmp = max($videoDetails['video_height'],360);
				$width_tmp = max($videoDetails['video_width'],360);
			}
			$size = "{$width_tmp}x{$height_tmp}";

			if ($version == "0.9")
			{
				if($isHd)
				{
					$vpre = "hq";
				} else {
					$vpre = "normal";
				}
				$commandSwitches .= " -s {$size} -vpre {$vpre}";
			} else {
				if($isHd)
				{
					$vpre = "slow";
				} else {
					$vpre = "medium";
				}
				$commandSwitches .= " -s {$size} -preset {$vpre}";
			}

			if(isset($this->options['format'])){
				$commandSwitches .= " -f " .$this->options['format'];
			}

			if(isset($this->options['video_bitrate']))
			{
				$videoBitrate = (int)$this->options['video_bitrate'];
				if($isHd){
					$videoBitrate = (int)$this->options['video_bitrate_hd'];
				}
				$commandSwitches .= " -vb ".$videoBitrate;
			}
			if(isset($this->options['audio_bitrate'])){
				$commandSwitches .= " -ab " .$this->options['audio_bitrate'];
			}
			if(isset($this->options['video_rate'])){
				$commandSwitches .= " -r " .$this->options['video_rate'];
			}
			if(isset($this->options['audio_rate'])){
				$commandSwitches .= " -ar " .$this->options['audio_rate'];
			}
			return $commandSwitches;
		}
		return false;
	}

	function log($name,$value)
	{
		$this->log .= $name.' : '.$value."\r\n";
	}
	
	/**
	 * Function used to start log
	 */
	function start_log()
	{
		$TemplogData = "Started on ".NOW()." - ".date("Y M d")."\n\n";
		$TemplogData .= "Checking File...\n";
		$TemplogData .= "File : {$this->input_file}";
		$this->log->writeLine("Starting Conversion", $TemplogData, true);
	}
	
	/**
	 * Function used to log video info
	 */
	function log_file_info()
	{
		$details = $this->input_details;
		$configLog = '';
		if(is_array($details))
		{
			foreach($details as $name => $value)
			{
				$configLog .= "<strong>{$name}</strong> : {$value}\n";
			}
		} else {
			$configLog = "Unknown file details - Unable to get video details using FFMPEG \n";
		}

		$this->log->writeLine('Preparing file...', $configLog, true);
	}

	/**
	 * Function log outpuit file details
	 */
	function log_ouput_file_info()
	{
		$details = $this->output_details;
		$configLog = '';
		if(is_array($details))
		{
			foreach($details as $name => $value)
			{
				$configLog .= "<strong>{$name}</strong> : {$value}\n";
			}
		} else {
			$configLog = "Unknown file details - Unable to get video details using FFMPEG \n";
		}

		$this->log->writeLine('OutPut Deatils',$configLog,true);
	}

	function time_check()
	{
		$time = microtime();
		$time = explode(' ',$time);
		$time = $time[1]+$time[0];
		return $time;
	}
	
	/**
	 * Function used to start timing
	 */
	function start_time_check()
	{
		$this->start_time = $this->time_check();
	}
	
	/**
	 * Function used to end timing
	 */
	function end_time_check()
	{
		$this->end_time = $this->time_check();
	}
	
	/** 
	 * Function used to check total time 
	 */
	function total_time()
	{
		$this->total_time = round(($this->end_time-$this->start_time),4);
	}
	
	function getClosest($search, $arr) 
	{
		$closest = null;
		foreach ($arr as $item) 
		{
			if($closest === null || abs($search - $closest) > abs($item - $search)) {
				$closest = $item;
			}
		}
		return $closest;
	}

	/**
	 * @Reason : this function is used to rearrange required resolution for conversion
	 *
	 * @params : { resolutions (Array) }
	 *
	 * @date : 13-12-2016
	 * return : refined resolution array
	 *
	 * @param $resolutions
	 *
	 * @return bool
	 */
	function reindex_required_resolutions($resolutions)
	{
		$original_video_height = $this->input_details['video_height'];

		// Setting threshold for input video to convert
		$valid_dimensions = array(240,360,480,720,1080);
		$input_video_height = $this->getClosest($original_video_height, $valid_dimensions);

		logData("input : video : ".$input_video_height,"checkpoints");
		logData($this->options,'checkpoints');
		//Setting contidion to place resolution to first near to input video 
		if ($this->options['gen_'.$input_video_height]  == 'yes'){
			$final_res[$input_video_height] = $resolutions[$input_video_height];
		}
		foreach ($resolutions as $key => $value) 
		{
			//$video_width=(int)$value[0];
			$video_height=(int)$value[1];	
			if($input_video_height != $video_height && $this->options['gen_'.$video_height] == 'yes'){
				$final_res[$video_height] = $value;	
			}
		}
		
		logData("Final Res : ".$final_res,"checkpoints");

		$revised_resolutions = $final_res;
		if ( $revised_resolutions )
			return $revised_resolutions;
		return false;
	}

	function isLocked($num=1)
	{
		for($i=0;$i<$num;$i++)
		{
			$conv_file = TEMP_DIR.'/conv_lock'.$i.'.loc';
			if(!file_exists($conv_file))
			{
				$this->lock_file = $conv_file;
				$file = fopen($conv_file,"w+");
				fwrite($file,"converting..");
				fclose($file);
				return false;
			}
		}
		return true;
	}

	function ClipBucket()
	{
		$conv_file = TEMP_DIR.'/conv_lock.loc';

		//We will now add a loop that will check weather
		logData('Checking conversion locks','checkpoints');
		while( true )
		{
			$use_crons = config('use_crons');
			if( !$this->isLocked(PROCESSESS_AT_ONCE) || $use_crons == 'yes' )
			{
				if( $use_crons == 'no' )
				{
					//Lets make a file
					$file = fopen($conv_file,"w+");
					fwrite($file,"converting..");
					fclose($file);
				}

				$this->start_time_check();
				$this->start_log();
				$this->prepare();
				
				$ratio = substr($this->input_details['video_wh_ratio'], 0, 7);
				
				$max_duration = config('max_video_duration') * 60;

				if( $this->input_details['duration'] > $max_duration )
				{
					$max_duration_seconds = $max_duration / 60; 
					$log = "Video duration was ".$this->input_details['duration']." minutes and Max video duration is {$max_duration_seconds} minutes, Therefore Video cancelled\n";
					$log .= "Conversion_status : failed\n";
					$log .= "Failed Reason : Max Duration Configurations\n";
					$this->log->writeLine("Max Duration configs", $log, true);
					$this->failed_reason = 'max_duration';
	
					break;
				}
				$ratio = (float)$ratio;
				if( $ratio >= 1.6 )
					$res = $this->options['res169'];
				else
					$res = $this->options['res43'];

				logData('Video is about to convert','checkpoints');
				
				$nr = $this->options['normal_res'];
				 /*Added by Hassan Baryar bug#268 **/
				if($nr=='320')
					$nr='360';
				/*End*/

				$this->log->writeLine("Thumbs Generation", "Starting");
				$log = "";
				try {
					$thumbs_settings = $this->thumbs_res_settings;
					logData($thumbs_settings,'checkpoints');
					foreach( $thumbs_settings as $key => $thumbs_size )
					{
						$height_setting = $thumbs_size[1];
						$width_setting = $thumbs_size[0];
						$dimension_setting = $width_setting.'x'.$height_setting;
						if( $key == 'original' )
						{
							$dimension_setting = $key;
							$dim_identifier = $key;	
						} else {
							$dim_identifier = $width_setting.'x'.$height_setting;	
						}
						$thumbs_settings['vid_file'] = $this->input_file;
						$thumbs_settings['duration'] = $this->input_details['duration'];
						$thumbs_settings['num']      = thumbs_number;
						$thumbs_settings['dim']      = $dimension_setting;
						$thumbs_settings['size_tag'] = $dim_identifier;
						$this->generateThumbs($thumbs_settings);
					}
					
				} catch(Exception $e) {
					$log .= "\r\n Error Occured : ".$e->getMessage()."\r\n";
				}
				$log .= "\r\n ====== End : Thumbs Generation ======= \r\n";
				$this->log->writeLine("Thumbs Files", $log, true );


				$hr = $this->options['high_res'];
				$this->options['video_width'] = $res[$nr][0];
				$this->options['format'] = 'mp4';
				$this->options['video_height'] = $res[$nr][1];
				$this->options['hq_video_width'] = $res[$hr][0];
				$this->options['hq_video_height'] = $res[$hr][1];
				$orig_file = $this->input_file;
				
				// setting type of conversion, fetching from configs
				$this->resolutions = $this->options['cb_combo_res'];

				switch( $this->resolutions )
				{
					case 'yes':
						$res169 = $this->reindex_required_resolutions($this->res169);
						
						$this->ratio = $ratio;
						foreach( $res169 as $value )
						{
							$video_width  = (int)$value[0];
							$video_height = (int)$value[1];

							// Here we must check width and height to be able to import other formats than 16/9 (For example : 1920x800, 1800x1080, ...)
							if( $this->input_details['video_width'] >= $video_width || $this->input_details['video_height'] >= $video_height )
							{
								$more_res['video_width']  = $video_width;
								$more_res['video_height'] = $video_height;
								$more_res['name']		  = $video_height;
								logData($more_res['video_height'], 'reindex');
								$this->convert(NULL, false, $more_res);
							}
						}
						break;

					case 'no':
					default :
						$this->convertVideo($orig_file);
						break;
				}

				$this->end_time_check();
				$this->total_time();
				
				//Copying File To Original Folder
				if( $this->keep_original == 'yes' )
				{
					$log .= "\r\nCopy File to original Folder";
					if( copy($this->input_file, $this->original_output_path) )
						$log .= "\r\nFile Copied to original Folder...";
					else
						$log .= "\r\nUnable to copy file to original folder...";
				}

				$log .= "\r\n\r\nTime Took : ";
				$log .= $this->total_time.' seconds'."\r\n\r\n";

				if(file_exists($this->output_file) && filesize($this->output_file) > 0)
					$log .= "conversion_status : completed ";
				else
					$log .= "conversion_status : failed ";
				
				$this->log->writeLine("Conversion Completed", $log, true );

				break;
			}

			// Prevent video_convert action to use 100% cpu while waiting for queued videos to end conversion
			sleep(5);
		}
		
	}
	
	public function generate_thumbs($input_file,$duration, $dim='120x90', $num=3, $prefix=NULL, $rand=NULL, $gen_thumb=FALSE, $output_file_path=false, $specific_dura=false)
	{
		if($specific_dura)
		{
			$durations_format = gmdate("H:i:s", $duration);
			$command = $this->ffmpeg." -i $input_file -ss ".$durations_format." -r 1 $dim -y -f image2 -vframes 1 $output_file_path ";
			shell_output($command);
		} else {
			$tmpDir = TEMP_DIR.'/'.getName($input_file);
			if(!file_exists($tmpDir))
				mkdir($tmpDir,0777,true);
			$dimension = '';

			$duration = (int)$duration + 7;
			$half_vid_duration =(int) ($duration/2);
			$one_third_duration =(int) ($duration/3);
			$one_forth_duration =(int) ($duration/4);
			$durations = array($half_vid_duration,$one_third_duration,$one_forth_duration);
			logData('thumbs_command=> '.json_encode($durations),'thumbs');
			foreach ($durations as $key => $duration) 
			{
				$key1 = $key+1;
				$this->log .= "\r\n=====THUMBS LOG========";

				$file_name = $this->file_name."-{$prefix}{$key1}.jpg";
			
				$file_path = THUMBS_DIR.'/'.$this->video_folder.$file_name;

				$this->log .= "\r\n";

				$durations_format = gmdate("H:i:s", $duration);

				logData('thumbs_command=> '.$duration."\n".$durations_format,'thumbs');

				$this->log .= $command = $this->ffmpeg."   -i $input_file  -ss ".$durations_format."   -r 1 $dimension -y -f image2 -vframes 1 $file_path ";

				logData('thumbs_command=> '.$command,'thumbs');
				$this->exec($command);

				//checking if file exists in temp dir
				if(file_exists($tmpDir.'/00000001.jpg'))
				{
					rename($tmpDir.'/00000001.jpg',THUMBS_DIR.'/'.$this->video_folder.$file_name);
				}
				if(file_exists($tmpDir))
					rmdir($tmpDir);
			}
		}
	}

	private function executeCommand($command = false)
	{
		// the last 2>&1 is for forcing the shell_exec to return the output 
		if($command)
			return shell_exec($command . " 2>&1");
		return false;
	}

	private function setDefaults()
	{
		if(PHP_OS == "Linux")
		{
			$ac = 'libfaac';
		} elseif(PHP_OS == "WINNT") {
			$ac = 'libvo_aacenc';
		}
		$this->defaultOptions = array(
			'format' => 'mp4',
			'video_codec'=> 'libx264',
			'audio_codec'=> $ac,
			'audio_rate'=> '22050',
			'audio_bitrate'=> '128000',
			'video_rate'=> '25',
			'video_bitrate'=> '300000',
			'video_bitrate_hd'=> '500000',
			'normal_res' => false,
			'high_res' => false,
			'max_video_duration' => false,
			'resolution16_9' => $this->resolution16_9,
			'resolution4_3' => $this->resolution4_3,
			'resize'=>'max',
			'outputPath' => false,
			'use_video_rate' => false,
			'use_video_bit_rate' => false,
			'use_audio_rate' => false,
			'use_audio_bit_rate' => false,
			'use_audio_codec' => false,
			'use_video_codec' => false,
			'cb_combo_res' => false,
			'gen_240' => false,
			'gen_360' => false,
			'gen_480' => false,
			'gen_720' => false,
			'gen_1080' => false
		);
	}

	function ffmpeg($file)
	{
		global $Cbucket;

		$this->ffmpeg = FFMPEG_BINARY;
		$this->mp4box = MP4Box_BINARY;
		$this->flvtool2 = FLVTool2_BINARY;
		$this->flvtoolpp = $Cbucket->configs['flvtoolpp'];
		$this->mplayerpath = $Cbucket->configs['mplayerpath'];
		$this->input_file = $file;
	}

	function calculate_size_padding( $parameters, $source_info, & $width, & $height, & $ratio, & $pad_top, & $pad_bottom, & $pad_left, & $pad_right )	
	{
		$p = $parameters;
		$i = $source_info;

		switch( $p['resize'] )
		{
			# dont resize, use same size as source, and aspect ratio
			# WARNING: some codec will NOT preserve the aspect ratio
			case 'no':
				$width      = $i['video_width'];
				$height     = $i['video_height'];
				$ratio      = $i['video_wh_ratio'];
				$pad_top    = 0;
				$pad_bottom = 0;
				$pad_left   = 0;
				$pad_right  = 0;
				break;
			# resize to parameters width X height, use same aspect ratio
			# WARNING: some codec will NOT preserve the aspect ratio
			case 'WxH':
				$width  = $p['video_width'];
				$height = $p['video_height'];
				$ratio  = $i['video_wh_ratio'];
				$pad_top    = 0;
				$pad_bottom = 0;
				$pad_left   = 0;
				$pad_right  = 0;
				break;
			# make pixel square
			# reduce video size if bigger than p[width] X p[height]
			# and preserve aspect ratio
			case 'max':
				$width        = (float)$i['video_width'];
				$height       = (float)$i['video_height'];
				$ratio        = (float)$i['video_wh_ratio'];
				$max_width    = (float)$p['video_width'];
				$max_height   = (float)$p['video_height'];

				# make pixels square
				if( $ratio > 1.0 )
					$width = $height * $ratio;
				else
					$height = @$width / $ratio;

				# reduce width
				if( $width > $max_width ) {
					$r       = $max_width / $width;
					$width  *= $r;
					$height *= $r;
				}

				# reduce height
				if( $height > $max_height ) {
					$r       = $max_height / $height;
					$width  *= $r;
					$height *= $r;
				}

				# make size even (required by many codecs)
				$width  = (integer)( ($width  + 1 ) / 2 ) * 2;
				$height = (integer)( ($height + 1 ) / 2 ) * 2;
				# no padding
				$pad_top    = 0;
				$pad_bottom = 0;
				$pad_left   = 0;
				$pad_right  = 0;
				break;
			# make pixel square
			# resize video to fit inside p[width] X p[height]
			# add padding and preserve aspect ratio
			case 'fit':
				# values need to be multiples of 2 in the end so
				# divide width and height by 2 to do the calculation
				# then multiply by 2 in the end
				$ratio        = (float)$i['video_wh_ratio'];
				$width        = (float)$i['video_width'   ] / 2;
				$height       = (float)$i['video_height'  ] / 2;
				$trt_width    = (float)$p['video_width'   ] / 2;
				$trt_height   = (float)$p['video_height'  ] / 2;

				# make pixels square
				if( $ratio > 1.0 )
					$width = $height * $ratio;
				else
					$height = $width / $ratio;
				
				# calculate size to fit
				$ratio_w = $trt_width  / $width;
				$ratio_h = $trt_height / $height;

				if( $ratio_h > $ratio_w ) {
					$width  = (integer)$trt_width;
					$height = (integer)($width / $ratio);
				} else {
					$height = (integer)$trt_height;
					$width  = (integer)($height * $ratio);
				}

				# calculate padding
				$pad_top    = (integer)(($trt_height - $height + 1) / 2);
				$pad_left   = (integer)(($trt_width  - $width  + 1) / 2);
				$pad_bottom = (integer)($trt_height  - $height - $pad_top );
				$pad_right  = (integer)($trt_width   - $width  - $pad_left);

				# multiply by 2 to undo division and get multiples of 2
				$width      *= 2;
				$height     *= 2;
				$pad_top    *= 2;
				$pad_left   *= 2;
				$pad_bottom *= 2;
				$pad_right  *= 2;
				break;
		}
	}
	function create_log_file()
	{
		$file = $this->log_file;
		$data = $this->log;
		$fo = fopen($file,"w");
		if($fo)
		{
			fwrite($fo,$data);
		}
	}

	/**
	 * Function used to convert video 
	 */
	function convert($file=NULL,$for_iphone=false,$more_res=NULL)
	{
		global $width, $height;

		$TemplogData = "";
		
		$ratio = $this->ratio;
		if($file)
			$this->input_file = $file;

		$p = $this->options;
		$i = $this->input_details;

		$opt_av = '';
		logData($p,'checkpoints');
		# Prepare the ffmpeg command to execute
		if(isset($p['extra_options']))
			$opt_av .= " -y {$p['extra_options']} ";

		# file format
		if(isset($p['format']))
			$opt_av .= " -f {$p['format']} ";

		if($p['use_video_codec'])
		{
			# video codec
			if(isset($p['video_codec']))
				$opt_av .= " -vcodec ".$p['video_codec'];
			elseif(isset($i['video_codec']))
				$opt_av .= " -vcodec ".$i['video_codec'];
			if($p['video_codec'] == 'libx264')
			{
				if($p['normal_quality']!='hq')
					$opt_av .= " -preset medium ";
				else
					$opt_av .= " -preset slow -crf 26";
			}
		}

		# video rate
		if($p['use_video_rate'])
		{
			if(isset($p['video_rate']))
				$vrate = $p['video_rate'];
			elseif(isset($i['video_rate']))
				$vrate = $i['video_rate'];

			if(isset($p['video_max_rate']) && !empty($vrate))
				$vrate = min($p['video_max_rate'], $vrate);

			if(!empty($vrate))
				$opt_av .= " -r $vrate ";
		}

		# video bitrate
		if($p['use_video_bit_rate'])
		{
			if(isset($p['video_bitrate']))
				$vbrate = $p['video_bitrate'];
			elseif(isset($i['video_bitrate']))
				$vbrate = $i['video_bitrate'];

			if(!empty($vbrate))
				$vbrate = min(config('vbrate_'.$more_res['name']), $vbrate);

			if(!empty($vbrate))
				$opt_av .= ' -vb '.$vbrate.' ';
		}

		# video size, aspect and padding
		
		#create all possible resolutions of selected video
		if($more_res!=NULL)
		{
			$p['resize']='fit';
			$i['video_width']  = $more_res['video_width'];
			$i['video_height'] = $more_res['video_height'];

			$opt_av .= " -s ".$more_res['video_width']."x".$more_res['video_height']." -aspect $ratio ";
		} else {
			$this->calculate_size_padding( $p, $i );
			$opt_av .= " -s {$width}x{$height} -aspect $ratio ";
		}

		# audio codec, rate and bitrate
		if($p['use_audio_codec'])
		{
			if(!empty($p['audio_codec']) && $p['audio_codec'] != 'None'){
				$opt_av .= " -acodec {$p['audio_codec']}";
			}
		}
		
		# audio bitrate
		if($p['use_audio_bit_rate'])
		{
			if(isset($p['audio_bitrate']))
				$abrate = $p['audio_bitrate'];
			elseif(isset($i['audio_bitrate']))
				$abrate = $i['audio_bitrate'];

			if(!empty($abrate))
			{
				$abrate_cmd = " -ab ".$abrate;
				$opt_av .= $abrate_cmd;
			}
		}
		
		# audio bitrate
		if(!is_numeric($this->input_details['audio_rate']))
		{
			$opt_av .= "  ";
		} elseif($p['use_audio_rate']) {
			if(!$this->validChannels($this->input_details))
			{
				$arate = $i['audio_rate'];
				$opt_av .= $arate_cmd = " -ar $arate ";
			} else {
				if(isset($p['audio_rate']))
					$arate = $p['audio_rate'];
				elseif(isset($i['audio_rate']))
					$arate = $i['audio_rate'];
				if(!empty($arate))
					$opt_av .= $arate_cmd = " -ar $arate ";
			}
		}

		#audio track
		if( $this->audio_track && is_numeric($this->audio_track) && $this->audio_track > 0 ) // 0 = default, don't require to specify anything
		{
			// TODO : Specify track to use on conversion
		}

		if ($i['rotation'] != 0 )
		{
			if ($i['video_wh_ratio'] >= 1.6){
				$opt_av .= " -vf pad='ih*16/9:ih:(ow-iw)/2:(oh-ih)/2' ";
			} else {
				$opt_av .= " -vf pad='ih*4/3:ih:(ow-iw)/2:(oh-ih)/2' ";
			}
		}

		$tmp_file = time().RandomString(5).'.tmp';

		if(!$for_iphone)
		{
			$TemplogData .= "\r\nConverting Video file ".$more_res['name']." @ ".date("Y-m-d H:i:s")." \r\n";
			if($more_res==NULL){
				echo 'here';
			} else {
				#create all possible resolutions of selected video
				if( $more_res['name'] == '240' && $p['gen_240'] == 'yes' ||
					$more_res['name'] == '360' && $p['gen_360'] == 'yes' ||
					$more_res['name'] == '480' && $p['gen_480'] == 'yes' ||
					$more_res['name'] == '720' && $p['gen_720'] == 'yes' ||
					$more_res['name'] == '1080' && $p['gen_1080'] == 'yes')
				{
					$command  = $this->ffmpeg." -i ".$this->input_file." $opt_av ".$this->raw_path."-".$more_res['name'].".mp4  2> ".TEMP_DIR."/".$tmp_file;
				} else {
					$command = '';
					$resolution_error_log = array('err'=>'empty command');
					logData(json_encode($resolution_error_log),'resolution command');
				}
			}
			if($more_res!=NULL){
				$output = $this->exec($command);
			}
			if(file_exists(TEMP_DIR.'/'.$tmp_file)){
				$output = $output ? $output : join("", file(TEMP_DIR.'/'.$tmp_file));
				unlink(TEMP_DIR.'/'.$tmp_file);
			}

			if(file_exists($this->raw_path."-".$more_res['name'].".mp4") && filesize($this->raw_path."-".$more_res['name'].".mp4")>0)
			{
				$this->has_resolutions = 'yes';
				$this->video_files[] = $more_res['name'];
				$TemplogData .="\r\nFiles resolution : ".$more_res['name']." \r\n";
			} else {
				$TemplogData .="\r\n\r\nFile doesn't exist. Path: ".$this->raw_path."-".$more_res['name'].".mp4 \r\n\r\n";
			}

			$this->output_file = $this->raw_path."-".$more_res['name'].".mp4";
			  
			if($more_res!=NULL)
			{
				$TemplogData .= "\r\n\r\n== Conversion Command == \r\n\r\n";
				$TemplogData .= $command;
				$TemplogData .= "\r\n\r\n== Conversion OutPut == \r\n\r\n";
				$TemplogData .= $output;
			}

			#FFMPEG GNERETAES Damanged File (MP4Box)
			#Injecting MetaData using Mp4Box - you must have update version of Mp4Box ie 1.0.6 Final or greater

			if($more_res==NULL){
				echo 'here';
			} else {
				$resolution_log_data = array('file'=>$this->file_name,'more_res'=>$more_res,'command'=>'mp4box');
				logData(json_encode($resolution_log_data),'resolution command');
				#create all possible resolutions of selected video
				if( $more_res['name'] == '240' && $p['gen_240'] == 'yes' ||
					$more_res['name'] == '360' && $p['gen_360'] == 'yes' ||
					$more_res['name'] == '480' && $p['gen_480'] == 'yes' ||
				 	$more_res['name'] == '720' && $p['gen_720'] == 'yes' ||
					$more_res['name'] == '1080' && $p['gen_1080'] == 'yes')
				{
					$command = $this->mp4box." -inter 0.5 ".$this->raw_path."-".$more_res['name'].".mp4 -tmp ".$this->tmp_dir." 2> ".TEMP_DIR."/".$this->file_name."_mp4_output.tmp";
				} else {
					$command = '';
					$resolution_error_log = array('err'=>'empty command');
					logData(json_encode($resolution_error_log),'resolution command');
				}
			}

			if(file_exists(TEMP_DIR."/".$this->file_name."_mp4_output.tmp")){
				unlink(TEMP_DIR."/".$this->file_name."_mp4_output.tmp");
			}
			if($more_res!=NULL){
				$TemplogData .= "\n\n==  Mp4Box Command ==\n\n";
				$TemplogData .= $command;
				$TemplogData .= "\n\n==  MP4Box OutPut ==\n\n";
				$TemplogData .= $output;
			}
		}

		$TemplogData .="\r\n\r\nEnd resolutions @ ".date("Y-m-d H:i:s")."\r\n\r\n";
		$this->log->writeLine('Conversion Ouput', $TemplogData, true);

		$this->output_details = $this->get_file_info($this->output_file);
		$this->log_ouput_file_info();
	}

	/**
	 * Prepare file to be converted
	 * this will first get info of the file
	 * and enter its info into database
	 *
	 * @param null $file
	 */
	function prepare($file=NULL)
	{
		if($file)
			$this->input_file = $file;
			
		if(file_exists($this->input_file))
			$this->input_file = $this->input_file;
		else
			$this->input_file = TEMP_DIR.'/'.$this->input_file;

		//Checking File Exists
		if(!file_exists($this->input_file))
		{
			$this->log->writeLine('File Exists','No',true);
		}else{
			$this->log->writeLine('File Exists','Yes',true);
		}
		
		//Get File info
		$this->input_details = $this->get_file_info($this->input_file);
		//Logging File Details
		$this->log_file_info();

		//Get FFMPEG version
		$result = shell_output(FFMPEG_BINARY." -version");
		$version = parse_version('ffmpeg',$result);

		$this->vconfigs['map_meta_data'] = 'map_meta_data';
		
		if(strstr($version,'Git'))
		{
			$this->vconfigs['map_meta_data'] = 'map_metadata';
		}
	}

	private function getVideoDetails( $videoPath = false)
	{
		if($videoPath)
		{
			# init the info to N/A
			$info['format']			= 'N/A';
			$info['duration']		= 'N/A';
			$info['size']			= 'N/A';
			$info['bitrate']		= 'N/A';
			$info['video_width']	= 'N/A';
			$info['video_height']	= 'N/A';
			$info['video_wh_ratio']	= 'N/A';
			$info['video_codec']	= 'N/A';
			$info['video_rate']		= 'N/A';
			$info['video_bitrate']	= 'N/A';
			$info['video_color']	= 'N/A';
			$info['audio_codec']	= 'N/A';
			$info['audio_bitrate']	= 'N/A';
			$info['audio_rate']		= 'N/A';
			$info['audio_channels']	= 'N/A';
			$info['path'] = $videoPath;

			/*
				get the information about the file
				returns array of stats
			*/
			$stats = stat($videoPath);
			if($stats && is_array($stats))
			{
				$ffmpegOutput = $this->executeCommand( $this->ffMpegPath . " -i {$videoPath} -acodec copy -vcodec copy -y -f null /dev/null 2>&1" );
				$info = $this->parseVideoInfo($ffmpegOutput,$stats['size']);
				$info['size'] = (integer)$stats['size'];
				return $info;
			}
		}
		return false;
	}

	private function parseVideoInfo($output = "",$size=0)
	{
		# search the output for specific patterns and extract info
		# check final encoding message
		$info['size'] = $size;
		$audio_codec = false;

		if( $args = self::pregMatch( 'video:([0-9]+)kB audio:([0-9]+)kB global headers:[0-9]+kB muxing overhead', $output) ) {
			$video_size = (float)$args[1];
			$audio_size = (float)$args[2];
		}

		# check for last encoding update message
		if($args = self::pregMatch( '(frame=([^=]*) fps=[^=]* q=[^=]* L)?size=[^=]*kB time=([^=]*) bitrate=[^=]*kbits\/s[^=]*$', $output) ) {
			$frame_count = $args[2] ? (float)ltrim($args[2]) : 0;
		}

		$len = strlen($output);
		$findme = 'Duration';
		$findme1 = 'start';
		$pos = strpos($output, $findme);
		$pos = $pos + 10;
		$pos1 = strpos($output, $findme1);
		$bw = $len - ($pos1 - 5);
		$rest = substr($output, $pos, -$bw);

		$duration = explode(':',$rest);
		//Convert Duration to seconds
		$hours = $duration[0];
		$minutes = $duration[1];
		$seconds = $duration[2];
		
		$hours = $hours * 60 * 60;
		$minutes = $minutes * 60;

		$duration = $hours+$minutes+$seconds;

		$info['duration'] = $duration;
		if($duration)
		{
			$info['bitrate' ] = (integer)($info['size'] * 8 / 1024 / $duration);
			if( $frame_count > 0 )
				$info['video_rate']	= (float)$frame_count / (float)$duration;
			if( $video_size > 0 )
				$info['video_bitrate']	= (integer)($video_size * 8 / $duration);
			if( $audio_size > 0 )
				$info['audio_bitrate']	= (integer)($audio_size * 8 / $duration);
				# get format information
			if($args = self::pregMatch( "Input #0, ([^ ]+), from", $output) ) {
				$info['format'] = $args[1];
			}
		}

		# get video information
		if( $args = self::pregMatch( '([0-9]{2,4})x([0-9]{2,4})', $output ) )
		{
			$info['video_width'  ] = $args[1];
			$info['video_height' ] = $args[2];
			$info['video_wh_ratio'] = (float) $info['video_width'] / (float)$info['video_height'];
		}
		
		if($args = self::pregMatch('Video: ([^ ^,]+)',$output))
		{
			$info['video_codec'] = $args[1];
		}

		# get audio information
		if($args = self::pregMatch( "Audio: ([^ ]+), ([0-9]+) Hz, ([^\n,]*)", $output) )
		{
			$audio_codec = $info['audio_codec'] = $args[1];
			$audio_rate = $info['audio_rate'  ] = $args[2];
			$info['audio_channels'] = $args[3];
		}

		if((isset($audio_codec) && !$audio_codec) || !$audio_rate)
		{
			$args = self::pregMatch( "Audio: ([a-zA-Z0-9]+)(.*), ([0-9]+) Hz, ([^\n,]*)", $output);
			$info['audio_codec'   ] = $args[1];
			$info['audio_rate'    ] = $args[3];
			$info['audio_channels'] = $args[4];
		}

		return $info;
	}

	private static function pregMatch($in = false, $str = false)
	{
		if($in && $str)
		{
			preg_match("/$in/",$str,$args);
			return $args;
		}
		return false;
	}

	public function generateThumbs($array)
	{
		$input_file = $array['vid_file'];
		$duration = $array['duration'];
		$dim = $array['dim'];
		$num = $array['num'];
		if (!empty($array['size_tag'])){
			$size_tag= $array['size_tag'];
		}
		if (!empty($array['file_directory'])){
			$regenerateThumbs = true;
			$file_directory = $array['file_directory'];
		}
		if (!empty($array['file_name'])){
			$filename = $array['file_name'];
		}
		if (!empty($array['rand'])){
				$rand = $array['rand'];		
		}
		$tmpDir = TEMP_DIR.'/'.getName($input_file);

		/*
			The format of $this->options["outputPath"] should be like this
			year/month/day/ 
			the trailing slash is important in creating directories for thumbs
		*/
		if(substr($this->options["outputPath"], strlen($this->options["outputPath"]) - 1) !== "/"){
			$this->options["outputPath"] .= "/";
		}
		
		mkdir($tmpDir,0777);	

		$dimension = '';
		
		if(!empty($size_tag))
		{
			$size_tag = $size_tag.'-';
		}

		if (!empty($file_directory) && !empty($filename))
		{
			$thumbs_outputPath = $file_directory.'/';
		}else{
			$thumbs_outputPath = $this->options['outputPath'];
		}

		if($dim!='original'){
			$dimension = " -s $dim  ";
		}

		if($num > 1 && $duration > 14)
		{
			$duration = $duration - 5;
			$division = $duration / $num;
			$count=1;

			for($id=3;$id<=$duration;$id++)
			{
				if (empty($filename)){
					$file_name = getName($input_file)."-{$size_tag}{$count}.jpg";	
				}else{
					$file_name = $filename."-{$size_tag}{$count}.jpg";	
				}
				
				$file_path = THUMBS_DIR.'/' . $thumbs_outputPath . $file_name;
				$id	= $id + $division - 1;

				if($rand != "") {
					$time = $this->ChangeTime($id,1);
				} elseif($rand == "") {
					$time = $this->ChangeTime($id);
				}
				
				$command = $this->ffMpegPath." -ss {$time} -i $input_file -an -r 1 $dimension -y -f image2 -vframes 1 $file_path ";
				$output = $this->executeCommand($command);	

				//checking if file exists in temp dir
				if(file_exists($tmpDir.'/00000001.jpg'))
				{
					rename($tmpDir.'/00000001.jpg',THUMBS_DIR.'/'.$file_name);
				}
				$count = $count+1;
				if (!$regenerateThumbs)
				{
					$this->TemplogData .= "\r\n\r\n Command : $command ";
					$this->TemplogData .= "\r\n\r\n OutPut : $output ";	
					if (file_exists($file_path)){
						$output_thumb_file = $file_path;
					}else{
						$output_thumb_file = "Oops ! Not Found.. See log";						
					}
					$this->TemplogData .= "\r\n\r\n Response : $output_thumb_file ";	
				}
			}
		} else {
			if (empty($filename)){
				$file_name = getName($input_file)."-{$size_tag}1.jpg";	
			} else {
				$file_name = $filename."-{$size_tag}1.jpg";	
			}
			
			$file_path = THUMBS_DIR.'/' . $thumbs_outputPath . $file_name;
			$command = $this->ffMpegPath." -i $input_file -an $dimension -y -f image2 -vframes $num $file_path ";
			$output = $this->executeCommand($command);
			if (!$regenerateThumbs){
				$this->TemplogData .= "\r\n Command : $command ";
				$this->TemplogData .= "\r\n File : $file_path ";
			}
		}
		rmdir($tmpDir);
	}

	/**
	 * Function used to convert seconds into proper time format
	 *
	 * @param : INT duration
	 * @param : rand
	 * last edited : 23-12-2016
	 * edited by : Fahad Abbas
	 *
	 * @author : Fahad Abbas
	 * @edit_reason : date() function was used which was not a good approach
	 * @return bool|string
	 */
	private function ChangeTime($duration, $rand = "")
	{
		try{
			/*Formatting up the duration in seconds for datetime object*/
			/* desired format ( 00:00:00 ) */
			if (!empty($duration))
			{
				if($rand != "") {
					$init = $duration - rand(0,$duration);
				} else {
					$init = $duration;
				}

				$hours = floor($init / 3600);
				$minutes = floor(($init / 60) % 60);
				$seconds = $init % 60;
				$d_formatted = "$hours:$minutes:$seconds";
				$d = new DateTime($d_formatted);
				$time = $d->format("H:i:s");

				return $time;
			}
			return false;
		} catch (Exception $e){
			echo 'Caught exception: ',  $e->getMessage(), "\n";
		}
	}

	private function startLog($logFileName)
	{
		$this->logFile = $this->logDir . $logFileName . ".log";
		$log = new SLog();
		$this->logs = $log;
		$log->setLogFile($this->logFile);
	}

	function validChannels($in)
	{
		if(!$in)
			return true;
		$in['audio_channels'] = strtolower($in['audio_channels']);
		$channels = false;
		if(is_numeric($in['audio_channels']))
			$channels = $in['audio_channels'];
		else
		{
			if(strstr($in['audio_channels'],'stereo'))
				$channels = 2;
			
			if(strstr($in['audio_channels'],'mono'))
				$channels = 1;
	
			if(!$channels)
			{
				preg_match('/([0-9.]+)/',$in['audio_channels'],$matches);
				if($matches)
					$channels = $matches[1];
			}
		}
		
		if(!$channels)
			return true;
		if($channels>2)
			return false;
		return true;
	}


	public static function get_video_tracks($filepath)
	{
		$stats = stat($filepath);
		if($stats && is_array($stats))
		{
			$json = shell_exec(FFPROBE . ' -i "'.$filepath.'" -loglevel panic -select_streams a -of compact=p=0:nk=1 -print_format json -show_entries stream_tags=language,title 2>&1');
			$tracks_json = json_decode($json, true)['streams'];
			$langs = array();
			foreach($tracks_json as $track)
			{
				if( !isset($track['tags']) )
					continue;
				$track = $track['tags'];

				if( !isset($track['language']) && !isset($track['LANGUAGE']) )
					continue;
				if( isset($track['language']) )
					$language = $track['language'];
				else
					$language = $track['LANGUAGE'];

				if( $language == 'und' )
					continue;

				$title = '';
				if( isset($track['title']) )
					$title = $track['title'];

				if( !empty($title) )
				{
					$lang = $language.' : '.$title;
				} else {
					$lang = $language;
				}

				if( count($langs) == 0 )
					$lang .= ' (Default)';

				$langs[] = $lang;
			}
			return $langs;
		} else {
			return false;
		}
	}

	public static function get_video_basic_infos($filepath)
	{
		$stats = stat($filepath);
		if($stats && is_array($stats))
		{
			$json = shell_exec(FFPROBE. ' -v quiet -print_format json -show_format -show_streams "'.$filepath.'"');
			$data = json_decode($json,true);

			$video = NULL;
			foreach($data['streams'] as $stream)
			{
				if( $stream['codec_type'] == 'video' )
				{
					$video = $stream;
					break;
				}
			}

			if($video)
			{
				$info = array();
				$info['duration'] = SetTime($data['format']['duration']);
				$info['width']    = (int) $video['width'];
				$info['height']   = (int) $video['height'];
				return $info;
			} else {
				return array();
			}
		} else {
			return array();
		}
	}

}