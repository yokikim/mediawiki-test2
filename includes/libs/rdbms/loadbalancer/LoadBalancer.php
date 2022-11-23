<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */
namespace Wikimedia\Rdbms;

use ArrayUtils;
use BagOStuff;
use EmptyBagOStuff;
use InvalidArgumentException;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use LogicException;
use NullStatsdDataFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use WANObjectCache;
use Wikimedia\RequestTimeout\CriticalSectionProvider;
use Wikimedia\ScopedCallback;

/**
 * @see ILoadBalancer
 * @ingroup Database
 */
class LoadBalancer implements ILoadBalancerForOwner {
	/** @var ILoadMonitor */
	private $loadMonitor;
	/** @var CriticalSectionProvider|null */
	private $csProvider;
	/** @var callable|null Callback to run before the first connection attempt */
	private $chronologyCallback;
	/** @var BagOStuff */
	private $srvCache;
	/** @var WANObjectCache */
	private $wanCache;
	/** @var DatabaseFactory */
	private $databaseFactory;
	/**
	 * @var callable|null An optional callback that returns a ScopedCallback instance,
	 * meant to profile the actual query execution in {@see Database::doQuery}
	 */
	private $profiler;
	/** @var TransactionProfiler */
	private $trxProfiler;
	/** @var StatsdDataFactoryInterface */
	private $statsd;
	/** @var LoggerInterface */
	private $connLogger;
	/** @var LoggerInterface */
	private $queryLogger;
	/** @var LoggerInterface */
	private $replLogger;
	/** @var LoggerInterface */
	private $perfLogger;
	/** @var callable Exception logger */
	private $errorLogger;
	/** @var callable Deprecation logger */
	private $deprecationLogger;

	/** @var DatabaseDomain Local DB domain ID and default for new connections */
	private $localDomain;

	/** @var Database[][][] Map of (pool category => server index => Database[]) */
	private $conns;

	/** @var string|null The name of the DB cluster */
	private $clusterName;
	/** @var array[] Map of (server index => server config array) */
	private $servers;
	/** @var array[] Map of (group => server index => weight) */
	private $groupLoads;
	/** @var int Seconds to spend waiting on replica DB lag to resolve */
	private $waitTimeout;
	/** @var array The LoadMonitor configuration */
	private $loadMonitorConfig;
	/** @var int */
	private $maxLag;
	/** @var string|null Default query group to use with getConnection() */
	private $defaultGroup;

	/** @var bool Whether this PHP instance is for a CLI script */
	private $cliMode;
	/** @var string Agent name for query profiling */
	private $agent;

	/** @var array[] $aliases Map of (table => (dbname, schema, prefix) map) */
	private $tableAliases = [];
	/** @var string[] Map of (index alias => index) */
	private $indexAliases = [];
	/** @var DatabaseDomain[]|string[] Map of (domain alias => DB domain) */
	private $domainAliases = [];
	/** @var callable[] Map of (name => callable) */
	private $trxRecurringCallbacks = [];
	/** @var bool[] Map of (domain => whether to use "temp tables only" mode) */
	private $tempTablesOnlyMode = [];

	/** @var string|false Explicit DBO_TRX transaction round active or false if none */
	private $trxRoundId = false;
	/** @var string Stage of the current transaction round in the transaction round life-cycle */
	private $trxRoundStage = self::ROUND_CURSORY;
	/** @var int[] The group replica server indexes keyed by group */
	private $readIndexByGroup = [];
	/** @var DBPrimaryPos|false Replication sync position or false if not set */
	private $waitForPos;
	/** @var bool Whether the generic reader fell back to a lagged replica DB */
	private $laggedReplicaMode = false;
	/** @var string|false Reason this instance is read-only or false if not */
	private $readOnlyReason = false;
	/** @var int Total number of new connections ever made with this instance */
	private $connectionCounter = 0;
	/** @var bool */
	private $disabled = false;
	/** @var bool Whether the session consistency callback already executed */
	private $chronologyCallbackTriggered = false;

	/** @var Database|null The last connection handle that caused a problem */
	private $lastErrorConn;

	/** @var DatabaseDomain[] Map of (domain ID => domain instance) */
	private $nonLocalDomainCache = [];

	/**
	 * @var int Modification counter for invalidating connections held by
	 *      DBConnRef instances. This is bumped by reconfigure().
	 */
	private $modcount = 0;

	/** IDatabase handle LB info key; the "server index" of the handle */
	private const INFO_SERVER_INDEX = 'serverIndex';
	/** IDatabase handle LB info key; whether the handle belongs to the auto-commit pool */
	private const INFO_AUTOCOMMIT_ONLY = 'autoCommitOnly';

	/**
	 * Default 'maxLag' when unspecified
	 * @internal Only for use within LoadBalancer/LoadMonitor
	 */
	public const MAX_LAG_DEFAULT = 6;

	/** Warn when this many connection are held */
	private const CONN_HELD_WARN_THRESHOLD = 10;

	/** Default 'waitTimeout' when unspecified */
	private const MAX_WAIT_DEFAULT = 10;
	/** Seconds to cache primary DB server read-only status */
	private const TTL_CACHE_READONLY = 5;

	/** @var string Key to the pool of transaction round connections */
	private const POOL_ROUND = 'round';
	/** @var string Key to the pool of auto-commit connections */
	private const POOL_AUTOCOMMIT = 'auto-commit';

	/** Transaction round, explicit or implicit, has not finished writing */
	private const ROUND_CURSORY = 'cursory';
	/** Transaction round writes are complete and ready for pre-commit checks */
	private const ROUND_FINALIZED = 'finalized';
	/** Transaction round passed final pre-commit checks */
	private const ROUND_APPROVED = 'approved';
	/** Transaction round was committed and post-commit callbacks must be run */
	private const ROUND_COMMIT_CALLBACKS = 'commit-callbacks';
	/** Transaction round was rolled back and post-rollback callbacks must be run */
	private const ROUND_ROLLBACK_CALLBACKS = 'rollback-callbacks';
	/** Transaction round encountered an error */
	private const ROUND_ERROR = 'error';

	/** @var int Idiom for getExistingReaderIndex() meaning "no index selected" */
	private const READER_INDEX_NONE = -1;

	public function __construct( array $params ) {
		$this->configure( $params );

		$this->conns = self::newTrackedConnectionsArray();
	}

	/**
	 * @param array $params A database configuration array, see $wgLBFactoryConf.
	 *
	 * @return void
	 */
	protected function configure( array $params ): void {
		if ( !isset( $params['servers'] ) || !count( $params['servers'] ) ) {
			throw new InvalidArgumentException( 'Missing or empty "servers" parameter' );
		}

		$localDomain = isset( $params['localDomain'] )
			? DatabaseDomain::newFromId( $params['localDomain'] )
			: DatabaseDomain::newUnspecified();
		$this->setLocalDomain( $localDomain );

		$this->maxLag = $params['maxLag'] ?? self::MAX_LAG_DEFAULT;

		$listKey = -1;
		$this->servers = [];
		$this->groupLoads = [ self::GROUP_GENERIC => [] ];
		foreach ( $params['servers'] as $i => $server ) {
			if ( ++$listKey !== $i ) {
				throw new UnexpectedValueException( 'List expected for "servers" parameter' );
			}
			$this->servers[ $i ] = $server;
			foreach ( ( $server['groupLoads'] ?? [] ) as $group => $ratio ) {
				$this->groupLoads[ $group ][ $i ] = $ratio;
			}
			$this->groupLoads[ self::GROUP_GENERIC ][ $i ] = $server['load'];
		}

		$this->waitTimeout = $params['waitTimeout'] ?? self::MAX_WAIT_DEFAULT;

		if ( isset( $params['readOnlyReason'] ) && is_string( $params['readOnlyReason'] ) ) {
			$this->readOnlyReason = $params['readOnlyReason'];
		}

		$this->loadMonitorConfig = $params['loadMonitor'] ?? [ 'class' => 'LoadMonitorNull' ];
		$this->loadMonitorConfig += [ 'lagWarnThreshold' => $this->maxLag ];

		$this->srvCache = $params['srvCache'] ?? new EmptyBagOStuff();
		$this->wanCache = $params['wanCache'] ?? WANObjectCache::newEmpty();
		$this->databaseFactory = $params['databaseFactory'] ?? new DatabaseFactory();
		$this->errorLogger = $params['errorLogger'] ?? static function ( Throwable $e ) {
				trigger_error( get_class( $e ) . ': ' . $e->getMessage(), E_USER_WARNING );
		};
		$this->deprecationLogger = $params['deprecationLogger'] ?? static function ( $msg ) {
				trigger_error( $msg, E_USER_DEPRECATED );
		};
		$this->replLogger = $params['replLogger'] ?? new NullLogger();
		$this->connLogger = $params['connLogger'] ?? new NullLogger();
		$this->queryLogger = $params['queryLogger'] ?? new NullLogger();
		$this->perfLogger = $params['perfLogger'] ?? new NullLogger();

		$this->clusterName = $params['clusterName'] ?? null;
		$this->profiler = $params['profiler'] ?? null;
		$this->trxProfiler = $params['trxProfiler'] ?? new TransactionProfiler();
		$this->statsd = $params['statsdDataFactory'] ?? new NullStatsdDataFactory();

		$this->csProvider = $params['criticalSectionProvider'] ?? null;

		$this->cliMode = $params['cliMode'] ?? ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' );
		$this->agent = $params['agent'] ?? '';

		if ( isset( $params['chronologyCallback'] ) ) {
			$this->chronologyCallback = $params['chronologyCallback'];
		}

		if ( isset( $params['roundStage'] ) ) {
			if ( $params['roundStage'] === self::STAGE_POSTCOMMIT_CALLBACKS ) {
				$this->trxRoundStage = self::ROUND_COMMIT_CALLBACKS;
			} elseif ( $params['roundStage'] === self::STAGE_POSTROLLBACK_CALLBACKS ) {
				$this->trxRoundStage = self::ROUND_ROLLBACK_CALLBACKS;
			}
		}

		$group = $params['defaultGroup'] ?? self::GROUP_GENERIC;
		$this->defaultGroup = isset( $this->groupLoads[ $group ] ) ? $group : self::GROUP_GENERIC;
	}

