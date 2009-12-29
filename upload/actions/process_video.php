<?php
/**
 * File use to process video files
 *
 * @Author : Arslan Hassan
 * @Software : ClipBucket
 * @License : CBLA
 * @Since : July 3 2009
 */

include(dirname(__FILE__)."/../includes/config.inc.php");


//Get vid
$vid = $_SERVER['argv'][1];

//Check video exists or not

if($myquery->video_exists($vid))
{
	//Get Video Details
	//$vdetails = $myquery->get_video_details($vid);
	$Upload->do_after_video_upload($vid);

}else{
	e("Video doesn't exist");
}
?>