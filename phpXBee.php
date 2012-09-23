<?php
/* 
* +--------------------------------------------------------------------------+
* | Copyright (c) 2012 Chris Barnes                                          |
* +--------------------------------------------------------------------------+
* | This program is free software; you can redistribute it and/or modify     |
* | it under the terms of the GNU General Public License as published by     |
* | the Free Software Foundation; either version 2 of the License, or        |
* | (at your option) any later version.                                      |
* |                                                                          |
* | This program is distributed in the hope that it will be useful,          |
* | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
* | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
* | GNU General Public License for more details.                             |
* |                                                                          |
* | You should have received a copy of the GNU General Public License        |
* | along with this program; if not, write to the Free Software              |
* | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA |
* +--------------------------------------------------------------------------+
*/

define ("SERIAL_DEVICE_NOTSET", 0);
define ("SERIAL_DEVICE_SET", 1);
define ("SERIAL_DEVICE_OPENED", 2);

/**
 * Serial port control class
 *
 * THIS PROGRAM COMES WITH ABSOLUTELY NO WARANTIES !
 * USE IT AT YOUR OWN RISKS !
 *
 * Changes added by Rizwan Kassim <rizwank@geekymedia.com> for OSX functionality
 * default serial device for osx devices is /dev/tty.serial for machines with a built in serial device
 *
 * @author Rémy Sanchez <thenux@gmail.com>
 * @thanks Aurélien Derouineau for finding how to open serial ports with windows
 * @thanks Alec Avedisyan for help and testing with reading
 * @thanks Jim Wright for OSX cleanup/fixes.
 * @copyright under GPL 2 licence
 */
class phpSerial
{
	var $_device = null;
	var $_windevice = null;
	var $_dHandle = null;
	var $_dState = SERIAL_DEVICE_NOTSET;
	var $_buffer = "";
	var $_os = "";

	/**
	 * This var says if buffer should be flushed by sendMessage (true) or manualy (false)
	 *
	 * @var bool
	 */
	var $autoflush = true;

	/**
	 * Constructor. Perform some checks about the OS and setserial
	 *
	 * @return phpSerial
	 */
	function phpSerial ()
	{
		setlocale(LC_ALL, "en_US");

		$sysname = php_uname();

		if (substr($sysname, 0, 5) === "Linux")
		{
			$this->_os = "linux";

			if($this->_exec("stty --version") === 0)
			{
				register_shutdown_function(array($this, "deviceClose"));
			}
			else
			{
				trigger_error("No stty availible, unable to run.", E_USER_ERROR);
			}
		}
		elseif (substr($sysname, 0, 6) === "Darwin")
		{
			$this->_os = "osx";
            // We know stty is available in Darwin. 
            // stty returns 1 when run from php, because "stty: stdin isn't a
            // terminal"
            // skip this check
//			if($this->_exec("stty") === 0)
//			{
				register_shutdown_function(array($this, "deviceClose"));
//			}
//			else
//			{
//				trigger_error("No stty availible, unable to run.", E_USER_ERROR);
//			}
		}
		elseif(substr($sysname, 0, 7) === "Windows")
		{
			$this->_os = "windows";
			register_shutdown_function(array($this, "deviceClose"));
		}
		else
		{
			trigger_error("Host OS is neither osx, linux nor windows, unable to run.", E_USER_ERROR);
			exit();
		}
	}

	//
	// OPEN/CLOSE DEVICE SECTION -- {START}
	//

	/**
	 * Device set function : used to set the device name/address.
	 * -> linux : use the device address, like /dev/ttyS0
	 * -> osx : use the device address, like /dev/tty.serial
	 * -> windows : use the COMxx device name, like COM1 (can also be used
	 *     with linux)
	 *
	 * @param string $device the name of the device to be used
	 * @return bool
	 */
	function deviceSet ($device)
	{
		if ($this->_dState !== SERIAL_DEVICE_OPENED)
		{
			if ($this->_os === "linux")
			{
				if (preg_match("@^COM(\d+):?$@i", $device, $matches))
				{
					$device = "/dev/ttyS" . ($matches[1] - 1);
				}

				if ($this->_exec("stty -F " . $device) === 0)
				{
					$this->_device = $device;
					$this->_dState = SERIAL_DEVICE_SET;
					return true;
				}
			}
			elseif ($this->_os === "osx")
			{
				if ($this->_exec("stty -f " . $device) === 0)
				{
					$this->_device = $device;
					$this->_dState = SERIAL_DEVICE_SET;
					return true;
				}
			}
			elseif ($this->_os === "windows")
			{
				if (preg_match("@^COM(\d+):?$@i", $device, $matches) and $this->_exec(exec("mode " . $device . " xon=on BAUD=9600")) === 0)
				{
					$this->_windevice = "COM" . $matches[1];
					$this->_device = "\\.\com" . $matches[1];
					$this->_dState = SERIAL_DEVICE_SET;
					return true;
				}
			}

			trigger_error("Specified serial port is not valid", E_USER_WARNING);
			return false;
		}
		else
		{
			trigger_error("You must close your device before to set an other one", E_USER_WARNING);
			return false;
		}
	}