	private static function newTrackedConnectionsArray() {
		return [
			// Connection handles that participate in transaction rounds
			self::POOL_ROUND => [],
			// Auto-committing connection handles that ignore transaction rounds
			self::POOL_AUTOCOMMIT => []
		];
	}

	public function getClusterName(): string {
		// Fallback to the current primary name if not specified
		return $this->clusterName ?? $this->getServerName( $this->getWriterIndex() );
	}

	public function getLocalDomainID(): string {
		return $this->localDomain->getId();
	}

	public function resolveDomainID( $domain ): string {
		return $this->resolveDomainInstance( $domain )->getId();
	}

	/**
	 * @param DatabaseDomain|string|false $domain
	 * @return DatabaseDomain
	 */
	final protected function resolveDomainInstance( $domain ): DatabaseDomain {
		if ( $domain instanceof DatabaseDomain ) {
			return $domain; // already a domain instance
		} elseif ( $domain === false || $domain === $this->localDomain->getId() ) {
			return $this->localDomain;
		} elseif ( isset( $this->domainAliases[$domain] ) ) {
			$this->domainAliases[$domain] =
				DatabaseDomain::newFromId( $this->domainAliases[$domain] );

			return $this->domainAliases[$domain];
		}

		$cachedDomain = $this->nonLocalDomainCache[$domain] ?? null;
		if ( $cachedDomain === null ) {
			$cachedDomain = DatabaseDomain::newFromId( $domain );
			$this->nonLocalDomainCache = [ $domain => $cachedDomain ];
		}

		return $cachedDomain;
	}

	/**
	 * Resolve $groups into a list of query groups defining as having database servers
	 *
	 * @param string[]|string|false $groups Query group(s) in preference order, [], or false
	 * @param int $i Specific server index or DB_PRIMARY/DB_REPLICA
	 * @return string[] Non-empty group list in preference order with the default group appended
	 */
	private function resolveGroups( $groups, $i ) {
		// If a specific replica server was specified, then $groups makes no sense
		if ( $i > 0 && $groups !== [] && $groups !== false ) {
			$list = implode( ', ', (array)$groups );
			throw new LogicException( "Query group(s) ($list) given with server index (#$i)" );
		}

		if ( $groups === [] || $groups === false || $groups === $this->defaultGroup ) {
			$resolvedGroups = [ $this->defaultGroup ]; // common case
		} elseif ( is_string( $groups ) && isset( $this->groupLoads[$groups] ) ) {
			$resolvedGroups = [ $groups, $this->defaultGroup ];
		} elseif ( is_array( $groups ) ) {
			$resolvedGroups = $groups;
			if ( !in_array( $this->defaultGroup, $resolvedGroups ) ) {
				$resolvedGroups[] = $this->defaultGroup;
			}
		} else {
			$resolvedGroups = [ $this->defaultGroup ];
		}

		return $resolvedGroups;
	}

	/**
	 * @param int $flags Bitfield of class CONN_* constants
	 * @param int $i Specific server index or DB_PRIMARY/DB_REPLICA
	 * @param string $domain Database domain
	 * @return int Sanitized bitfield
	 */
	private function sanitizeConnectionFlags( $flags, $i, $domain ) {
		// Whether an outside caller is explicitly requesting the primary database server
		if ( $i === self::DB_PRIMARY || $i === $this->getWriterIndex() ) {
			$flags |= self::CONN_INTENT_WRITABLE;
		}

		if ( self::fieldHasBit( $flags, self::CONN_TRX_AUTOCOMMIT ) ) {
			// Callers use CONN_TRX_AUTOCOMMIT to bypass REPEATABLE-READ staleness without
			// resorting to row locks (e.g. FOR UPDATE) or to make small out-of-band commits
			// during larger transactions. This is useful for avoiding lock contention.
			// Assuming all servers are of the same type (or similar), which is overwhelmingly
			// the case, use the primary server information to get the attributes. The information
			// for $i cannot be used since it might be DB_REPLICA, which might require connection
			// attempts in order to be resolved into a real server index.
			$attributes = $this->getServerAttributes( $this->getWriterIndex() );
			if ( $attributes[Database::ATTR_DB_LEVEL_LOCKING] ) {
				// The RDBMS does not support concurrent writes (e.g. SQLite), so attempts
				// to use separate connections would just cause self-deadlocks. Note that
				// REPEATABLE-READ staleness is not an issue since DB-level locking means
				// that transactions are Strict Serializable anyway.
				$flags &= ~self::CONN_TRX_AUTOCOMMIT;
				$type = $this->getServerType( $this->getWriterIndex() );
				$this->connLogger->info( __METHOD__ . ": CONN_TRX_AUTOCOMMIT disallowed ($type)" );
			} elseif ( isset( $this->tempTablesOnlyMode[$domain] ) ) {
				// T202116: integration tests are active and queries should be all be using
				// temporary clone tables (via prefix). Such tables are not visible across
				// different connections nor can there be REPEATABLE-READ snapshot staleness,
				// so use the same connection for everything.
				$flags &= ~self::CONN_TRX_AUTOCOMMIT;
			}
		}

		return $flags;
	}

	/**
	 * @param IDatabase $conn
	 * @param int $flags
	 * @throws DBUnexpectedError
	 */
	private function enforceConnectionFlags( IDatabase $conn, $flags ) {
		if ( self::fieldHasBit( $flags, self::CONN_TRX_AUTOCOMMIT ) ) {
			if ( $conn->trxLevel() ) {
				throw new DBUnexpectedError(
					$conn,
					'Handle requested with CONN_TRX_AUTOCOMMIT yet it has a transaction'
				);
			}

			$conn->clearFlag( $conn::DBO_TRX ); // auto-commit mode
		}
	}

	/**
	 * Get a LoadMonitor instance
	 *
	 * @return ILoadMonitor
	 */
	private function getLoadMonitor() {
		if ( !isset( $this->loadMonitor ) ) {
			$compat = [
				'LoadMonitor' => LoadMonitor::class,
				'LoadMonitorNull' => LoadMonitorNull::class
			];

			$class = $this->loadMonitorConfig['class'];
			if ( isset( $compat[$class] ) ) {
				$class = $compat[$class];
			}

			$this->loadMonitor = new $class(
				$this, $this->srvCache, $this->wanCache, $this->loadMonitorConfig );
			$this->loadMonitor->setLogger( $this->replLogger );
			$this->loadMonitor->setStatsdDataFactory( $this->statsd );
		}

		return $this->loadMonitor;
	}

	/**
	 * @param array $loads
	 * @param int|float $maxLag Restrict the maximum allowed lag to this many seconds, or INF for no max
	 * @return int|string|false
	 */
	private function getRandomNonLagged( array $loads, $maxLag = INF ) {
		$lags = $this->getLagTimes();

		# Unset excessively lagged servers
		foreach ( $lags as $i => $lag ) {
			if ( $i !== $this->getWriterIndex() ) {
				# How much lag this server nominally is allowed to have
				$maxServerLag = $this->servers[$i]['max lag'] ?? $this->maxLag; // default
				# Constrain that further by $maxLag argument
				$maxServerLag = min( $maxServerLag, $maxLag );

				$srvName = $this->getServerName( $i );
				if ( $lag === false && !is_infinite( $maxServerLag ) ) {
					$this->replLogger->debug(
						__METHOD__ . ": server {db_server} is not replicating?",
						[ 'db_server' => $srvName ]
					);
					unset( $loads[$i] );
				} elseif ( $lag > $maxServerLag ) {
					$this->replLogger->debug(
						__METHOD__ .
							": server {db_server} has {lag} seconds of lag (>= {maxlag})",
						[ 'db_server' => $srvName, 'lag' => $lag, 'maxlag' => $maxServerLag ]
					);
					unset( $loads[$i] );
				}
			}
		}

		if ( array_sum( $loads ) == 0 ) {
			// All the replicas with non-zero load are lagged and the primary has zero load.
			// Inform caller so that it can use switch to read-only mode and use a lagged replica.
			return false;
		}

		# Return a random representative of the remainder
		return ArrayUtils::pickRandom( $loads );
	}

	/**
	 * Get the server index to use for a specified server index and query group list
	 *
	 * @param int $i Specific server index or DB_PRIMARY/DB_REPLICA
	 * @param string[] $groups Non-empty query group list in preference order
	 * @param string|false $domain
	 * @return int A specific server index (replica DBs are checked for connectivity)
	 */
	private function getConnectionIndex( $i, array $groups, $domain ) {
		if ( $i === self::DB_PRIMARY ) {
			$i = $this->getWriterIndex();
		} elseif ( $i === self::DB_REPLICA ) {
			foreach ( $groups as $group ) {
				$groupIndex = $this->getReaderIndex( $group );
				if ( $groupIndex !== false ) {
					$i = $groupIndex; // group connection succeeded
					break;
				}
			}
			if ( $i < 0 ) {
				$this->reportConnectionError( 'could not connect to any replica DB server' );
			}
		} elseif ( !isset( $this->servers[$i] ) ) {
			throw new UnexpectedValueException( "Invalid server index index #$i" );
		}

		return $i;
	}

	public function getReaderIndex( $group = false ) {
		$group = is_string( $group ) ? $group : self::GROUP_GENERIC;

		if ( !$this->hasReplicaServers() ) {
			// There is only one possible server to use (the primary)
			return $this->getWriterIndex();
		}

		$index = $this->getExistingReaderIndex( $group );
		if ( $index !== self::READER_INDEX_NONE ) {
			// A reader index was already selected for this query group. Keep using it,
			// since any session replication position was already waited on and any
			// active transaction will be reused (e.g. for point-in-time snapshots).
			return $index;
		}

		// Get the server weight array for this load group
		$loads = $this->groupLoads[$group] ?? [];
		if ( !$loads ) {
			$this->connLogger->info( __METHOD__ . ": no loads for group $group" );

			return false;
		}

		// Load any session replication positions, before any connection attempts,
		// since reading them afterwards can only cause more delay due to possibly
		// seeing even higher replication positions (e.g. from concurrent requests).
		$this->loadSessionPrimaryPos();

		// Scale the configured load ratios according to each server's load/state.
		// This can sometimes trigger server connections due to cache regeneration.
		$this->getLoadMonitor()->scaleLoads( $loads );

		// Pick a server, accounting for weight, load, lag, and session consistency
		[ $i, $laggedReplicaMode ] = $this->pickReaderIndex( $loads );
		if ( $i === false ) {
			// Connection attempts failed
			return false;
		}

		// If data seen by queries is expected to reflect writes from a prior transaction,
		// then wait for the chosen server to apply those changes. This is used to improve
		// session consistency.
		if ( !$this->awaitSessionPrimaryPos( $i ) ) {
			// Data will be outdated compared to what was expected
			$laggedReplicaMode = true;
		}

		// Keep using this server for DB_REPLICA handles for this group
		$this->setExistingReaderIndex( $group, $i );

		// Record whether the generic reader index is in "lagged replica DB" mode
		if ( $group === self::GROUP_GENERIC && $laggedReplicaMode ) {
			$this->laggedReplicaMode = true;
			$this->replLogger->debug( __METHOD__ . ": setting lagged replica mode" );
		}

		$serverName = $this->getServerName( $i );
		$this->connLogger->debug( __METHOD__ . ": using server $serverName for group '$group'" );

		return $i;
	}

