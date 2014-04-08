#!/usr/bin/env php
<?php
/**
 * Location Based Social App, server side script
 *
 * Author: Aleksandr Tsygankov <tsygankov.aleksandr@gmail.com>.
 *
 */

// database connection settings
define("DB_NAME","app");
define("DB_USER","app_service");
define("DB_PASSWORD","7Yt2J6fVGDKc6qV8");
define("DB_HOST","localhost");
define("DB_PORT","5432");

define("READ_BUFFER_SIZE_FOR_APPS", 64*1024);

error_reporting(E_ALL);

$ip="80.78.247.202";		// TCP IP address to listen on
// these ports should be allowed through the firewall
$app_port = "8099";		// TCP port for android app connections

require "JAXL/jaxl.php";
require_once 'JAXL/core/jaxl_logger.php'; // class for logging purposes
require "streamsocketserver.class.php";

JAXLLogger::$max_log_size = 64*1024;

// -d is for displaying debug info in terminal
if(!in_array("-d", $argv))
{
    $baseDir = dirname(__FILE__);
	$filename = basename(__FILE__,".php");
    ini_set('error_log',$baseDir.'/error.log');
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
    $STDIN = fopen('/dev/null', 'r');
    $STDOUT = fopen($baseDir.'/'.$filename.'.log', 'ab');
    $STDERR = fopen($baseDir.'/'.$filename.'_daemon.log', 'ab');
	JAXLLogger::$path = $baseDir.'/'.$filename.'.log';
}
// -full is for extra debug output
if (in_array("-full", $argv)) {
	define("DEBUG", TRUE);
} else {
	define("DEBUG", FALSE);
}

	/**
	 * AppService class extends base StreamSocketServer class with following functionalities:
	 * 1) Completely overrides constructor, using JAXL to manage main loop
	 * 2) Listens for incoming connections on a port from androids
	 * 3) Processes incoming messages from androids
	 */
	class AppService extends StreamSocketServer {
		
		var $address, $app_port;
		var $app_master; // special socket for incoming connections from android cleint apps
		
		function __construct ($address, $app_port) {
			$this->address = $address;
			$this->app_port = $app_port;
			
			error_reporting (E_ALL);
			set_time_limit (0);
			ob_implicit_flush ();

			// database handle and settings
			
			try {
				$this->DBHandle = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME, DB_USER, DB_PASSWORD, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
			} catch (PDOException $e) {
				echo 'Connection failed: ' . $e->getMessage();
			}
			
			// Socket for android apps
			if ($this->app_master = stream_socket_server("tcp://{$this->address}:{$this->app_port}", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN)) {
				$this->sockets[] = $this->app_master;
				$this->say ("Server Started 	: " . date ('Y-m-d H:i:s'));
				$this->say ("Listening on   	: {$this->address} {$this->app_port}");
				$this->say ("App Master socket  : {$this->app_master}\n");
				JAXLLoop::watch($this->app_master,array(
					'read' => array(&$this,'on_app_master_select')
				));
			} else {
				die("Could not start the server on {$this->address}:{$this->app_port}\nError: $errstr\n");
			}
			JAXLLoop::run();
		}

		/**
		 * Accepts socket connection from android app
		 * Calls connect_app() for further processing
		 */
		function on_app_master_select($fd) {
			$client = stream_socket_accept ($this->app_master);
			
			if ($client == FALSE) {
				_error ("stream_socket_accept() failed");
				continue;
			}
			else {
				// Connects the socket
				$this->connect_app ($client);
			}
		}
		
		/**
		 * Creates a new User object for newly connected android app
		 * Adds socket to watch list with on_app_slave_select() as a processing method
		 */
		function connect_app ($socket) {
			$regId = fread($socket,8096);
			if ($regId !== FALSE) if (strlen($regId) == 37) {
				_info ("Android app connected. regId: $regId");
				
				$user = new User ();
				$user->id = $regId;
				$user->socket = $socket;
				
				array_push ($this->users, $user);
				array_push ($this->sockets, $socket);
				
				JAXLLoop::watch($socket,array(
					'read' => array(&$this,'on_app_slave_select')
				));
			}
		}

		/**
		 * Read data from app socket, separate, and call process function
		 */
		function on_app_slave_select($socket) {
			$buffer = fread ($socket, READ_BUFFER_SIZE_FOR_APPS);
			if (strlen($buffer) == 0) {
				$this->disconnect ($socket);
			} else {
				$user = $this->getuserbysocket ($socket);
				$this->process_app_message ($user, $buffer);
			}
		}
		
		/**
		 * Process message from app
		 * Message types:
		 *		SALT
		 *		LOGOUT
		 *		LOGIN
		 *		POST
		 */		
		function process_app_message($user, $buffer) {
			foreach (explode(PHP_EOL,$buffer) as $buf) {
				$data = json_decode(htmlspecialchars_decode($buf),true); // true - we want array, not object
				$regId = $user->id;
				if (isset($data['data'])) if (isset($data['data']['cmd'])) {
					$message_id = $data['message_id'];
					$this->send_ack($regId,$message_id); // we got the message. Don't need to resend it
					$cmd = $data['data']['cmd'];
					_debug( 'Received a command from a device: '.$cmd );
					switch ($cmd) {
						/**
						 * SALT request is sent when user has just opened the app and is about to log in
						 * We have to check if the device is already logged in
						 * If so, we just send LOGIN event with a status of LOGGED_IN, we also specify login
						 * If the device is not logged in,
						 * we have to generate random salt, store it in devices table (using regId) and then send it back to the device
						 */
						case "SALT":
							// device is logged in if its regId exists in devices table and it has a valid user_id
							$sql = "SELECT user_id,login FROM devices,users WHERE devices.id='$regId' AND devices.user_id=users.id";
							$result = $this->query($sql);
							if (empty($result)) {
								$salt = uniqid(mt_rand(), true); // generate a good salt
								$sql = "INSERT INTO devices (id,salt) VALUES ('$regId','$salt') ON DUPLICATE KEY UPDATE salt='$salt'";
								$this->query($sql);
								$this->send_to_device($regId,array(
										'event'=>'SALT',
										'salt'=>$salt,
									));
							} else {
								$login = $result[0]['login'];
								$this->send_to_device($regId,array(
										'event'=>'LOGIN',
										'status'=>'LOGGED_IN',
										'login'=>$login,
									));
								if (DEBUG) _notice("The device is already logged in, login: $login");
							}
							break;
						/**
						 * LOGOUT
						 * Clear user_id value for current device in devices table
						 * Generate a new salt and send it
						 */
						case "LOGOUT":
							$salt = uniqid(mt_rand(), true); // generate a good salt
							$sql = "INSERT INTO devices (id,salt) VALUES ('$regId','$salt') ON DUPLICATE KEY UPDATE user_id=NULL,salt='$salt'";
							$this->query($sql);
							$this->send_to_device($regId,array('event'=>'LOGOUT','salt'=>$salt));
							break;
						/**
						 * LOGIN request is sent when user is done entering credentials and app has received salt in previous SALT request
						 * app should encrypt credentials with aquired salt and send them in this LOGIN request
						 */
						case "LOGIN":
							$hashed_salted_credentials = $data['data']['md5'];
							if (DEBUG) _notice( "MD5 from android app = ".$hashed_salted_credentials );
							$login = $data['data']['login'];
							if (DEBUG) _notice( "login: $login" );
		
							// Get the hashed credentials from database
							$sql = "SELECT id,hash FROM users WHERE login='$login'";
							$result = $this->query($sql);
							if (empty($result)) {
								if (DEBUG) _notice("There is no record in database for such login");
								$new_salt = uniqid(mt_rand(), true); // generate new salt for the next login attempt
								$sql = "INSERT INTO devices (id,salt) VALUES ('$regId','$new_salt') ON DUPLICATE KEY UPDATE user_id=NULL,salt='$new_salt'";
								$this->query($sql);
								$this->send_to_device($regId,array('event'=>'LOGIN','status'=>'NO_SUCH_LOGIN','login'=>$login,'salt'=>$new_salt));
							} else foreach ($result as $row) {
								if (DEBUG) _notice("Found one match. Correct hashed credentials: ".$row['hash']);
								$correct_hashed_credentials = $row['hash'];
								$sql = "SELECT salt FROM devices WHERE id='$regId'";
								$result = $this->query($sql);
								if (isset($result[0])) {
									$salt = $result[0]['salt'];
									$correct_hashed_salted_credentials = md5( $salt."_".$correct_hashed_credentials."_".$salt );
									if (DEBUG) _notice("Correct hashed salted credentials: ".$correct_hashed_salted_credentials);
									if ($hashed_salted_credentials == $correct_hashed_salted_credentials) {
										if (DEBUG) _notice("Hashes match! Successfully logged in.");
										// bind this device to this user through creating a record in devices table
										$user_id = $row['id'];
										$sql = "INSERT INTO devices (id,user_id) VALUES ('$regId',$user_id) ON DUPLICATE KEY UPDATE user_id=$user_id";
										$this->query($sql);
										// the password is OK - go ahead and authenticate the user
										$this->send_to_device($regId,array(
												'event'=>'LOGIN',
												'status'=>'LOGGED_IN',
												'login'=>$login,
											));
									} else {
										_notice("Incorrect password.");
										$new_salt = uniqid(mt_rand(), true); // generate new salt for the next login attempt
										$sql = "INSERT INTO devices (id,salt) VALUES ('$regId','$new_salt') ON DUPLICATE KEY UPDATE user_id=NULL,salt='$new_salt'";
										$this->query($sql);
										$this->send_to_device($regId,array(
												'event'=>'LOGIN',
												'status'=>'INCORRECT_PASSWORD',
												'login'=>$login,
												'salt'=>$new_salt
											));
									}
								}
							}
							break;
						case "POST":
							$message = $data['data']['message'];
							_info("Message received: $message");
							$latitude = $data['data']['latitude'];
							_info("Latitude: $latitude");
							$longitude = $data['data']['longitude'];
							_info("Longitude: $longitude");
							// save this message into the database, public messages table
							$sql = "INSERT INTO messages_public (regId,message,latitude,longitude) VALUES ('$regId','$message','$latitude','$longitude')";
							$this->query($sql);
							_debug("Message was saved into messages_public table.");
							break;
					}
				}
			}
		}

		/**
		 * Send a message to a certain device
		 * $regId - the value of User::id field, same as id field of devices table
		 * $data - array, should contain event key
		 */
		function send_to_device($regId,$data) {
			$message_id = mt_rand();
			$raw_data = json_encode(
				array(
					'message_id'=>'m-'.$message_id,
					'data'=>$data,
				)
			);
			$socket = NULL;
			foreach ($this->users as $user) {
				if ($user->id == $regId) $socket = $user->socket;
			}
			if ($socket) {
				_notice("Sending: $raw_data");
				fputs($socket,$raw_data.PHP_EOL); // TODO: remember that we have sent the message, but we also have to check if the other side has really received it
			}
		}
		
		/**
		 * Send a message to all registered online apps for the specified $domain
		 */
		function broadcast_to_devices($data) {
				$sql = "SELECT devices.id AS regId FROM devices,users WHERE devices.user_id=users.id";
				$result = $this->query($sql);
				if (is_array($result)) {
					foreach ($result as $row) {
						$this->send_to_device($row['regId'],$data);
					}
				}
		}
		
		/**
		 * Sends ack message (delivery report) to the specified app
		 * So that app does not send the same message again
		 */
		function send_ack($regId,$message_id) {
			$socket = NULL;
			foreach ($this->users as $user) {
				if ($user->id == $regId) $socket = $user->socket;
			}
			if ($socket) {
				fputs($socket,json_encode(array(
					'message_id'=>$message_id,
					'message_type'=>"ack"
				)).PHP_EOL);
			}
		}
		
	}
	
// TODO: Add database CREATE TABLE clauses.

$App_Service = new AppService($ip,$app_port);
?>