	/**
	 * Opens the device for reading and/or writing.
	 *
	 * @param string $mode Opening mode : same parameter as fopen()
	 * @return bool
	 */
	function deviceOpen ($mode = "r+b")
	{
		if ($this->_dState === SERIAL_DEVICE_OPENED)
		{
			trigger_error("The device is already opened", E_USER_NOTICE);
			return true;
		}

		if ($this->_dState === SERIAL_DEVICE_NOTSET)
		{
			trigger_error("The device must be set before to be open", E_USER_WARNING);
			return false;
		}

		if (!preg_match("@^[raw]\+?b?$@", $mode))
		{
			trigger_error("Invalid opening mode : ".$mode.". Use fopen() modes.", E_USER_WARNING);
			return false;
		}

		$this->_dHandle = @fopen($this->_device, $mode);
		
		if ($this->_dHandle !== false)
		{
			stream_set_blocking($this->_dHandle, 0);
			$this->_dState = SERIAL_DEVICE_OPENED;
			return true;
		}

		$this->_dHandle = null;
		trigger_error("Unable to open the device", E_USER_WARNING);
		return false;
	}

	/**
	 * Closes the device
	 *
	 * @return bool
	 */
	function deviceClose ()
	{
		if ($this->_dState !== SERIAL_DEVICE_OPENED)
		{
			return true;
		}

		if (fclose($this->_dHandle))
		{
			$this->_dHandle = null;
			$this->_dState = SERIAL_DEVICE_SET;
			return true;
		}

		trigger_error("Unable to close the device", E_USER_ERROR);
		return false;
	}

	//
	// OPEN/CLOSE DEVICE SECTION -- {STOP}
	//

	//
	// CONFIGURE SECTION -- {START}
	//

	/**
	 * Configure the Baud Rate
	 * Possible rates : 110, 150, 300, 600, 1200, 2400, 4800, 9600, 38400,
	 * 57600 and 115200. Note there was a paramter added from original class.
	 *
	 * @param int $rate the rate to set the port in
	 * @return bool
	 */
	function confBaudRate ($rate)
	{
		if ($this->_dState !== SERIAL_DEVICE_SET)
		{
			trigger_error("Unable to set the baud rate : the device is either not set or opened", E_USER_WARNING);
			return false;
		}

		$validBauds = array (
			110    => 11,
			150    => 15,
			300    => 30,
			600    => 60,
			1200   => 12,
			2400   => 24,
			4800   => 48,
			9600   => 96,
			19200  => 19,
			38400  => 38400,
			57600  => 57600,
			115200 => 115200
		);

		if (isset($validBauds[$rate]))
		{
			if ($this->_os === "linux")
			{
				//note this is the only place modified from the original, I submitted a patch on Google Code for this change
				//See switches here: http://www.daemon-systems.org/man/stty.1.html
                $ret = $this->_exec("stty -F " . $this->_device . " raw speed -echo " . (int) $rate, $out);
            }
            if ($this->_os === "osx")
            {
                $ret = $this->_exec("stty -f " . $this->_device . " " . (int) $rate, $out);
            }
            elseif ($this->_os === "windows")
            {
                $ret = $this->_exec("mode " . $this->_windevice . " BAUD=" . $validBauds[$rate], $out);
            }
            else return false;

			if ($ret !== 0)
			{
				trigger_error ("Unable to set baud rate: " . $out[1], E_USER_WARNING);
				return false;
			}
		}
	}

	/**
	 * Configure parity.
	 * Modes : odd, even, none
	 *
	 * @param string $parity one of the modes
	 * @return bool
	 */
	function confParity ($parity)
	{
		if ($this->_dState !== SERIAL_DEVICE_SET)
		{
			trigger_error("Unable to set parity : the device is either not set or opened", E_USER_WARNING);
			return false;
		}

		$args = array(
			"none" => "-parenb",
			"odd"  => "parenb parodd",
			"even" => "parenb -parodd",
		);

		if (!isset($args[$parity]))
		{
			trigger_error("Parity mode not supported", E_USER_WARNING);
			return false;
		}

		if ($this->_os === "linux")
		{
			$ret = $this->_exec("stty -F " . $this->_device . " " . $args[$parity], $out);
		}
		elseif ($this->_os === "osx")
		{
			$ret = $this->_exec("stty -f " . $this->_device . " " . $args[$parity], $out);
		}
		else
		{
			$ret = $this->_exec("mode " . $this->_windevice . " PARITY=" . $parity{0}, $out);
		}

		if ($ret === 0)
		{
			return true;
		}

		trigger_error("Unable to set parity : " . $out[1], E_USER_WARNING);
		return false;
	}