	/**
	 * Get the server index chosen for DB_REPLICA connections for the given query group
	 *
	 * @param string $group Query group; use false for the generic group
	 * @return int Specific server index or LoadBalancer::READER_INDEX_NONE if none was chosen
	 */
	protected function getExistingReaderIndex( $group ) {
		return $this->readIndexByGroup[$group] ?? self::READER_INDEX_NONE;
	}

	/**
	 * Set the server index chosen for DB_REPLICA connections for the given query group
	 *
	 * @param string $group Query group; use false for the generic group
	 * @param int $index Specific server index
	 */
	private function setExistingReaderIndex( $group, $index ) {
		if ( $index < 0 ) {
			throw new UnexpectedValueException( "Cannot set a negative read server index" );
		}
		$this->readIndexByGroup[$group] = $index;
	}

	/**
	 * Pick a server that is reachable, preferably non-lagged, and return its server index
	 *
	 * This will leave the server connection open within the pool for reuse
	 *
	 * @param array $loads List of server weights
	 * @return array (reader index, lagged replica mode) or (false, false) on failure
	 */
	private function pickReaderIndex( array $loads ) {
		if ( $loads === [] ) {
			throw new InvalidArgumentException( "Server configuration array is empty" );
		}

		/** @var int|false $i Index of selected server */
		$i = false;

		$laggedReplicaMode = false;

		// Quickly look through the available servers for a server that meets criteria...
		$currentLoads = $loads;
		while ( count( $currentLoads ) ) {
			if ( $laggedReplicaMode ) {
				$i = ArrayUtils::pickRandom( $currentLoads );
			} else {
				$i = false;
				if ( $this->waitForPos && $this->waitForPos->asOfTime() ) {
					$this->replLogger->debug( __METHOD__ . ": session has replication position" );
					// "chronologyCallback" sets "waitForPos" for session consistency.
					// This triggers doWait() after connect, so it's especially good to
					// avoid lagged servers so as to avoid excessive delay in that method.
					$ago = microtime( true ) - $this->waitForPos->asOfTime();
					// Aim for <= 1 second of waiting (being too picky can backfire)
					$i = $this->getRandomNonLagged( $currentLoads, $ago + 1 );
				}
				if ( $i === false ) {
					// Any server with less lag than it's 'max lag' param is preferable
					$i = $this->getRandomNonLagged( $currentLoads );
				}
				if ( $i === false && count( $currentLoads ) ) {
					// All replica DBs lagged. Switch to read-only mode
					$this->replLogger->error( __METHOD__ . ": excessive replication lag" );
					$i = ArrayUtils::pickRandom( $currentLoads );
					$laggedReplicaMode = true;
				}
			}

			if ( $i === false ) {
				// pickRandom() returned false.
				// This is permanent and means the configuration or the load monitor
				// wants us to return false.
				$this->connLogger->debug( __METHOD__ . ": no suitable server found" );

				return [ false, false ];
			}

			$serverName = $this->getServerName( $i );
			$this->connLogger->debug( __METHOD__ . ": connecting to $serverName..." );

			// Get a connection to this server without triggering complementary connections
			// to other servers (due to things like lag or read-only checks). We want to avoid
			// the risk of overhead and recursion here.
			$conn = $this->getServerConnection( $i, self::DOMAIN_ANY, self::CONN_SILENCE_ERRORS );
			if ( !$conn ) {
				$this->connLogger->warning( __METHOD__ . ": failed connecting to $serverName" );
				unset( $currentLoads[$i] ); // avoid this server next iteration
				$i = false;
				continue;
			}

			// Return this server
			break;
		}

		// If all servers were down, quit now
		if ( $currentLoads === [] ) {
			$this->connLogger->error( __METHOD__ . ": all servers down" );
		}

		return [ $i, $laggedReplicaMode ];
	}

	public function waitFor( $pos ) {
		$oldPos = $this->waitForPos;
		try {
			$this->waitForPos = $pos;

			$genericIndex = $this->getExistingReaderIndex( self::GROUP_GENERIC );
			// If a generic reader connection was already established, then wait now.
			// Otherwise, wait until a connection is established in getReaderIndex().
			if ( $genericIndex !== self::READER_INDEX_NONE ) {
				if ( !$this->awaitSessionPrimaryPos( $genericIndex ) ) {
					$this->laggedReplicaMode = true;
					$this->replLogger->debug( __METHOD__ . ": setting lagged replica mode" );
				}
			}
		} finally {
			// Restore the older position if it was higher since this is used for lag-protection
			$this->setWaitForPositionIfHigher( $oldPos );
		}
	}

	public function waitForAll( $pos, $timeout = null ) {
		$timeout = $timeout ?: $this->waitTimeout;

		$oldPos = $this->waitForPos;
		try {
			$this->waitForPos = $pos;

			$ok = true;
			foreach ( $this->getStreamingReplicaIndexes() as $i ) {
				if ( $this->serverHasLoadInAnyGroup( $i ) ) {
					$start = microtime( true );
					$ok = $this->awaitSessionPrimaryPos( $i, $timeout ) && $ok;
					$timeout -= intval( microtime( true ) - $start );
					if ( $timeout <= 0 ) {
						break; // timeout reached
					}
				}
			}

			return $ok;
		} finally {
			// Restore the old position; this is used for throttling, not lag-protection
			$this->waitForPos = $oldPos;
		}
	}

