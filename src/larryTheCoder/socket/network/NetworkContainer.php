<?php /** @noinspection PhpArrayUsedOnlyForWriteInspection */
/*
 * PHP Secure Socket Client
 *
 * Copyright (C) 2020 larryTheCoder
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types = 1);

namespace larryTheCoder\socket\network;

use Closure;
use larryTheCoder\socket\packets\protocol\DragonNetProtocol;
use pocketmine\MemoryManager;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\MainLogger;
use pocketmine\utils\TextFormat;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Throwable;
use const pocketmine\DATA;

/**
 * A representation of the DragonNetwork client packets handler in PHP.
 * Uses React/Socket framework for better and easy stream handling.
 *
 * <p>Think of this way, this class is a thread-blocking operation and
 * the other stuff after {@see NetworkContainer::run()} will be executed after this
 * operation has been completed. Therefore, we can conclude that, this is an isolated
 * execution from {@see \Threaded} in {@see NetworkThread}.
 *
 * <p>The reason behind this implementation is that:
 *     1. Threaded members will be serialized while this class will not
 *     2. It is easy to manage its variables and functions calls.
 *     3. It is a thread-safe operation.
 *
 * <p>I would personally say that this class is a docker container for PHP thread
 * and its parallel execution will only execute this part of the code. This API-level
 * only handles packet buffers and doesn't handle encoded/decoded packets.
 *
 * @package larryTheCoder\socket\network
 */
class NetworkContainer {

	/** @var StreamSelectLoop */
	private StreamSelectLoop $streamLoop;
	/** @var NetworkThread */
	private NetworkThread $thread;
	/** @var array */
	private array $settings;

	/** @var TimerInterface|null */
	private ?TimerInterface $writeTimer = null;
	/** @var TimerInterface|null */
	private ?TimerInterface $reconnectTask = null;

	/** @var Closure|null */
	private ?Closure $socketWrite = null;
	/** @var ConnectionInterface|null */
	private ?ConnectionInterface $connection = null;

	/** @var string[] */
	private array $recvQueue = [];
	/** @var string[] */
	private array $sendQueue = [];
	/** @var string[] */
	private array $waitingQueue = [];

	/**
	 * @param NetworkThread $thread
	 * @param array $settings
	 */
	public function __construct(NetworkThread $thread, array $settings){
		$this->streamLoop = new StreamSelectLoop();
		$this->thread = $thread;
		$this->settings = $settings;
	}

	public function run(): void{
		$this->initConnection();

		// Thread cycle ticks goes here by the way, this timer were used to check
		// If the server has closed so that the socket can be closed gracefully.

		$this->streamLoop->addPeriodicTimer(0.01, function(): void{
			if(!$this->thread->isRunning){
				$this->disconnect();

				$this->streamLoop->stop();
			}elseif($this->thread->dumpMemory){
				$dumpPath = "/memory_dumps/NetContainer-" . date("D_M_j-H.i.s-T_Y");

				MainLogger::getLogger()->info("[DragonNet] " . TextFormat::RED . "Attempting to dump memory into $dumpPath...");

				MemoryManager::dumpMemory($this, DATA . $dumpPath, 128, 512, MainLogger::getLogger());

				$this->thread->dumpMemory = false;
			}else{
				// This will accumulate outboundQueue with the packets that will be required to sent.
				// Until the socket has successfully being connected, the packets will be queued into order.
				if($this->thread->isConnected()){
					$this->sendQueue = array_merge($this->sendQueue, $this->waitingQueue);
					$this->waitingQueue = [];

					$this->thread->handleServerPacket($this->recvQueue, $this->sendQueue);
				}else{
					$sendQueue = [];
					$this->thread->handleServerPacket($this->recvQueue, $sendQueue);

					$this->waitingQueue = array_merge($sendQueue, $this->sendQueue, $this->waitingQueue);
					$this->sendQueue = [];
				}
			}
		});

		// Garbage collection thingy task, not sure if this is a requirement but lets be safe here.
		// This timer will do its garbage collection thingy every 30 minutes.

		gc_enable();
		$this->streamLoop->addPeriodicTimer(1800, function(): void{
			gc_enable();
			gc_collect_cycles();
			gc_mem_caches();
		});

		$this->streamLoop->run();
	}

	/**
	 * Establish a connection to the remote server.
	 */
	private function initConnection(): void{
		if($this->thread->connected){
			return;
		}

		$this->thread->reconnecting = true;
		$connection = new Connector($this->streamLoop, [
			'tls' => [
				'cafile'            => $this->thread->getCertificatePath(),
				'allow_self_signed' => true,
				'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
			],
		]);

		// Needless to say, connection to the server always have their SSL certificates turned on.
		$connection->connect("tls://" . $this->settings['server-socket'] . ":" . $this->settings['server-port'])->then(function(ConnectionInterface $connection): void{
			$this->socketWrite = $this->getWriteClosure($connection);

			$connection->on('data', $this->getReadClosure());
			$connection->on('close', $this->getCloseClosure());

			$this->writeTimer = $this->streamLoop->addPeriodicTimer(0.01, function() use ($connection): void{
				foreach(array_merge($this->sendQueue, $this->waitingQueue) as $packet){
					if(Binary::readInt($packet) === DragonNetProtocol::KEEP_ALIVE_PACKET && !$this->thread->connected){
						continue;
					}

					($this->socketWrite)($packet);
				}

				$this->sendQueue = [];
				$this->waitingQueue = [];

				$this->thread->connected = true;
				$this->thread->reconnecting = false;
			});

			$this->connection = $connection;

			$this->thread->notifyMainThread();

			MainLogger::getLogger()->info("[DragonNet] " . TextFormat::GREEN . "Successful connection to the remote server.");
		}, function(Throwable $err): void{
			$this->thread->reconnecting = false;

			if(strpos($err->getMessage(), 'Connection refused') !== false){
				$this->startReconnectTimer();
			} else {
				MainLogger::getLogger()->logException($err);
			}
		});
	}