	/**
	 * Sets the length of a character.
	 *
	 * @param int $int length of a character (5 <= length <= 8)
	 * @return bool
	 */
	function confCharacterLength ($int)
	{
		if ($this->_dState !== SERIAL_DEVICE_SET)
		{
			trigger_error("Unable to set length of a character : the device is either not set or opened", E_USER_WARNING);
			return false;
		}

		$int = (int) $int;
		if ($int < 5) $int = 5;
		elseif ($int > 8) $int = 8;

		if ($this->_os === "linux")
		{
			$ret = $this->_exec("stty -F " . $this->_device . " cs" . $int, $out);
		}
		elseif ($this->_os === "osx")
		{
			$ret = $this->_exec("stty -f " . $this->_device . " cs" . $int, $out);
		}
		else
		{
			$ret = $this->_exec("mode " . $this->_windevice . " DATA=" . $int, $out);
		}

		if ($ret === 0)
		{
			return true;
		}

		trigger_error("Unable to set character length : " .$out[1], E_USER_WARNING);
		return false;
	}

	/**
	 * Sets the length of stop bits.
	 *
	 * @param float $length the length of a stop bit. It must be either 1,
	 * 1.5 or 2. 1.5 is not supported under linux and on some computers.
	 * @return bool
	 */
	function confStopBits ($length)
	{
		if ($this->_dState !== SERIAL_DEVICE_SET)
		{
			trigger_error("Unable to set the length of a stop bit : the device is either not set or opened", E_USER_WARNING);
			return false;
		}

		if ($length != 1 and $length != 2 and $length != 1.5 and !($length == 1.5 and $this->_os === "linux"))
		{
			trigger_error("Specified stop bit length is invalid", E_USER_WARNING);
			return false;
		}

		if ($this->_os === "linux")
		{
			$ret = $this->_exec("stty -F " . $this->_device . " " . (($length == 1) ? "-" : "") . "cstopb", $out);
		}
		elseif ($this->_os === "osx")
		{
			$ret = $this->_exec("stty -f " . $this->_device . " " . (($length == 1) ? "-" : "") . "cstopb", $out);
		}
		else
		{
			$ret = $this->_exec("mode " . $this->_windevice . " STOP=" . $length, $out);
		}

		if ($ret === 0)
		{
			return true;
		}

		trigger_error("Unable to set stop bit length : " . $out[1], E_USER_WARNING);
		return false;
	}

	/**
	 * Configures the flow control
	 *
	 * @param string $mode Set the flow control mode. Availible modes :
	 * 	-> "none" : no flow control
	 * 	-> "rts/cts" : use RTS/CTS handshaking
	 * 	-> "xon/xoff" : use XON/XOFF protocol
	 * @return bool
	 */
	function confFlowControl ($mode)
	{
		if ($this->_dState !== SERIAL_DEVICE_SET)
		{
			trigger_error("Unable to set flow control mode : the device is either not set or opened", E_USER_WARNING);
			return false;
		}

		$linuxModes = array(
			"none"     => "clocal -crtscts -ixon -ixoff",
			"rts/cts"  => "-clocal crtscts -ixon -ixoff",
			"xon/xoff" => "-clocal -crtscts ixon ixoff"
		);
		$windowsModes = array(
			"none"     => "xon=off octs=off rts=on",
			"rts/cts"  => "xon=off octs=on rts=hs",
			"xon/xoff" => "xon=on octs=off rts=on",
		);

		if ($mode !== "none" and $mode !== "rts/cts" and $mode !== "xon/xoff") {
			trigger_error("Invalid flow control mode specified", E_USER_ERROR);
			return false;
		}

		if ($this->_os === "linux")
			$ret = $this->_exec("stty -F " . $this->_device . " " . $linuxModes[$mode], $out);
		elseif ($this->_os === "osx")
			$ret = $this->_exec("stty -f " . $this->_device . " " . $linuxModes[$mode], $out);
		else
			$ret = $this->_exec("mode " . $this->_windevice . " " . $windowsModes[$mode], $out);

		if ($ret === 0) return true;
		else {
			trigger_error("Unable to set flow control : " . $out[1], E_USER_ERROR);
			return false;
		}
	}