	/**
	 * @param int $i Specific server index
	 * @return bool
	 */
	private function serverHasLoadInAnyGroup( $i ) {
		foreach ( $this->groupLoads as $loadsByIndex ) {
			if ( ( $loadsByIndex[$i] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param DBPrimaryPos|false $pos
	 */
	private function setWaitForPositionIfHigher( $pos ) {
		if ( !$pos ) {
			return;
		}

		if ( !$this->waitForPos || $pos->hasReached( $this->waitForPos ) ) {
			$this->waitForPos = $pos;
		}
	}

	public function getAnyOpenConnection( $i, $flags = 0 ) {
		$i = ( $i === self::DB_PRIMARY ) ? $this->getWriterIndex() : $i;
		// Connection handles required to be in auto-commit mode use a separate connection
		// pool since the main pool is effected by implicit and explicit transaction rounds
		$autoCommitOnly = self::fieldHasBit( $flags, self::CONN_TRX_AUTOCOMMIT );

		$conn = false;
		foreach ( $this->conns as $type => $poolConnsByServer ) {
			if ( $i === self::DB_REPLICA ) {
				// Consider all existing connections to any server
				$applicableConnsByServer = $poolConnsByServer;
			} else {
				// Consider all existing connections to a specific server
				$applicableConnsByServer = isset( $poolConnsByServer[$i] )
					? [ $i => $poolConnsByServer[$i] ]
					: [];
			}

			$conn = $this->pickAnyOpenConnection( $applicableConnsByServer, $autoCommitOnly );
			if ( $conn ) {
				$this->connLogger->debug( __METHOD__ . ": found '$type' connection to #$i." );
				break;
			}
		}

		if ( $conn ) {
			$this->enforceConnectionFlags( $conn, $flags );
		}

		return $conn;
	}

	/**
	 * @param Database[][] $connsByServer Map of (server index => array of DB handles)
	 * @param bool $autoCommitOnly Whether to only look for auto-commit connections
	 * @return IDatabase|false An appropriate open connection or false if none found
	 */
	private function pickAnyOpenConnection( array $connsByServer, $autoCommitOnly ) {
		foreach ( $connsByServer as $i => $conns ) {
			foreach ( $conns as $conn ) {
				if ( !$conn->isOpen() ) {
					$this->connLogger->warning(
						__METHOD__ .
						": pooled DB handle for {db_server} (#$i) has no open connection.",
						$this->getConnLogContext( $conn )
					);
					continue; // some sort of error occurred?
				}

				if ( $autoCommitOnly ) {
					// Only accept CONN_TRX_AUTOCOMMIT connections
					if ( !$conn->getLBInfo( self::INFO_AUTOCOMMIT_ONLY ) ) {
						// Connection is aware of transaction rounds
						continue;
					}

					if ( $conn->trxLevel() ) {
						// Some sort of bug left a transaction open
						$this->connLogger->warning(
							__METHOD__ .
							": pooled DB handle for {db_server} (#$i) has a pending transaction.",
							$this->getConnLogContext( $conn )
						);
						continue;
					}
				}

				return $conn;
			}
		}

		return false;
	}

	/**
	 * Wait for a given replica DB to catch up to the primary DB pos stored in "waitForPos"
	 *
	 * @see loadSessionPrimaryPos()
	 *
	 * @param int $index Specific server index
	 * @param int|null $timeout Max seconds to wait; default is "waitTimeout"
	 * @return bool Success
	 */
	private function awaitSessionPrimaryPos( $index, $timeout = null ) {
		$timeout = max( 1, intval( $timeout ?: $this->waitTimeout ) );

		if ( !$this->waitForPos || $index === $this->getWriterIndex() ) {
			return true;
		}

		$srvName = $this->getServerName( $index );

		// Check if we already know that the DB has reached this point
		$key = $this->srvCache->makeGlobalKey( __CLASS__, 'last-known-pos', $srvName, 'v2' );

		/** @var DBPrimaryPos $knownReachedPos */
		$knownReachedPos = $this->unmarshalPosition( $this->srvCache->get( $key ) );
		if (
			$knownReachedPos instanceof DBPrimaryPos &&
			$knownReachedPos->hasReached( $this->waitForPos )
		) {
			$this->replLogger->debug(
				__METHOD__ .
				": replica DB {db_server} known to be caught up (pos >= $knownReachedPos).",
				[ 'db_server' => $srvName ]
			);

			return true;
		}

		$close = false; // close the connection afterwards
		$flags = self::CONN_SILENCE_ERRORS;
		// Check if there is an existing connection that can be used
		$conn = $this->getAnyOpenConnection( $index, $flags );
		if ( !$conn ) {
			// Get a connection to this server without triggering complementary connections
			// to other servers (due to things like lag or read-only checks). We want to avoid
			// the risk of overhead and recursion here.
			$conn = $this->getServerConnection( $index, self::DOMAIN_ANY, $flags );
			if ( !$conn ) {
				$this->replLogger->warning(
					__METHOD__ . ': failed to connect to {db_server}',
					[ 'db_server' => $srvName ]
				);

				return false;
			}
			// Avoid connection spam in waitForAll() when connections
			// are made just for the sake of doing this lag check.
			$close = true;
		}

		$this->replLogger->info(
			__METHOD__ .
			': waiting for replica DB {db_server} to catch up...',
			$this->getConnLogContext( $conn )
		);

		$result = $conn->primaryPosWait( $this->waitForPos, $timeout );

		$ok = ( $result !== null && $result != -1 );
		if ( $ok ) {
			// Remember that the DB reached this point
			$this->srvCache->set( $key, $this->waitForPos->toArray(), BagOStuff::TTL_DAY );
		}

		if ( $close ) {
			$this->closeConnection( $conn );
		}

		return $ok;
	}

	private function unmarshalPosition( $position ) {
		if ( !is_array( $position ) ) {
			return null;
		}

		$class = $position['_type_'];
		return $class::newFromArray( $position );
	}

	public function getConnection( $i, $groups = [], $domain = false, $flags = 0 ) {
		return $this->getConnectionRef( $i, $groups, $domain, $flags );
	}

	public function getConnectionInternal( $i, $groups = [], $domain = false, $flags = 0 ): IDatabase {
		$domain = $this->resolveDomainID( $domain );
		$groups = $this->resolveGroups( $groups, $i );
		$flags = $this->sanitizeConnectionFlags( $flags, $i, $domain );
		// If given DB_PRIMARY/DB_REPLICA, resolve it to a specific server index. Resolving
		// DB_REPLICA might trigger getServerConnection() calls due to the getReaderIndex()
		// connectivity checks or LoadMonitor::scaleLoads() server state cache regeneration.
		// The use of getServerConnection() instead of getConnection() avoids infinite loops.
		$serverIndex = $this->getConnectionIndex( $i, $groups, $domain );
		// Get an open connection to that server (might trigger a new connection)
		$conn = $this->getServerConnection( $serverIndex, $domain, $flags );
		// Set primary DB handles as read-only if there is high replication lag
		if (
			$conn &&
			$serverIndex === $this->getWriterIndex() &&
			$this->getLaggedReplicaMode() &&
			!is_string( $conn->getLBInfo( $conn::LB_READ_ONLY_REASON ) )
		) {
			$genericIndex = $this->getExistingReaderIndex( self::GROUP_GENERIC );
			$reason = ( $genericIndex !== self::READER_INDEX_NONE )
				? 'The database is read-only until replication lag decreases.'
				: 'The database is read-only until replica database servers becomes reachable.';
			$conn->setLBInfo( $conn::LB_READ_ONLY_REASON, $reason );
		}

		return $conn;
	}

	public function getServerConnection( $i, $domain, $flags = 0 ) {
		$domainInstance = DatabaseDomain::newFromId( $domain );
		// Number of connections made before getting the server index and handle
		$priorConnectionsMade = $this->connectionCounter;
		// Get an open connection to this server (might trigger a new connection)
		$conn = $this->reuseOrOpenConnectionForNewRef( $i, $domainInstance, $flags );
		// Throw an error or otherwise bail out if the connection attempt failed
		if ( !( $conn instanceof IDatabase ) ) {
			if ( !self::fieldHasBit( $flags, self::CONN_SILENCE_ERRORS ) ) {
				$this->reportConnectionError();
			}

			return false;
		}

		// Profile any new connections caused by this method
		if ( $this->connectionCounter > $priorConnectionsMade ) {
			$this->trxProfiler->recordConnection(
				$conn->getServerName(),
				$conn->getDBname(),
				self::fieldHasBit( $flags, self::CONN_INTENT_WRITABLE )
			);
		}

		if ( !$conn->isOpen() ) {
			$this->lastErrorConn = $conn;
			// Connection was made but later unrecoverably lost for some reason.
			// Do not return a handle that will just throw exceptions on use, but
			// let the calling code, e.g. getReaderIndex(), try another server.
			if ( !self::fieldHasBit( $flags, self::CONN_SILENCE_ERRORS ) ) {
				$this->reportConnectionError();
			}
			return false;
		}

		// Make sure that flags like CONN_TRX_AUTOCOMMIT are respected by this handle
		$this->enforceConnectionFlags( $conn, $flags );
		// Set primary DB handles as read-only if the load balancer is configured as read-only
		// or the primary database server is running in server-side read-only mode. Note that
		// replica DB handles are always read-only via Database::assertIsWritablePrimary().
		// Read-only mode due to replication lag is *avoided* here to avoid recursion.
		if ( $i === $this->getWriterIndex() ) {
			if ( $this->readOnlyReason !== false ) {
				$readOnlyReason = $this->readOnlyReason;
			} elseif ( $this->isPrimaryConnectionReadOnly( $conn, $flags ) ) {
				$readOnlyReason = 'The primary database server is running in read-only mode.';
			} else {
				$readOnlyReason = false;
			}
			$conn->setLBInfo( $conn::LB_READ_ONLY_REASON, $readOnlyReason );
		}

		return $conn;
	}

	public function reuseConnection( IDatabase $conn ) {
		// no-op
	}

	public function getConnectionRef( $i, $groups = [], $domain = false, $flags = 0 ): IDatabase {
		if ( self::fieldHasBit( $flags, self::CONN_SILENCE_ERRORS ) ) {
			throw new UnexpectedValueException(
				__METHOD__ . ' got CONN_SILENCE_ERRORS; connection is already deferred'
			);
		}

		$domain = $this->resolveDomainID( $domain );
		$role = $this->getRoleFromIndex( $i );

		return new DBConnRef( $this, [ $i, $groups, $domain, $flags ], $role, $this->modcount );
	}

	public function getLazyConnectionRef( $i, $groups = [], $domain = false, $flags = 0 ): IDatabase {
		wfDeprecated( __METHOD__, '1.38 Use ::getConnectionRef' );
		return $this->getConnectionRef( $i, $groups, $domain, $flags );
	}

	public function getMaintenanceConnectionRef(
		$i,
		$groups = [],
		$domain = false,
		$flags = 0
	): DBConnRef {
		if ( self::fieldHasBit( $flags, self::CONN_SILENCE_ERRORS ) ) {
			throw new UnexpectedValueException(
				__METHOD__ . ' CONN_SILENCE_ERRORS is not supported'
			);
		}

		$domain = $this->resolveDomainID( $domain );
		$role = $this->getRoleFromIndex( $i );

		return new DBConnRef( $this, [ $i, $groups, $domain, $flags ], $role, $this->modcount );
	}

	/**
	 * @param int $i Server index or DB_PRIMARY/DB_REPLICA
	 * @return int One of DB_PRIMARY/DB_REPLICA
	 */
	private function getRoleFromIndex( $i ) {
		return ( $i === self::DB_PRIMARY || $i === $this->getWriterIndex() )
			? self::DB_PRIMARY
			: self::DB_REPLICA;
	}

	/**
	 * Get a live connection handle to the given domain
	 *
	 * This will reuse an existing tracked connection when possible. In some cases, this
	 * involves switching the DB domain of an existing handle in order to reuse it. If no
	 * existing handles can be reused, then a new connection will be made.
	 *
	 * On error, the offending DB handle will be available via $this->errorConnection.
	 *
	 * @param int $i Specific server index
	 * @param DatabaseDomain $domain Database domain ID required by the reference
	 * @param int $flags Bit field of class CONN_* constants
	 * @return IDatabase|null Database or null on error
	 * @throws DBError When database selection fails
	 * @throws InvalidArgumentException When the server index is invalid
	 * @throws UnexpectedValueException When the DB domain of the connection is corrupted
	 * @throws DBAccessError If disable() was called
	 */
	private function reuseOrOpenConnectionForNewRef( $i, DatabaseDomain $domain, $flags = 0 ) {
		// Connection handles required to be in auto-commit mode use a separate connection
		// pool since the main pool is effected by implicit and explicit transaction rounds
		$autoCommit = self::fieldHasBit( $flags, self::CONN_TRX_AUTOCOMMIT );
		// Decide which pool of connection handles to use (segregated by CONN_TRX_AUTOCOMMIT)
		$poolKey = $autoCommit ? self::POOL_AUTOCOMMIT : self::POOL_ROUND;

		$conn = null;
		// Reuse a free connection in the pool from any domain if possible. There should only
		// be one connection in this pool unless either:
		//  - a) IDatabase::databasesAreIndependent() returns true (e.g. postgres) and two
		//       or more database domains have been used during the load balancer's lifetime
		//  - b) Two or more nested function calls used getConnection() on different domains.
		//       Normally, callers should use getConnectionRef() instead of getConnection().
		foreach ( ( $this->conns[$poolKey][$i] ?? [] ) as $poolConn ) {
			// Check if any required DB domain changes for the new reference are possible
			// Calling selectDomain() would trigger a reconnect, which will break if a
			// transaction is active or if there is any other meaningful session state.
			$isShareable = !(
				$poolConn->databasesAreIndependent() &&
				$domain->getDatabase() !== null &&
				$domain->getDatabase() !== $poolConn->getDBname()
			);
			if ( $isShareable ) {
				$conn = $poolConn;
				// Make any required DB domain changes for the new reference
				if ( !$domain->isUnspecified() ) {
					$conn->selectDomain( $domain );
				}
				$this->connLogger->debug( __METHOD__ . ": reusing connection for $i/$domain" );
				break;
			}
		}

		// If necessary, try to open a new connection and add it to the pool
		if ( !$conn ) {
			$conn = $this->reallyOpenConnection(
				$i,
				$domain,
				[ self::INFO_AUTOCOMMIT_ONLY => $autoCommit ]
			);
			if ( $conn->isOpen() ) {
				$this->conns[$poolKey][$i][] = $conn;
			} else {
				$this->connLogger->warning( __METHOD__ . ": connection error for $i/$domain" );
				$this->lastErrorConn = $conn;
				$conn = null;
			}
		}

		// Check to make sure that the right domain is selected
		if ( $conn instanceof IDatabase ) {
			$this->assertConnectionDomain( $conn, $domain );
		}

		return $conn;
	}

	/**
	 * Sanity check to make sure that the right domain is selected
	 *
	 * @param Database $conn
	 * @param DatabaseDomain $domain
	 * @throws DBUnexpectedError
	 */
	private function assertConnectionDomain( Database $conn, DatabaseDomain $domain ) {
		if ( !$domain->isCompatible( $conn->getDomainID() ) ) {
			throw new UnexpectedValueException(
				"Got connection to '{$conn->getDomainID()}', but expected one for '{$domain}'"
			);
		}
	}

	public function getServerAttributes( $i ) {
		return $this->databaseFactory->attributesFromType(
			$this->getServerType( $i ),
			$this->servers[$i]['driver'] ?? null
		);
	}

	/**
	 * Test if the specified index represents an open connection
	 *
	 * @param int $index Server index
	 * @return bool
	 */
	private function isOpen( $index ) {
		return (bool)$this->getAnyOpenConnection( $index );
	}

	/**
	 * Open a new network connection to a server (uncached)
	 *
	 * Returns a Database object whether or not the connection was successful.
	 *
	 * @param int $i Specific server index
	 * @param DatabaseDomain $domain Domain the connection is for, possibly unspecified
	 * @param array $lbInfo Additional information for setLBInfo()
	 * @return Database
	 * @throws DBAccessError
	 * @throws InvalidArgumentException
	 */
	protected function reallyOpenConnection( $i, DatabaseDomain $domain, array $lbInfo ) {
		if ( $this->disabled ) {
			throw new DBAccessError();
		}

		$server = $this->getServerInfoStrict( $i );

		$conn = $this->databaseFactory->create(
			$server['type'],
			array_merge( $server, [
				// Basic replication role information
				'topologyRole' => $this->getTopologyRole( $i, $server ),
				// Use the database specified in $domain (null means "none or entrypoint DB");
				// fallback to the $server default if the RDBMs is an embedded library using a
				// file on disk since there would be nothing to access to without a DB/file name.
				'dbname' => $this->getServerAttributes( $i )[Database::ATTR_DB_IS_FILE]
					? ( $domain->getDatabase() ?? $server['dbname'] ?? null )
					: $domain->getDatabase(),
				// Override the $server default schema with that of $domain if specified
				'schema' => $domain->getSchema(),
				// Use the table prefix specified in $domain
				'tablePrefix' => $domain->getTablePrefix(),
				// Participate in transaction rounds if $server does not specify otherwise
				'flags' => $this->initConnFlags( $server['flags'] ?? IDatabase::DBO_DEFAULT ),
				// Inject the PHP execution mode and the agent string
				'cliMode' => $this->cliMode,
				'agent' => $this->agent,
				'srvCache' => $this->srvCache,
				'connLogger' => $this->connLogger,
				'queryLogger' => $this->queryLogger,
				'replLogger' => $this->replLogger,
				'errorLogger' => $this->errorLogger,
				'deprecationLogger' => $this->deprecationLogger,
				'profiler' => $this->profiler,
				'trxProfiler' => $this->trxProfiler,
				'criticalSectionProvider' => $this->csProvider
			] ),
			Database::NEW_UNCONNECTED
		);
		// Attach load balancer information to the handle
		$conn->setLBInfo( [ self::INFO_SERVER_INDEX => $i ] + $lbInfo );
		// Set alternative table/index names before any queries can be issued
		$conn->setTableAliases( $this->tableAliases );
		$conn->setIndexAliases( $this->indexAliases );
		// Account for any active transaction round and listeners
		if ( $i === $this->getWriterIndex() ) {
			if ( $this->trxRoundId !== false ) {
				$this->applyTransactionRoundFlags( $conn );
			}
			foreach ( $this->trxRecurringCallbacks as $name => $callback ) {
				$conn->setTransactionListener( $name, $callback );
			}
		}

		// Make the connection handle live
		try {
			$conn->initConnection();
			++$this->connectionCounter;
		} catch ( DBConnectionError $e ) {
			$this->lastErrorConn = $conn;
			// ignore; let the DB handle the logging
		}

		if ( $conn->isOpen() ) {
			$this->connLogger->debug( __METHOD__ . ": opened new connection for $i/$domain" );
		} else {
			$this->connLogger->warning(
				__METHOD__ . ": connection error for $i/{db_domain}",
				[ 'db_domain' => $domain->getId() ]
			);
		}

		// Log when many connection are made during a single request/script
		$count = $this->getCurrentConnectionCount();
		if ( $count >= self::CONN_HELD_WARN_THRESHOLD ) {
			$this->perfLogger->warning(
				__METHOD__ . ": {connections}+ connections made (primary={primarydb})",
				$this->getConnLogContext(
					$conn,
					[
						'connections' => $count,
						'primarydb' => $this->getPrimaryServerName(),
						'db_domain' => $domain->getId()
					]
				)
			);
		}

		$this->assertConnectionDomain( $conn, $domain );

		return $conn;
	}

	/**
	 * @param int $i Specific server index
	 * @param array $server Server config map
	 * @return string IDatabase::ROLE_* constant
	 */
	private function getTopologyRole( $i, array $server ) {
		if ( !empty( $server['is static'] ) ) {
			return IDatabase::ROLE_STATIC_CLONE;
		}

		return ( $i === $this->getWriterIndex() )
			? IDatabase::ROLE_STREAMING_MASTER
			: IDatabase::ROLE_STREAMING_REPLICA;
	}

	/**
	 * @see IDatabase::DBO_DEFAULT
	 * @param int $flags Bit field of IDatabase::DBO_* constants from configuration
	 * @return int Bit field of IDatabase::DBO_* constants to use with Database::factory()
	 */
	private function initConnFlags( $flags ) {
		if ( self::fieldHasBit( $flags, IDatabase::DBO_DEFAULT ) ) {
			if ( $this->cliMode ) {
				$flags &= ~IDatabase::DBO_TRX;
			} else {
				$flags |= IDatabase::DBO_TRX;
			}
		}

		return $flags;
	}

	/**
	 * Make sure that any "waitForPos" replication positions are loaded and available
	 *
	 * Each load balancer cluster has up to one replication position for the session.
	 * These are used when data read by queries is expected to reflect writes caused
	 * by a prior request/script from the same client.
	 *
	 * @see awaitSessionPrimaryPos()
	 */
	private function loadSessionPrimaryPos() {
		if ( !$this->chronologyCallbackTriggered && $this->chronologyCallback ) {
			$this->chronologyCallbackTriggered = true;
			( $this->chronologyCallback )( $this ); // generally calls waitFor()
			$this->connLogger->debug( __METHOD__ . ': executed chronology callback.' );
		}
	}

	/**
	 * @param string $extraLbError Separat load balancer error
	 * @throws DBConnectionError
	 * @return never
	 */
	private function reportConnectionError( $extraLbError = '' ) {
		if ( $this->lastErrorConn instanceof IDatabase ) {
			$srvName = $this->lastErrorConn->getServerName();
			$lastDbError = $this->lastErrorConn->lastError() ?: 'unknown error';

			$exception = new DBConnectionError(
				$this->lastErrorConn,
				$extraLbError
					? "{$extraLbError}; {$lastDbError} ({$srvName})"
					: "{$lastDbError} ({$srvName})"
			);

			if ( $extraLbError ) {
				$this->connLogger->warning(
					__METHOD__ . ": $extraLbError; {last_error} ({db_server})",
					$this->getConnLogContext(
						$this->lastErrorConn,
						[
							'method' => __METHOD__,
							'last_error' => $lastDbError
						]
					)
				);
			}
		} else {
			$exception = new DBConnectionError(
				null,
				$extraLbError ?: 'could not connect to the DB server'
			);

			if ( $extraLbError ) {
				$this->connLogger->error(
					__METHOD__ . ": $extraLbError",
					[
						'method' => __METHOD__,
						'last_error' => '(last connection error missing)'
					]
				);
			}
		}

		throw $exception;
	}

	public function getWriterIndex() {
		return 0;
	}

	public function getServerCount() {
		return count( $this->servers );
	}

	public function hasReplicaServers() {
		return ( $this->getServerCount() > 1 );
	}

	/**
	 * @return int[] List of replica server indexes
	 */
	private function getStreamingReplicaIndexes() {
		$indexes = [];
		foreach ( $this->servers as $i => $server ) {
			if ( $i !== $this->getWriterIndex() && empty( $server['is static'] ) ) {
				$indexes[] = $i;
			}
		}

		return $indexes;
	}

	public function hasStreamingReplicaServers() {
		return (bool)$this->getStreamingReplicaIndexes();
	}

	private function getServerNameFromConfig( $config ) {
		$name = $config['serverName'] ?? ( $config['host'] ?? '' );
		return ( $name !== '' ) ? $name : 'localhost';
	}

	public function getServerName( $i ): string {
		return $this->getServerNameFromConfig( $this->servers[$i] );
	}

	public function getServerInfo( $i ) {
		return $this->servers[$i] ?? false;
	}

	public function getServerType( $i ) {
		return $this->servers[$i]['type'] ?? 'unknown';
	}

	public function getPrimaryPos() {
		$index = $this->getWriterIndex();

		$conn = $this->getAnyOpenConnection( $index );
		if ( $conn ) {
			return $conn->getPrimaryPos();
		}

		$conn = $this->getConnectionInternal( $index, self::CONN_SILENCE_ERRORS );
		// @phan-suppress-next-line PhanRedundantCondition
		if ( !$conn ) {
			$this->reportConnectionError();
		}

		try {
			return $conn->getPrimaryPos();
		} finally {
			$this->closeConnection( $conn );
		}
	}

	public function getReplicaResumePos() {
		// Get the position of any existing primary DB server connection
		$primaryConn = $this->getAnyOpenConnection( $this->getWriterIndex() );
		if ( $primaryConn ) {
			return $primaryConn->getPrimaryPos();
		}

		// Get the highest position of any existing replica server connection
		$highestPos = false;
		foreach ( $this->getStreamingReplicaIndexes() as $i ) {
			$conn = $this->getAnyOpenConnection( $i );
			$pos = $conn ? $conn->getReplicaPos() : false;
			if ( !$pos ) {
				continue; // no open connection or could not get position
			}

			$highestPos = $highestPos ?: $pos;
			if ( $pos->hasReached( $highestPos ) ) {
				$highestPos = $pos;
			}
		}

		return $highestPos;
	}

	/**
	 * Apply updated configuration.
	 *
	 * This invalidates any open connections. However, existing connections may continue to be
	 * used while they are in an active transaction. In that case, the old connection will be
	 * discarded on the first operation after the transaction is complete. The next operation
	 * will use a new connection based on the new configuration.
	 *
	 * @internal for use by LBFactory::reconfigure()
	 *
	 * @see DBConnRef::ensureConnection()
	 * @see LBFactory::reconfigure()
	 *
	 * @param array $params A database configuration array, see $wgLBFactoryConf.
	 *
	 * @return void
	 */
	public function reconfigure( array $params ) {
		// NOTE: We could close all connection here, but some may be in the middle of
		//       a transaction. So instead, we leave it to DBConnRef to close the
		//       connection when it detects that the modcount has changed and no
		//       transaction is open.
		if ( count( $params['servers'] ) == count( $this->servers ) ) {
			return;
		}
		$this->connLogger->notice( 'Reconfiguring dbs!' );
		$newServers = [];
		foreach ( $params['servers'] as $i => $server ) {
			$newServers[] = $this->getServerNameFromConfig( $server );
		}

		$closeConnections = false;
		foreach ( $this->servers as $i => $server ) {
			if ( !in_array( $this->getServerNameFromConfig( $server ), $newServers ) ) {
				// db depooled, remove it from list of servers
				unset( $this->servers[$i] );
				$this->groupLoads = [ self::GROUP_GENERIC => [] ];
				foreach ( $params['servers'] as $j => $serverNew ) {
					foreach ( ( $serverNew['groupLoads'] ?? [] ) as $group => $ratio ) {
						$this->groupLoads[ $group ][ $j ] = $ratio;
					}
					$this->groupLoads[ self::GROUP_GENERIC ][ $j ] = $serverNew['load'];
				}
				$closeConnections = true;
				$this->readIndexByGroup = [];
				$this->conns = self::newTrackedConnectionsArray();
			}
		}

		if ( $closeConnections ) {
			// Bump modification counter to invalidate the connections held by DBConnRef
			// instances. This will cause the next call to a method on the DBConnRef
			// to get a new connection from getConnectionInternal()
			$this->modcount++;
		}
	}

	public function disable( $fname = __METHOD__ ) {
		$this->closeAll( $fname );
		$this->disabled = true;
	}

	public function closeAll( $fname = __METHOD__ ) {
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();
		foreach ( $this->getOpenConnections() as $conn ) {
			$conn->close( $fname );
		}

		$this->conns = self::newTrackedConnectionsArray();
	}

	public function closeConnection( IDatabase $conn ) {
		if ( $conn instanceof DBConnRef ) {
			// Avoid calling close() but still leaving the handle in the pool
			throw new RuntimeException( 'Cannot close DBConnRef instance; it must be shareable' );
		}

		$domain = $conn->getDomainID();
		$serverIndex = $conn->getLBInfo( self::INFO_SERVER_INDEX );
		if ( $serverIndex === null ) {
			throw new UnexpectedValueException( "Handle on '$domain' missing server index" );
		}

		$srvName = $this->getServerName( $serverIndex );

		$found = false;
		foreach ( $this->conns as $type => $poolConnsByServer ) {
			$key = array_search( $conn, $poolConnsByServer[$serverIndex] ?? [], true );
			if ( $key !== false ) {
				$found = true;
				unset( $this->conns[$type][$serverIndex][$key] );
			}
		}

		if ( !$found ) {
			$this->connLogger->warning(
				__METHOD__ .
				": orphaned connection to database {$this->stringifyConn( $conn )} at '$srvName'."
			);
		}

		$this->connLogger->debug(
			__METHOD__ .
			": closing connection to database {$this->stringifyConn( $conn )} at '$srvName'."
		);

		$conn->close( __METHOD__ );
	}

	public function commitAll( $fname = __METHOD__ ) {
		$this->commitPrimaryChanges( $fname );
		$this->flushPrimarySnapshots( $fname );
		$this->flushReplicaSnapshots( $fname );
	}

	public function finalizePrimaryChanges( $fname = __METHOD__ ) {
		$this->assertTransactionRoundStage( [ self::ROUND_CURSORY, self::ROUND_FINALIZED ] );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		// Loop until callbacks stop adding callbacks on other connections
		$total = 0;
		do {
			$count = 0; // callbacks execution attempts
			foreach ( $this->getOpenPrimaryConnections() as $conn ) {
				// Run any pre-commit callbacks while leaving the post-commit ones suppressed.
				// Any error should cause all (peer) transactions to be rolled back together.
				$count += $conn->runOnTransactionPreCommitCallbacks();
			}
			$total += $count;
		} while ( $count > 0 );
		// Defer post-commit callbacks until after COMMIT/ROLLBACK happens on all handles
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->setTrxEndCallbackSuppression( true );
		}
		$this->trxRoundStage = self::ROUND_FINALIZED;

		return $total;
	}

	public function approvePrimaryChanges( array $options, $fname = __METHOD__ ) {
		$this->assertTransactionRoundStage( self::ROUND_FINALIZED );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$limit = $options['maxWriteDuration'] ?? 0;

		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			// Any atomic sections should have been closed by now and there definitely should
			// not be any open transactions started by begin() from callers outside Database.
			if ( $conn->explicitTrxActive() ) {
				throw new DBTransactionError(
					$conn,
					"Explicit transaction still active; a caller might have failed to call " .
					"endAtomic() or cancelAtomic()."
				);
			}
			// Assert that the time to replicate the transaction will be reasonable.
			// If this fails, then all DB transactions will be rollback back together.
			$time = $conn->pendingWriteQueryDuration( $conn::ESTIMATE_DB_APPLY );
			if ( $limit > 0 ) {
				if ( $time > $limit ) {
					$humanTimeSec = round( $time, 3 );
					throw new DBTransactionSizeError(
						$conn,
						"Transaction spent {time}s in writes, exceeding the {$limit}s limit",
						// Message parameters for: transaction-duration-limit-exceeded
						[ $time, $limit ],
						null,
						[ 'time' => $humanTimeSec ]
					);
				} elseif ( $time > 0 ) {
					$timeMs = $time * 1000;
					$humanTimeMs = $timeMs > 1 ? round( $timeMs ) : round( $timeMs, 3 );
					$this->perfLogger->debug(
						"Transaction spent {time_ms}ms in writes, under the {$limit}s limit",
						[ 'time_ms' => $humanTimeMs ]
					);
				}
			}
			// If a connection sits idle for too long it might be dropped, causing transaction
			// writes and session locks to be lost. Ping all the server connections before making
			// any attempt to commit the transactions belonging to the active transaction round.
			if ( $conn->writesOrCallbacksPending() || $conn->sessionLocksPending() ) {
				if ( !$conn->ping() ) {
					throw new DBTransactionError(
						$conn,
						"Pre-commit ping failed on server {$conn->getServerName()}"
					);
				}
			}
		}
		$this->trxRoundStage = self::ROUND_APPROVED;
	}

	public function beginPrimaryChanges( $fname = __METHOD__ ) {
		if ( $this->trxRoundId !== false ) {
			throw new DBTransactionError(
				null,
				"Transaction round '{$this->trxRoundId}' already started"
			);
		}
		$this->assertTransactionRoundStage( self::ROUND_CURSORY );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		// Clear any empty transactions (no writes/callbacks) from the implicit round
		$this->flushPrimarySnapshots( $fname );

		$this->trxRoundId = $fname;
		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		// Mark applicable handles as participating in this explicit transaction round.
		// For each of these handles, any writes and callbacks will be tied to a single
		// transaction. The (peer) handles will reject begin()/commit() calls unless they
		// are part of an en masse commit or an en masse rollback.
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$this->applyTransactionRoundFlags( $conn );
		}
		$this->trxRoundStage = self::ROUND_CURSORY;
	}

	public function commitPrimaryChanges( $fname = __METHOD__ ) {
		$this->assertTransactionRoundStage( self::ROUND_APPROVED );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$failures = [];

		$restore = ( $this->trxRoundId !== false );
		$this->trxRoundId = false;
		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		// Commit any writes and clear any snapshots as well (callbacks require AUTOCOMMIT).
		// Note that callbacks should already be suppressed due to finalizePrimaryChanges().
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			try {
				$conn->commit( $fname, $conn::FLUSHING_ALL_PEERS );
			} catch ( DBError $e ) {
				( $this->errorLogger )( $e );
				$failures[] = "{$conn->getServerName()}: {$e->getMessage()}";
			}
		}
		if ( $failures ) {
			throw new DBTransactionError(
				null,
				"Commit failed on server(s) " . implode( "\n", array_unique( $failures ) )
			);
		}
		if ( $restore ) {
			// Unmark handles as participating in this explicit transaction round
			foreach ( $this->getOpenPrimaryConnections() as $conn ) {
				$this->undoTransactionRoundFlags( $conn );
			}
		}
		$this->trxRoundStage = self::ROUND_COMMIT_CALLBACKS;
	}

