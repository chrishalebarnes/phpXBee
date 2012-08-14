<?php
    require 'phpXBee.php';
	
	$xbeeFrameRemote = new XBeeFrame();
	$xbeeFrameRemote -> remoteAtCommand('5678' , 'D0' , '04');
	
	$xbeeFrameLocal= new XBeeFrame();
	$xbeeFrameLocal -> localAtCommand('ND');
	
	$xbee = new XBee();
	$xbee->confDefaults();
	$xbee->open();

	$xbee->send($xbeeFrameRemote);
	$remoteResponse = $xbee->recieve();	
	
	$xbee->send($xbeeFrameLocal,3);
	$localResponse = $xbee->recieve();
	
	$xbee->close();
	die();
?>