	/**
	 * Sets a setserial parameter (cf man setserial)
	 * NO MORE USEFUL !
	 * 	-> No longer supported
	 * 	-> Only use it if you need it
	 *
	 * @param string $param parameter name
	 * @param string $arg parameter value
	 * @return bool
	 */
	function setSetserialFlag ($param, $arg = "")
	{
		if (!$this->_ckOpened()) return false;

		$return = exec ("setserial " . $this->_device . " " . $param . " " . $arg . " 2>&1");

		if ($return{0} === "I")
		{
			trigger_error("setserial: Invalid flag", E_USER_WARNING);
			return false;
		}
		elseif ($return{0} === "/")
		{
			trigger_error("setserial: Error with device file", E_USER_WARNING);
			return false;
		}
		else
		{
			return true;
		}
	}

	//
	// CONFIGURE SECTION -- {STOP}
	//

	//
	// I/O SECTION -- {START}
	//

	/**
	 * Sends a string to the device
	 *
	 * @param string $str string to be sent to the device
	 * @param float $waitForReply time to wait for the reply (in seconds)
	 */
	function sendMessage ($str, $waitForReply = 0.1)
	{
		$this->_buffer .= $str;

		if ($this->autoflush === true) $this->serialflush();

		usleep((int) ($waitForReply * 1000000));
	}

	/**
	 * Reads the port until no new datas are availible, then return the content.
	 *
	 * @param int $count number of characters to be read (will stop before
	 * 	if less characters are in the buffer)
	 * @return string
	 */
	function readPort ($count = 0)
	{
		if ($this->_dState !== SERIAL_DEVICE_OPENED)
		{
			trigger_error("Device must be opened to read it", E_USER_WARNING);
			return false;
		}

		if ($this->_os === "linux" || $this->_os === "osx")
			{
			// Behavior in OSX isn't to wait for new data to recover, but just grabs what's there!
			// Doesn't always work perfectly for me in OSX
			$content = ""; $i = 0;

			if ($count !== 0)
			{
				do {
					if ($i > $count) $content .= fread($this->_dHandle, ($count - $i));
					else $content .= fread($this->_dHandle, 128);
				} while (($i += 128) === strlen($content));
			}
			else
			{
				do {
					$content .= fread($this->_dHandle, 128);
				} while (($i += 128) === strlen($content));
			}

			return $content;
		}
		elseif ($this->_os === "windows")
		{
			// Windows port reading procedures still buggy
			$content = ""; $i = 0;

			if ($count !== 0)
			{
				do {
					if ($i > $count) $content .= fread($this->_dHandle, ($count - $i));
					else $content .= fread($this->_dHandle, 128);
				} while (($i += 128) === strlen($content));
			}
			else
			{
				do {
					$content .= fread($this->_dHandle, 128);
				} while (($i += 128) === strlen($content));
			}

			return $content;
		}

		return false;
	}

	/**
	 * Flushes the output buffer
	 * Renamed from flush for osx compat. issues
	 *
	 * @return bool
	 */
	function serialflush ()
	{
		if (!$this->_ckOpened()) return false;

		if (fwrite($this->_dHandle, $this->_buffer) !== false)
		{
			$this->_buffer = "";
			return true;
		}
		else
		{
			$this->_buffer = "";
			trigger_error("Error while sending message", E_USER_WARNING);
			return false;
		}
	}

	//
	// I/O SECTION -- {STOP}
	//

	//
	// INTERNAL TOOLKIT -- {START}
	//

	function _ckOpened()
	{
		if ($this->_dState !== SERIAL_DEVICE_OPENED)
		{
			trigger_error("Device must be opened", E_USER_WARNING);
			return false;
		}

		return true;
	}

	function _ckClosed()
	{
		if ($this->_dState !== SERIAL_DEVICE_CLOSED)
		{
			trigger_error("Device must be closed", E_USER_WARNING);
			return false;
		}

		return true;
	}

	function _exec($cmd, &$out = null)
	{
		$desc = array(
			1 => array("pipe", "w"),
			2 => array("pipe", "w")
		);

		$proc = proc_open($cmd, $desc, $pipes);

		$ret = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$retVal = proc_close($proc);

		if (func_num_args() == 2) $out = array($ret, $err);
		return $retVal;
	}

	//
	// INTERNAL TOOLKIT -- {STOP}
	//
}

/**
 * XBee represents an XBee connection
 * 
 * Be sure to configure the serial connection first.
 * If on linux run these commands at the shell first:
 * 
 * Add apache user to dialout group:
 * sudo adduser www-data dialout
 * 
 * Restart Apache:
 * sudo /etc/init.d/apache2 restart
 * 
 * THIS PROGRAM COMES WITH ABSOLUTELY NO WARANTIES !
 * USE IT AT YOUR OWN RISKS !
 * @author Chris Barnes
 * @thanks Rémy Sanchez, Aurélien Derouineau and Alec Avedisyan for the original serial class
 * @copyright GPL 2 licence
 * @package phpXBee
 */