	public function runPrimaryTransactionIdleCallbacks( $fname = __METHOD__ ) {
		if ( $this->trxRoundStage === self::ROUND_COMMIT_CALLBACKS ) {
			$type = IDatabase::TRIGGER_COMMIT;
		} elseif ( $this->trxRoundStage === self::ROUND_ROLLBACK_CALLBACKS ) {
			$type = IDatabase::TRIGGER_ROLLBACK;
		} else {
			throw new DBTransactionError(
				null,
				"Transaction should be in the callback stage (not '{$this->trxRoundStage}')"
			);
		}
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$oldStage = $this->trxRoundStage;
		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise

		// Now that the COMMIT/ROLLBACK step is over, enable post-commit callback runs
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->setTrxEndCallbackSuppression( false );
		}

		$errors = [];
		$fname = __METHOD__;
		// Loop until callbacks stop adding callbacks on other connections
		do {
			// Run any pending callbacks for each connection...
			$count = 0; // callback execution attempts
			foreach ( $this->getOpenPrimaryConnections() as $conn ) {
				if ( $conn->trxLevel() ) {
					continue; // retry in the next iteration, after commit() is called
				}
				$count += $conn->runOnTransactionIdleCallbacks( $type, $errors );
			}
			// Clear out any active transactions left over from callbacks...
			foreach ( $this->getOpenPrimaryConnections() as $conn ) {
				if ( $conn->writesPending() ) {
					// A callback from another handle wrote to this one and DBO_TRX is set
					$this->queryLogger->warning( $fname . ": found writes pending." );
					$fnames = implode( ', ', $conn->pendingWriteAndCallbackCallers() );
					$this->queryLogger->warning(
						"$fname: found writes pending ($fnames).",
						$this->getConnLogContext(
							$conn,
							[ 'exception' => new RuntimeException() ]
						)
					);
				} elseif ( $conn->trxLevel() ) {
					// A callback from another handle read from this one and DBO_TRX is set,
					// which can easily happen if there is only one DB (no replicas)
					$this->queryLogger->debug( "$fname: found empty transaction." );
				}
				try {
					$conn->commit( $fname, $conn::FLUSHING_ALL_PEERS );
				} catch ( DBError $ex ) {
					$errors[] = $ex;
				}
			}
		} while ( $count > 0 );

