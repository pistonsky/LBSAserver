<?php

/**
 * Simple implementation of HTML5 WebSocket server-side.
 *
 * PHP versions 5
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    WebSocket
 * @author     George Nava <georgenava@gmail.com>
 * @author     Vincenzo Ferrari <wilk3ert@gmail.com>
 * @copyright  2010-2011
 * @license    http://www.gnu.org/licenses/gpl.txt GNU GPLv3
 * @version    1.1.0
 * @link       http://code.google.com/p/phpwebsocket/
 */
define("STATE_WAITING_FOR_CMD",0);
	/**
	 * @usage $master = new StreamSocketServer ("localhost", 12345);
	 */
	class StreamSocketServer {
		var $master;
		var $sockets = array ();
		var $users = array ();
		// true to debug
		var $debug = true;
		// frame mask
		var $masks;
		// initial frames
		var $initFrame;
		// database handle and settings
		var $DBHandle;

		function __construct ($address, $port) {
			error_reporting (E_ALL);
			set_time_limit (0);
			ob_implicit_flush ();

			// database handle and settings
			try {
				$this->DBHandle = new PDO('mysql:host=localhost;port=5432;dbname=app_service', 'app_service', 'jBM45Epqxf5tJb6r', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
			} catch (PDOException $e) {
				echo 'Connection failed: ' . $e->getMessage();
			}
			
			// Socket creation
			$this->master = stream_socket_server("tcp://{$address}:{$port}", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
			$this->sockets[] = $this->master;
			$this->say ("Server Started : " . date ('Y-m-d H:i:s'));
			$this->say ("Listening on   : {$address} {$port}");
			$this->say ("Master socket  : {$this->master}\n");
			
			// Main loop
			while (true) {
				$changed = $this->sockets;
				stream_select ($changed, $write = NULL, $except = NULL, NULL);
				
				foreach ($changed as $socket) {
					if ($socket == $this->master) {
						$client = stream_socket_accept ($this->master);
						
						if ($client == FALSE) {
							$this->log ("stream_socket_accept() failed");
							continue;
						}
						else {
							// Connects the socket
							$this->connect ($client);
						}
					}
					else {
						$buffer = fread ($socket, 2048);
						if (strlen($buffer) == 0) {
							// On socket.close ();
							$this->disconnect ($socket);
						}
						else {
							// Retrieve the user from his socket
							$user = $this->getuserbysocket ($socket);
							
							$this->process ($user, $buffer);
						}
					}
				}
			}
		}

		/**
		 * @brief Echo incoming messages back to the client
		 * @note Extend and modify this method to suit your needs
		 * @param $user {User} : owner of the message
		 * @param $msg {String} : the message to echo
		 * @return void
		 */
		function process ($user, $msg) {
			$this->log ("Processing incoming message from $user->socket");
			$this->send ($user->socket, $msg);
		}

		/**
		 * @brief Send a message to a client
		 * @param $client {Socket} : socket to send the message
		 * @param $msg {String} : the message to send
		 * @return void
		 */
		function send ($client, $msg) {
			$this->say ("> {$msg}");
			$bytes = fwrite ($client, $msg);
			$this->log ("Sent $bytes bytes");
			//fclose ($client);
		}

		/**
		 * @brief Broadcast a message to all sockets
		 * @param $msg {String} : the message to broadcast
		 * @return void
		 */
		function broadcast ($msg) {
			$this->say ("broadcasting > {$msg}");
			foreach ($this->sockets as $socket)
				fwrite ($socket, $msg);
		}

		/**
		 * @brief Broadcast a message to all sockets
		 * @param $msg {String} : the message to broadcast
		 * @return void
		 */
		function tellothers ($user,$msg) {
			$this->say ("$user->id to others: {$msg}");
			foreach ($this->sockets as $socket)
				if ($socket != $user->socket) fwrite ($socket, $msg);
		}

		/**
		 * @brief Connect a new client (socket)
		 * @param $socket {Socket} : socket to connect
		 * @return void
		 */
		function connect ($socket) {
			
			$this->log ("{$socket} CONNECTED!");
			$this->log (date ("d/n/Y ") . "at " . date ("H:i:s T"));
			
			// включаем SSL-шифрование
			$this->log ("Trying to read login...");
			$login = fread($socket,6); // сразу получаем логин
			if ($login !== FALSE) {
				$this->log ("OK. Login is $login. Checking if we have such login in the database...");
				$sql = "SELECT pem_passphrase, pem_file FROM servers WHERE domain='$login'"; // запрос в базу app_service - таблица servers, узнаём ключ шифрования
				$result = $this->query($sql);
				if (!empty($result[0])) {
					
					$this->log ("Yes, we have. Passphrase is ".$result[0]['pem_passphrase']);
					
					$this->log ("Setting up SSL...");
					#Setup the SSL Options
					stream_context_set_option($socket, 'ssl', 'local_cert', $result[0]['pem_file']);		// Our SSL Cert in PEM format
					stream_context_set_option($socket, 'ssl', 'passphrase', $result[0]['pem_passphrase']);	// Private key Password
					stream_context_set_option($socket, 'ssl', 'verify_host', true);
					stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
					stream_context_set_option($socket, 'ssl', 'verify_peer', true);
					stream_context_set_option($socket, 'ssl', 'cafile', $result[0]['pem_file']);
					stream_context_set_option($socket, 'ssl', 'capath', dirname($result[0]['pem_file']).'/');
					
					#start SSL on the connection
					stream_set_blocking ($socket, true); // block the connection until SSL is done.
					stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_SSLv3_SERVER);
					#unblock connection
					stream_set_blocking ($socket, false);
					
					$this->log ("SSL established.");
					
					$user = new User ();
					$user->id = uniqid ();
					$user->socket = $socket;
					$user->domain = $login;
					$user->state = STATE_WAITING_FOR_CMD;
					
					array_push ($this->users, $user);
					array_push ($this->sockets, $socket);
				}
			} else {
				$this->log ("Failed! This is not our client server.");
			}
		}

		/**
		 * @brief Функция исполняет запрос и возвращает массив с поименованными столбцами.
		 */
		function query($sql)
		{
			$data = $this->DBHandle->query($sql);
			if (is_object($data))
				return $data->fetchAll();
		}

		/**
		 * @brief Disconnect a client (socket)
		 * @param $socket {Socket} : socket to disconnect
		 * @return void
		 */
		function disconnect ($socket) {
			$found = null;
			$n = count ($this->users);
			
			// Finds the right user index from the given socket
			for ($i = 0; $i < $n; $i++) {
				if ($this->users[$i]->socket == $socket) {
					$found = $i;
					break;
				}
			}
			
			if (!is_null ($found)) {
				array_splice ($this->users, $found, 1);
			}
			
			$index = array_search ($socket, $this->sockets);
			fclose ($socket);
			$this->log ("{$socket} DISCONNECTED!");
			
			if ($index >= 0) {
				array_splice ($this->sockets, $index, 1);
			}
		}

		/**
		 * @brief Retrieve an user from his socket
		 * @param $socket {Socket} : socket of the user to search
		 * @return User or null
		 */
		function getuserbysocket ($socket) {
			$found = null;
			
			foreach ($this->users as $user) {
				if ($user->socket == $socket) {
					$found = $user;
					break;
				}
			}
			
			return $found;
		}
		
		/**
		 * @brief Local echo messages
		 * @param $msg {String} : message to echo
		 * @return void
		 */
		function say ($msg = "") {
			echo "{$msg}\n";
		}
		
		/**
		 * @brief Log function
		 * @param $msg {String} : message to log
		 * @return void
		 */
		function log ($msg = "") {
			if ($this->debug) {
				echo "{$msg}\n";
			}
		}
	}

	/**
	 * @brief Класс User представляет сервер клиента
	 * Фактически - это оболочка для сокета, на котором стоит соединение с сервером клиента
	 * в переменной $domain хранится доменное имя, использованное в качестве логина при установлении SSL-соединения
	 */
	class User {
		var $id;
		var $socket;
		var $domain; // доменное имя
		var $state; // текущий статус - может быть либо "ожидание команды", либо "ожидание данных"
		var $buffer;
	}
?>