class XBee extends phpSerial {
	/**
	 * Constructor. Parent is phpSerial
	 *
	 * @return Xbee
	 */
	function XBee() {
		parent::phpSerial();
	}

	/**
	 * Sets up typical Connection 9600 8-N-1
	 * 
	 * @param String $device is the path to the xbee, defaults to /dev/ttyUSB0
	 * @return void
	 */
	public function confDefaults($device = '/dev/ttyUSB0') {
		$this -> deviceSet($device);
		$this -> confBaudRate(9600);
		$this -> confParity('none');
		$this -> confCharacterLength(8);
		$this -> confStopBits(1);
		$this -> confFlowControl('none');
	}
	
	/**
	 * Opens this XBee connection. 
	 * 
	 * Note that you can send raw serial with sendMessage from phpSerial
	 * @return void
          * @param $waitForOpened int amount to sleep after openeing in seconds. Defaults to 0.1
	 */
	public function open($waitForOpened=0.1) {
		$this -> deviceOpen();
                  usleep((int) ($waitForOpened * 1000000));
	}

	/**
	 * Closes this XBee connection
	 * @return void
	 */
	public function close() {
		$this -> deviceClose();
	}
	
	/**
	 * Sends an XBee frame. $waitForReply is how long to wait on recieving
	 * 
	 * @param XBeeFrame $frame
	 * @param int $waitForRply
	 * @return void
	 */
	public function send($frame , $waitForReply=0.1) {
		$this -> sendMessage($frame -> getFrame(), $waitForReply);		
		//echo 'Sent: ';print_r(unpack('H*', $frame -> getFrame()));	//debug
	}

	/**
	 * Reads the XBee until no new data is availible, then returns the content.
	 * Note that the return is an array of XBeeResponses
	 *
	 * @param int $count number of characters to be read (will stop before
	 * 	if less characters are in the buffer)
	 * @return Array $XBeeResponse
	 */
	public function recieve($count = 0) {
		$rawResponse = $this -> readPort($count);
		$rawResponse = unpack('H*', $rawResponse);
		$response = explode('7e', $rawResponse[1]);
		//echo ' responseArr:';print_r($response);	//debug

		for ($i=1; $i < count($response); $i++) { 
			$response[$i] = new XBeeResponse($response[$i]);
		}
		return $response;
	}
}

/**
 * XbeeFrameBase represents common functions for all types of frames
 * 
 * @package XBeeFrameBase
 * @subpackage XBeeFrame
 * @subpackage XBeeResponse
 */
abstract class _XBeeFrameBase {
	const DEFAULT_START_BYTE = '7E', DEFAULT_FRAME_ID = '01', 
		REMOTE_API_ID = '17', LOCAL_API_ID = '08',
		QUEUED_API_ID = '09', TX_API_ID = '10', TX_EXPLICIT_API_ID = '11';
		
	protected $frame, $frameId, $apiId, $cmdData, $startByte, $address16, $address64, $options, $cmd, $val;
	
	/**
	 * Contructor for abstract class XbeeFrameBase.
	 *
	 */
	protected function _XBeeFrameBase() {
		$this -> setStartByte(_XBeeFrameBase::DEFAULT_START_BYTE);
		$this -> setFrameId(_XBeeFrameBase::DEFAULT_FRAME_ID);
	}
	
	/**
	 * Assembles frame after all values are set
	 *
	 * @return void
	 */
	protected function _assembleFrame() {
		$this -> setFrame(
					$this -> getStartByte() . 
					$this -> _getFramelength($this -> getCmdData()) . 
					$this -> getCmdData() . 
					$this -> _calcChecksum($this -> getCmdData())
					);
		//echo 'Assembled: ';print_r($this -> _unpackBytes($this -> getFrame()));	//debug
	}
	
	/**
	 * Calculates checksum for cmdData. Leave off start byte, length and checksum
	 * 
	 * @param String $data Should be a binary string
	 * @return String $checksum Should be a binary string
	 */
	protected function _calcChecksum($data) {
		$checksum = 0;
		for ($i = 0; $i < strlen($data); $i++) {
			$checksum += ord($data[$i]);
		}
		$checksum = $checksum & 0xFF;
		$checksum = 0xFF - $checksum;
		$checksum = chr($checksum);
		return $checksum;
	}
	
	/**
	 * Calculates lenth for cmdData. Leave off start byte, length and checksum
	 * 
	 * @param String $data Should be a binary string
	 * @return String $length Should be a binary string
	 */
	protected function _getFramelength($data) {
		$length = strlen($data);
		$length = sprintf("%04x", $length);
		$length = $this -> _packBytes($length);
		return $length;
	}
	