		$this->trxRoundStage = $oldStage;

		return $errors[0] ?? null;
	}

	public function runPrimaryTransactionListenerCallbacks( $fname = __METHOD__ ) {
		if ( $this->trxRoundStage === self::ROUND_COMMIT_CALLBACKS ) {
			$type = IDatabase::TRIGGER_COMMIT;
		} elseif ( $this->trxRoundStage === self::ROUND_ROLLBACK_CALLBACKS ) {
			$type = IDatabase::TRIGGER_ROLLBACK;
		} else {
			throw new DBTransactionError(
				null,
				"Transaction should be in the callback stage (not '{$this->trxRoundStage}')"
			);
		}
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$errors = [];
		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->runTransactionListenerCallbacks( $type, $errors );
		}
		$this->trxRoundStage = self::ROUND_CURSORY;

		return $errors[0] ?? null;
	}

	public function rollbackPrimaryChanges( $fname = __METHOD__ ) {
		/** @noinspection PhpUnusedLocalVariableInspection */
		$scope = ScopedCallback::newScopedIgnoreUserAbort();

		$restore = ( $this->trxRoundId !== false );
		$this->trxRoundId = false;
		$this->trxRoundStage = self::ROUND_ERROR; // "failed" until proven otherwise
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->rollback( $fname, $conn::FLUSHING_ALL_PEERS );
		}
		if ( $restore ) {
			// Unmark handles as participating in this explicit transaction round
			foreach ( $this->getOpenPrimaryConnections() as $conn ) {
				$this->undoTransactionRoundFlags( $conn );
			}
		}
		$this->trxRoundStage = self::ROUND_ROLLBACK_CALLBACKS;
	}

	public function flushPrimarySessions( $fname = __METHOD__ ) {
		$this->assertTransactionRoundStage( [ self::ROUND_CURSORY ] );
		if ( $this->hasPrimaryChanges() ) {
			// Any transaction should have been rolled back beforehand
			throw new DBTransactionError( null, "Cannot reset session while writes are pending" );
		}

		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->flushSession( $fname, $conn::FLUSHING_ALL_PEERS );
		}
	}

	/**
	 * @param string|string[] $stage
	 * @throws DBTransactionError
	 */
	private function assertTransactionRoundStage( $stage ) {
		$stages = (array)$stage;

		if ( !in_array( $this->trxRoundStage, $stages, true ) ) {
			$stageList = implode(
				'/',
				array_map( static function ( $v ) {
					return "'$v'";
				}, $stages )
			);
			throw new DBTransactionError(
				null,
				"Transaction round stage must be $stageList (not '{$this->trxRoundStage}')"
			);
		}
	}

	/**
	 * Make all DB servers with DBO_DEFAULT/DBO_TRX set join the transaction round
	 *
	 * Some servers may have neither flag enabled, meaning that they opt out of such
	 * transaction rounds and remain in auto-commit mode. Such behavior might be desired
	 * when a DB server is used for something like simple key/value storage.
	 *
	 * @param Database $conn
	 */
	private function applyTransactionRoundFlags( Database $conn ) {
		if ( $conn->getLBInfo( self::INFO_AUTOCOMMIT_ONLY ) ) {
			return; // transaction rounds do not apply to these connections
		}

		if ( $conn->getFlag( $conn::DBO_DEFAULT ) ) {
			// DBO_TRX is controlled entirely by CLI mode presence with DBO_DEFAULT.
			// Force DBO_TRX even in CLI mode since a commit round is expected soon.
			$conn->setFlag( $conn::DBO_TRX, $conn::REMEMBER_PRIOR );
		}

		if ( $conn->getFlag( $conn::DBO_TRX ) ) {
			$conn->setLBInfo( $conn::LB_TRX_ROUND_ID, $this->trxRoundId );
		}
	}

	/**
	 * @param Database $conn
	 */
	private function undoTransactionRoundFlags( Database $conn ) {
		if ( $conn->getLBInfo( self::INFO_AUTOCOMMIT_ONLY ) ) {
			return; // transaction rounds do not apply to these connections
		}

		if ( $conn->getFlag( $conn::DBO_TRX ) ) {
			$conn->setLBInfo( $conn::LB_TRX_ROUND_ID, null ); // remove the round ID
		}

		if ( $conn->getFlag( $conn::DBO_DEFAULT ) ) {
			$conn->restoreFlags( $conn::RESTORE_PRIOR );
		}
	}

	public function flushReplicaSnapshots( $fname = __METHOD__ ) {
		foreach ( $this->getOpenReplicaConnections() as $conn ) {
			$conn->flushSnapshot( $fname );
		}
	}

	public function flushPrimarySnapshots( $fname = __METHOD__ ) {
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->flushSnapshot( $fname );
		}
	}

	/**
	 * @return string
	 * @since 1.32
	 */
	public function getTransactionRoundStage() {
		return $this->trxRoundStage;
	}

	public function hasPrimaryConnection() {
		return $this->isOpen( $this->getWriterIndex() );
	}

	public function hasPrimaryChanges() {
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			if ( $conn->writesOrCallbacksPending() ) {
				return true;
			}
		}

		return false;
	}

	public function lastPrimaryChangeTimestamp() {
		$lastTime = false;
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$lastTime = max( $lastTime, $conn->lastDoneWrites() );
		}

		return $lastTime;
	}

	public function hasOrMadeRecentPrimaryChanges( $age = null ) {
		$age ??= $this->waitTimeout;

		return ( $this->hasPrimaryChanges()
			|| $this->lastPrimaryChangeTimestamp() > microtime( true ) - $age );
	}

	public function pendingPrimaryChangeCallers() {
		$fnames = [];
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$fnames = array_merge( $fnames, $conn->pendingWriteCallers() );
		}

		return $fnames;
	}

	public function explicitTrxActive() {
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			if ( $conn->explicitTrxActive() ) {
				return true;
			}
		}
		return false;
	}

	public function getLaggedReplicaMode() {
		if ( $this->laggedReplicaMode ) {
			// Stay in lagged replica mode once it is observed on any domain
			return true;
		}

		if ( $this->hasStreamingReplicaServers() ) {
			// This will set "laggedReplicaMode" as needed
			$this->getReaderIndex( self::GROUP_GENERIC );
		}

		return $this->laggedReplicaMode;
	}

	public function laggedReplicaUsed() {
		return $this->laggedReplicaMode;
	}

	public function getReadOnlyReason( $domain = false ) {
		if ( $this->readOnlyReason !== false ) {
			return $this->readOnlyReason;
		} elseif ( $this->isPrimaryRunningReadOnly() ) {
			return 'The primary database server is running in read-only mode.';
		} elseif ( $this->getLaggedReplicaMode() ) {
			$genericIndex = $this->getExistingReaderIndex( self::GROUP_GENERIC );

			return ( $genericIndex !== self::READER_INDEX_NONE )
				? 'The database is read-only until replication lag decreases.'
				: 'The database is read-only until a replica database server becomes reachable.';
		}

		return false;
	}

	/**
	 * @note This method suppresses DBError exceptions in order to avoid severe downtime
	 * @param IDatabase $conn Primary connection
	 * @param int $flags Bitfield of class CONN_* constants
	 * @return bool Whether the entire server or currently selected DB/schema is read-only
	 */
	private function isPrimaryConnectionReadOnly( IDatabase $conn, $flags = 0 ) {
		// Note that table prefixes are not related to server-side read-only mode
		$key = $this->srvCache->makeGlobalKey( 'rdbms-server-readonly', $conn->getServerName() );

		if ( self::fieldHasBit( $flags, self::CONN_REFRESH_READ_ONLY ) ) {
			// Refresh the local server cache. This is useful when the caller is
			// currently in the process of updating a corresponding WANCache key.
			try {
				$readOnly = (int)$conn->serverIsReadOnly();
			} catch ( DBError $e ) {
				$readOnly = 0;
			}
			$this->srvCache->set( $key, $readOnly, BagOStuff::TTL_PROC_SHORT );
		} else {
			$readOnly = $this->srvCache->getWithSetCallback(
				$key,
				BagOStuff::TTL_PROC_SHORT,
				static function () use ( $conn ) {
					try {
						$readOnly = (int)$conn->serverIsReadOnly();
					} catch ( DBError $e ) {
						$readOnly = 0;
					}

					return $readOnly;
				}
			);
		}

		return (bool)$readOnly;
	}

	/**
	 * @note This method suppresses DBError exceptions in order to avoid severe downtime
	 * @return bool Whether the entire primary DB server or the local domain DB is read-only
	 */
	private function isPrimaryRunningReadOnly() {
		// Context will often be HTTP GET/HEAD; heavily cache the results
		return (bool)$this->wanCache->getWithSetCallback(
			// Note that table prefixes are not related to server-side read-only mode
			$this->wanCache->makeGlobalKey(
				'rdbms-server-readonly',
				$this->getPrimaryServerName()
			),
			self::TTL_CACHE_READONLY,
			function () {
				$scope = $this->trxProfiler->silenceForScope();

				$index = $this->getWriterIndex();
				// Refresh the local server cache as well. This is done in order to avoid
				// backfilling the WANCache with data that is already significantly stale
				$flags = self::CONN_SILENCE_ERRORS | self::CONN_REFRESH_READ_ONLY;
				$conn = $this->getServerConnection( $index, self::DOMAIN_ANY, $flags );
				if ( $conn ) {
					try {
						$readOnly = (int)$this->isPrimaryConnectionReadOnly( $conn );
					} catch ( DBError $e ) {
						$readOnly = 0;
					}
				} else {
					$readOnly = 0;
				}

				ScopedCallback::consume( $scope );

				return $readOnly;
			},
			[
				'busyValue' => 0,
				'pcTTL' => WANObjectCache::TTL_PROC_LONG
			]
		);
	}

	public function pingAll() {
		$success = true;
		foreach ( $this->getOpenConnections() as $conn ) {
			if ( !$conn->ping() ) {
				$success = false;
			}
		}

		return $success;
	}

	public function forEachOpenConnection( $callback, array $params = [] ) {
		wfDeprecated( __METHOD__, '1.39' );
		foreach ( $this->getOpenConnections() as $conn ) {
			$callback( $conn, ...$params );
		}
	}

	public function forEachOpenPrimaryConnection( $callback, array $params = [] ) {
		wfDeprecated( __METHOD__, '1.39' );
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$callback( $conn, ...$params );
		}
	}

	/**
	 * Get all open connections
	 * @return \Generator|Database[]
	 */
	private function getOpenConnections() {
		foreach ( $this->conns as $poolConnsByServer ) {
			foreach ( $poolConnsByServer as $serverConns ) {
				foreach ( $serverConns as $conn ) {
					yield $conn;
				}
			}
		}
	}

	/**
	 * Get all open primary connections
	 * @return \Generator|Database[]
	 */
	private function getOpenPrimaryConnections() {
		$primaryIndex = $this->getWriterIndex();
		foreach ( $this->conns as $poolConnsByServer ) {
			/** @var IDatabase $conn */
			foreach ( ( $poolConnsByServer[$primaryIndex] ?? [] ) as $conn ) {
				yield $conn;
			}
		}
	}

	/**
	 * Get open replica connections
	 * @return \Generator|Database[]
	 */
	private function getOpenReplicaConnections() {
		foreach ( $this->conns as $poolConnsByServer ) {
			foreach ( $poolConnsByServer as $serverIndex => $serverConns ) {
				if ( $serverIndex === $this->getWriterIndex() ) {
					continue; // skip primary
				}
				foreach ( $serverConns as $conn ) {
					yield $conn;
				}
			}
		}
	}

	/**
	 * @return int
	 */
	private function getCurrentConnectionCount() {
		$count = 0;
		foreach ( $this->conns as $poolConnsByServer ) {
			foreach ( $poolConnsByServer as $serverConns ) {
				$count += count( $serverConns );
			}
		}

		return $count;
	}

	public function getMaxLag() {
		$host = '';
		$maxLag = -1;
		$maxIndex = 0;

		if ( $this->hasReplicaServers() ) {
			$lagTimes = $this->getLagTimes();
			foreach ( $lagTimes as $i => $lag ) {
				if ( $this->groupLoads[self::GROUP_GENERIC][$i] > 0 && $lag > $maxLag ) {
					$maxLag = $lag;
					$host = $this->getServerInfoStrict( $i, 'host' );
					$maxIndex = $i;
				}
			}
		}

		return [ $host, $maxLag, $maxIndex ];
	}

	public function getLagTimes() {
		if ( !$this->hasReplicaServers() ) {
			return [ $this->getWriterIndex() => 0 ]; // no replication = no lag
		}

		$knownLagTimes = []; // map of (server index => 0 seconds)
		$indexesWithLag = [];
		foreach ( $this->servers as $i => $server ) {
			if ( empty( $server['is static'] ) ) {
				$indexesWithLag[] = $i; // DB server might have replication lag
			} else {
				$knownLagTimes[$i] = 0; // DB server is a non-replicating and read-only archive
			}
		}

		return $this->getLoadMonitor()->getLagTimes( $indexesWithLag ) + $knownLagTimes;
	}

	public function waitForPrimaryPos( IDatabase $conn, $pos = false, $timeout = null ) {
		$timeout = max( 1, $timeout ?: $this->waitTimeout );

		if ( $conn->getLBInfo( self::INFO_SERVER_INDEX ) === $this->getWriterIndex() ) {
			return true; // not a replica DB server
		}

		if ( !$pos ) {
			// Get the current primary DB position, opening a connection only if needed
			$this->replLogger->debug( __METHOD__ . ': no position passed; using current' );
			$index = $this->getWriterIndex();
			$flags = self::CONN_SILENCE_ERRORS;
			$primaryConn = $this->getAnyOpenConnection( $index, $flags );
			if ( $primaryConn ) {
				$pos = $primaryConn->getPrimaryPos();
			} else {
				$primaryConn = $this->getServerConnection( $index, self::DOMAIN_ANY, $flags );
				if ( !$primaryConn ) {
					throw new DBReplicationWaitError(
						null,
						"Could not obtain a primary database connection to get the position"
					);
				}
				$pos = $primaryConn->getPrimaryPos();
				$this->closeConnection( $primaryConn );
			}
		}

		if ( $pos instanceof DBPrimaryPos ) {
			$this->replLogger->debug( __METHOD__ . ': waiting' );
			$result = $conn->primaryPosWait( $pos, $timeout );
			$ok = ( $result !== null && $result != -1 );
			if ( $ok ) {
				$this->replLogger->debug( __METHOD__ . ': done waiting (success)' );
			} else {
				$this->replLogger->debug( __METHOD__ . ': done waiting (failure)' );
			}
		} else {
			$ok = false; // something is misconfigured
			$this->replLogger->error(
				__METHOD__ . ': could not get primary pos for {db_server}',
				$this->getConnLogContext( $conn, [ 'exception' => new RuntimeException() ] )
			);
		}

		return $ok;
	}

	public function setTransactionListener( $name, callable $callback = null ) {
		if ( $callback ) {
			$this->trxRecurringCallbacks[$name] = $callback;
		} else {
			unset( $this->trxRecurringCallbacks[$name] );
		}
		foreach ( $this->getOpenPrimaryConnections() as $conn ) {
			$conn->setTransactionListener( $name, $callback );
		}
	}

	public function setTableAliases( array $aliases ) {
		$this->tableAliases = $aliases;
	}

	public function setIndexAliases( array $aliases ) {
		$this->indexAliases = $aliases;
	}

	public function setDomainAliases( array $aliases ) {
		$this->domainAliases = $aliases;
	}

	public function setLocalDomainPrefix( $prefix ) {
		$oldLocalDomain = $this->localDomain;

		$this->setLocalDomain( new DatabaseDomain(
			$this->localDomain->getDatabase(),
			$this->localDomain->getSchema(),
			$prefix
		) );

		// Update the prefix for existing connections.
		// Existing DBConnRef handles will not be affected.
		foreach ( $this->getOpenConnections() as $conn ) {
			if ( $oldLocalDomain->equals( $conn->getDomainID() ) ) {
				$conn->tablePrefix( $prefix );
			}
		}
	}

	public function redefineLocalDomain( $domain ) {
		$this->closeAll( __METHOD__ );

		$this->setLocalDomain( DatabaseDomain::newFromId( $domain ) );
	}

	public function setTempTablesOnlyMode( $value, $domain ) {
		$old = $this->tempTablesOnlyMode[$domain] ?? false;
		if ( $value ) {
			$this->tempTablesOnlyMode[$domain] = true;
		} else {
			unset( $this->tempTablesOnlyMode[$domain] );
		}

		return $old;
	}

	/**
	 * @param DatabaseDomain $domain
	 */
	private function setLocalDomain( DatabaseDomain $domain ) {
		$this->localDomain = $domain;
	}

	/**
	 * @param int $i Server index
	 * @param string|null $field Server index field [optional]
	 * @return mixed
	 * @throws InvalidArgumentException
	 */
	private function getServerInfoStrict( $i, $field = null ) {
		if ( !isset( $this->servers[$i] ) || !is_array( $this->servers[$i] ) ) {
			throw new InvalidArgumentException( "No server with index '$i'" );
		}

		if ( $field !== null ) {
			if ( !array_key_exists( $field, $this->servers[$i] ) ) {
				throw new InvalidArgumentException( "No field '$field' in server index '$i'" );
			}

			return $this->servers[$i][$field];
		}

		return $this->servers[$i];
	}

	/**
	 * @param IDatabase $conn
	 * @return string Desciption of a connection handle for log messages
	 * @throws InvalidArgumentException
	 */
	private function stringifyConn( IDatabase $conn ) {
		return $conn->getLBInfo( self::INFO_SERVER_INDEX ) . '/' . $conn->getDomainID();
	}

	/**
	 * @return string Name of the primary DB server of the relevant DB cluster (e.g. "db1052")
	 */
	private function getPrimaryServerName() {
		return $this->getServerName( $this->getWriterIndex() );
	}

	/**
	 * @param int $flags A bitfield of flags
	 * @param int $bit Bit flag constant
	 * @return bool Whether the bit field has the specified bit flag set
	 */
	private function fieldHasBit( int $flags, int $bit ) {
		return ( ( $flags & $bit ) === $bit );
	}

	/**
	 * Create a log context to pass to PSR-3 logger functions.
	 *
	 * @param IDatabase $conn
	 * @param array $extras Additional data to add to context
	 * @return array
	 */
	protected function getConnLogContext( IDatabase $conn, array $extras = [] ) {
		return array_merge(
			[
				'db_server' => $conn->getServerName(),
				'db_domain' => $conn->getDomainID()
			],
			$extras
		);
	}

}

/**
 * @deprecated since 1.29
 */
class_alias( LoadBalancer::class, 'LoadBalancer' );