	/**
	 * Attempt to re-establish a connection to the remote server.
	 */
	private function startReconnectTimer(): void{
		if($this->reconnectTask !== null || !$this->thread->isRunning){
			return;
		}

		$stage = 0;
		$stages = [3, 5, 8, 16, 32, 51, 60];
		$timeEstimated = $stages[$stage];

		MainLogger::getLogger()->info("[DragonNet] " . TextFormat::RED . "DragonNetwork Server unexpectedly closed the socket, reconnecting.");

		$this->reconnectTask = $this->streamLoop->addPeriodicTimer(1, function() use ($stages, &$stage, &$timeEstimated): void{
			if($this->thread->connected){
				$this->streamLoop->cancelTimer($this->reconnectTask);
				$this->reconnectTask = null;

				return;
			}

			if($this->thread->reconnecting) return;
			if($timeEstimated-- <= 0){
				$this->initConnection();

				$timeEstimated = $stages[$stage++] ?? $stages[6];

				MainLogger::getLogger()->info("[DragonNet] " . TextFormat::RED . "Attempting to reschedule reconnection task in {$timeEstimated}s. (Attempt $stage)");
			}
		});
	}

	public function disconnect(){
		if($this->connection !== null){
			$this->connection->end();
			$this->connection->close();

			$this->connection = null;
		}
	}

	private function getCloseClosure(): Closure{
		return function(): void{
			$this->streamLoop->cancelTimer($this->writeTimer);
			$this->writeTimer = null;

			$this->startReconnectTimer();

			$this->thread->connected = false;
		};
	}

	//////////////////////////////////////// MEMORY-SAFE TASKS AND CLOSURES ////////////////////////////////////////

	private function getReadClosure(): Closure{
		/** @var BinaryStream|null $packetBuilder */
		$packetBuilder = null;
		$bytesToRead = 0;
		$queue = &$this->recvQueue;

		return static function($data) use (&$queue, &$bytesToRead, &$packetBuilder): void{
			// Discard any empty data, the documentation said that this data can return empty
			// data so we do not want to process that thing here.
			if(empty($data)) return;

			// FRAGMENTATION SCENARIOS:
			// "Payload" . "Payload"
			// "Payload" . "Fragmented Payload"
			// "Fragmented Payload"
			// "Fragmented Payload" . "Payload"
			// "Fragmented Payload" . "Fragmented Payload" . "..."
			if($packetBuilder === null){
				$packetBuilder = new BinaryStream($data);

				decodeFragments:
				$packetLength = $packetBuilder->getInt();

				$bytesRecv = strlen(substr($packetBuilder->getBuffer(), $packetBuilder->getOffset()));
				$bytesToRead = $packetLength - $bytesRecv;
				if($bytesToRead === 0){
					// The length is exactly the same as the received bytes
					$payload = $packetBuilder->get($packetLength);
				}elseif($bytesToRead > 0){
					// There is some payload left that we need to receive.
					$payload = $packetBuilder->get(true);
				}else{
					// Rewind received payload to match packet length.
					$payload = $packetBuilder->get($bytesRecv + $bytesToRead);
				}

				if($bytesToRead <= 0){
					$queue[] = $payload;

					// The bytes that was suppose to be read was too much, it means that
					// the packet fragmented into the same payload
					if($bytesToRead < 0){
						$packetBuilder = new BinaryStream($packetBuilder->getRemaining());

						goto decodeFragments;
					}else{
						$packetBuilder = null;
					}
				}else{
					$packetBuilder->rewind();
				}
			}else{
				$packetLength = $packetBuilder->getInt();
				$bytesToRead = $bytesToRead - strlen($data);

				$packetBuilder->put($data);
				if($bytesToRead <= 0){
					$queue[] = $packetBuilder->get($packetLength);

					if(!$packetBuilder->feof()){
						$packetBuilder = new BinaryStream($packetBuilder->getRemaining());

						goto decodeFragments;
					}
				}else{
					$packetBuilder->rewind();
				}
			}
		};
	}

	private function getWriteClosure(ConnectionInterface $connection): Closure{
		return static function(string $data) use ($connection): void{
			$data = Binary::writeInt(strlen($data)) . $data;

			$connection->write($data);
		};
	}
	//////////////////////////////////////// MEMORY-SAFE TASKS AND CLOSURES ////////////////////////////////////////

}