	/**
	 * Transforms hex into a string
	 * 
	 * @param String $hex
	 * @return String $string Should be a binary string
	 */
	protected function _hexstr($hex) {
		$string = '';
		for ($i=0; $i < strlen($hex); $i+=2) {
			$string .= chr(hexdec($hex[$i] . $hex[$i+1]));
		}
		return $string;
	}
	
	/**
	 * Transforms string into hex
	 * 
	 * @param String $str Should be a binary string
	 * @return String $hex Sould be a hex string
	 */
	protected function _strhex($str) {
		$hex = '';
	    for ($i=0; $i < strlen($str); $i+=2) {
	        $hex .= dechex(ord($str[$i])) . dechex(ord($str[$i+1]));
	    }
	    return $hex;
	}
	
	/**
	 * Packs a string into binary for sending
	 * 
	 * @param String $data
	 * @return String $data Should be a binary string
	 */
	protected function _packBytes($data) {
		return pack('H*', $data);
	}

	/**
	 * Unpacks bytes into an array
	 * 
	 * @param String $data Should be a binary string
	 * @return Array $data
	 */
	protected function _unpackBytes($data) {
		return unpack('H*', $data);
	}
	
	/**
	 * Sets raw frame, including start byte etc
	 * 
	 * @param String $frame
	 * @return void
	 */
	public function setFrame($frame) {
		$this -> frame = $frame;
	}
	
	/**
	 * Gets raw frame data
	 * 
	 * @return String $FrameData
	 */
	public function getFrame() {
		return $this -> frame;
	}

	/**
	 * Sets FrameId according to XBee API
	 * 
	 * @param String $frameId
	 * @return void
	 */
	public function setFrameId($frameId) {
		$this -> frameId = $frameId;
	}

	/**
	 * Gets frame ID according to XBee API
	 * 
	 * @return String $frameId
	 */
	public function getFrameId() {
		return $this -> frameId;
	}

	/**
	 * Sets ApiId according to XBee API
	 * 
	 * @param String $apiId
	 */
	public function setApiId($apiId) {
		$this -> apiId = $apiId;
	}
	
	/**
	 * Gets API ID
	 * 
	 * @return String $apiId
	 */
	public function getApiId() {
		return $this -> apiId;
	}

	/**
	 * Sets raw command data, without start byte etc
	 * 
	 * @param String $cmdData
	 * @return void
	 */
	public function setCmdData($cmdData) {
		$this -> cmdData = $this -> _packBytes($cmdData);
	}

	/**
	 * Gets raw command data, without start byte etc
	 * 
	 * @return String $cmdData
	 */
	public function getCmdData() {
		return $this -> cmdData;
	}

	/**
	 * Sets Start Byte according to XBee API, defaults to 7E
	 * 
	 * @param String $startByte
	 */
	public function setStartByte($startByte) {
		$this -> startByte = $this -> _packBytes($startByte);
	}
	 
	 /**
	 * Gets Start Byte according to XBee API, default is 7E
	  * 
	 * @return String $startByte
	 */
	public function getStartByte() {
		return $this -> startByte;
	}
	
	/**
	 * Sets the 16 bit address
	 * 
	 * @param String $address16
	 */
	public function setAddress16($address16) {
		$this->address16 = $address16;
	}
	
	/**
	 * Gets the 16 bit address
	 * 
	 * @return String $address16
	 */
	public function getAddress16() {
		return $this->address16;
	}
	
	/**
	 * Sets the 64 bit address
	 * 
	 * @param String $address64
	 */
	public function setAddress64($address64) {
		$this->address64 = $address64;
	}
	
	/**
	 * Gets the 64 bit address
	 * 
	 * @param String $address64
	 */
	public function getAddress64() {
		return $this->address64;
	}
	
	/**
	 * Sets the options of the frame
	 * 
	 * @param String $options
	 */
	public function setOptions($options) {
		$this->options = $options;
	}
	
	/**
	 * Gets the options of the frame
	 * 
	 * @return String $options
	 */
	public function getOptions() {
		return $this->options;
	}
	
	/**
	 * Sets the command
	 * 
	 * @param String $cmd
	 */
	public function setCmd($cmd) {
		$this -> cmd = $cmd;		
	}
	
	/**
	 * Gets the command
	 * 
	 * @return String $cmd
	 */
	public function getCmd() {
		return $this -> cmd;
	}
	
	/**
	 * Sets the value of a packet
	 * 
	 * @param String $val
	 */
	public function setValue($val) {
		$this -> val = $val;
	}
	
	/**
	 * Gets value of value
	 * 
	 * @return String $val
	 */
	public function getValue() {
		return $this -> val;
	}
}

/**
 * XbeeFrame represents a frame to be sent.
 *
 * @package XBeeFrame
 */
class XBeeFrame extends _XBeeFrameBase {
	public function XBeeFrame() {
		parent::_XBeeFrameBase();
	}

	/**
	 * Represesnts a remote AT Command according to XBee API. 
	 * 64 bit address defaults to eight 00 bytes and $options defaults to 02 immediate
	 * Assembles frame for sending.
	 * 
	 * @param $address16, $cmd, $val, $address64, $options
	 * @return void
	 */
	public function remoteAtCommand($address16, $cmd, $val, $address64 = '0000000000000000', $options = '02') {
		$this -> setApiId(_XBeeFrameBase::REMOTE_API_ID);
		$this -> setAddress16($address16);
		$this -> setAddress64($address64);
		$this -> setOptions($options);
		$this -> setCmd($this -> _strhex($cmd));
		$this -> setValue($val);
		
		$this -> setCmdData(
					$this -> getApiId() . 
					$this -> getFrameId() . 
					$this -> getAddress64() .
					$this ->getAddress16() . 
					$this -> getOptions() .
					$this -> getCmd() .
					$this -> getValue()
					);
		$this -> _assembleFrame();
	}
	
	/**
	 * Represesnts a local AT Command according to XBee API. 
	 *  Takes command and value, value defaults to nothing
	 * 
	 * @param String $cmd, String $val
	 * @return void
	 */
	public function localAtCommand($cmd, $val = '') {
		$this -> setApiId(_XBeeFrameBase::LOCAL_API_ID);
		$this -> setCmd($this ->_strhex($cmd));
		$this -> setCmdData(
						$this -> getApiId() . 
						$this -> getFrameId() .
						$this -> getCmd() .
						$this -> getValue()
						);
		$this -> _assembleFrame();
	}

	/**
	 *  Not Implemented, do not use
	 */
	public function queuedAtCommand() {
		$this -> setApiId(_XBeeFrameBase::QUEUED_API_ID);
		trigger_error('queued_at not implemented', E_USER_ERROR);
	}

	/**
	 *  Not Implemented, do not use
	 */
	public function txCommand() {
		$this -> setApiId(_XBeeFrameBase::TX_API_ID);
		trigger_error('tx not implemented', E_USER_ERROR);
	}

	/**
	 *  Not Implemented, do not use
	 */
	public function txExplicityCommand() {
		$this -> setApiId(_XBeeFrameBase::TX_EXPLICIT_API_ID);
		trigger_error('tx_explicit not implemented', E_USER_ERROR);
	}

}

/**
 * XBeeResponse represents a response to a frame that has been sent.
 *
 * @package XBeeResponse
 */
class XBeeResponse extends _XBeeFrameBase {
	const REMOTE_RESPONSE_ID = '97', LOCAL_RESPONSE_ID = '88';
	protected $address16, $address64, $status, $cmd, $nodeId, $signalStrength;
	protected $status_bytes = array();
	
	/**
	 * Constructor.  Sets up an XBeeResponse
	 * 
	 * @param String $response A single frame of response from an XBee
	 */
	public function XBeeResponse($response) {
		parent::_XBeeFrameBase();
		
		$this->status_byte = array('00' => 'OK','01' => 'Error','02'=> 'Invalid Command', '03' => 'Invalid Parameter', '04' => 'No Response' );
		$this -> _parse($response);
		
		if ($this -> getApiId() === XBeeResponse::REMOTE_RESPONSE_ID) {
			$this -> _parseRemoteAt();
		} else if ($this -> getApiId() === XBeeResponse::LOCAL_RESPONSE_ID) {
			$this -> _parseLocalAt();
		} else {
			trigger_error('Could not determine response type or response type is not implemented.', E_USER_WARNING);
		}
		/* debug 
		echo '</br>';echo 'Response:';print_r($response);echo '</br>';
		echo ' apiId:';print_r($this->getApiId());echo '</br>';echo ' frameId:';print_r($this->getFrameId());echo '</br>';
		echo ' add64:';print_r($this->getAddress64());echo '</br>';echo ' add16:';print_r($this->getAddress16());echo '</br>';
		echo ' DB:';print_r($this->getSignalStrength());echo '</br>';echo ' NI:';print_r($this->getNodeId());echo '</br>';
		echo ' CMD:';print_r($this->getCmd());echo '</br>';echo ' Status:';print_r($this->getStatus());echo '</br>';
		echo ' isOk:';print_r($this->isOk());echo '</br>';*/
	}
	
	/**
	 * Parses the command data from the length and checksum
	 * 
	 * @param String $response A XBee frame response from an XBee
	 * @return void
	 */
	private function _parse($response) {
		$length = substr($response, 0, 4);
		$checksum = substr($response, -2);
		$cmdData = substr($response, 4, -2);
		$apiId = substr($cmdData, 0, 2);
		$frameId = substr($cmdData, 2, 2);
		$calculatedChecksum = $this -> _calcChecksum($this -> _packBytes($cmdData));
		$calculatedLength = $this -> _getFramelength($this -> _packBytes($cmdData));
		
		$packedChecksum = $this->_packBytes($checksum);	//pack for comparison
		$packedLength = $this->_packBytes($length);	//pack for comparison

		if ($packedChecksum === $calculatedChecksum && $packedLength === $calculatedLength) {
			$this -> setApiId($apiId);
			$cmdData = $this->_unpackBytes($cmdData);
			$cmdData=$cmdData[1];
			$this -> setCmdData($cmdData);
			$this -> setFrameId($frameId);
			$this -> setFrame($response);
		} else {
			trigger_error('Checksum or length check failed.', E_USER_WARNING);
		}
	}
	
	/**
	 * Parses remote At command
	 * 
	 * @return void
	 */
	private function _parseRemoteAt() {
		//A valid remote frame looks like this:
		//<apiId1> <frameId1> <address64,8> <address16,8> <command,2> <status,2>
		
		$cmdData = $this->getCmdData();
		
		$cmd = substr($cmdData, 24, 4);
		$cmd = $this->_hexstr($cmd);
		
		$frameId = substr($cmdData, 2, 2);
		$status = substr($cmdData, 4, 2);
		$address64 = substr($cmdData, 4, 16);
		$address16 = substr($cmdData, 20, 4);
		$signalStrength = substr($cmdData, 30, 2);
		
		$this->_setSignalStrength($signalStrength);
		$this->setAddress16($address16);
		$this->setAddress64($address64);
		$this->_setCmd($cmd);
		$this->_setStatus($status);
		$this->setFrameId($frameId);	
	}
	
	/**
	 * Parses a Local At Command response
	 * 
	 * @return void
	 */
	private function _parseLocalAt() {
		//A valid local frame looks like this:
		//<api_id1> <frameId1> <command2> <status2> <add16> <add64> <DB> <NI> <NULL>
		$cmdData = $this->getCmdData();
		
		$cmd = substr($cmdData, 4, 6);
		$cmd = $this->_hexstr($cmd);
		$frameId = substr($cmdData, 2, 2);
		$status = substr($cmdData, 8, 2);
		$address64 = substr($cmdData, 14, 16);
		$address16 = substr($cmdData, 10, 4);
		$signalStrength = substr($cmdData, 30, 2);
		$nodeId = $this->_hexstr(substr($cmdData, 32, -2));
		
		$this -> _setNodeId($nodeId);
		$this->_setSignalStrength($signalStrength);
		$this->setAddress16($address16);
		$this->setAddress64($address64);
		$this->_setCmd($cmd);
		$this->_setStatus($status);
		$this->setFrameId($frameId);
	}
	
	/**
	 * Gets signal strength in dB
	 * 
	 * @return String $signalStrength
	 */
	public function getSignalStrength() {
		return $this -> signalStrength;
	}
	
	/**
	 * Sets signal strength
	 * 
	 * @param String $strength
	 */
	private function _setSignalStrength($strength) {
		$this->signalStrength = $strength;
	}
	
	/**
	 * Gets Node ID aka NI
	 * 
	 * @return String $nodeId
	 */
	public function getNodeId() {
		return $this->nodeId;
	}
	
	/**
	 * Sets Node ID aka NI
	 * 
	 * @param String $nodeId
	 */
	private function _setNodeId($nodeId) {
		$this->nodeId = $nodeId;
	}
	
	/**
	 * Sets status
	 * 
	 * @param int $status
	 */
	private function _setStatus($status) {
		$this->status = $status;
	}
	
	/**
	 * Returns status. If you want boolean use isOk
	 * 
	 * 00 = OK
	 * 01 = Error
	 * 02 = Invalid Command
	 * 03 = Invalid Parameter
	 * 04 = No Response
	 * 
	 * @return int $status 
	 */
	public function getStatus() {
		return $this->status;
	}
	
	/**
	 * Checks if this resonse was positive
	 * 
	 * @return boolean
	 */
	public function isOk() {
		if ($this->getStatus()=='00') {
			return TRUE;
		} else {
			return FALSE;	
		}
	}
	
	/**
	 * Sets the command for this frame
	 * 
	 * @return void
	 * @param String $cmd The Xbee Command 
	 */
	private function _setCmd($cmd) {
		$this->cmd = $cmd;
	}
	
	/**
	 * Returns command. 
	 * 
	 * @return String $cmd
	 */
	public function getCmd() {
		return $this->cmd;
	}
}
?>
