<?php

namespace Budabot\User\Modules;

use Budabot\Core\CommandReply;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'spawntime',
 *		accessLevel = 'all',
 *		description = 'Show (re)spawntimers',
 *		alias       = 'spawn',
 *		help        = 'spawntime.txt'
 *	)
 */

class SpawntimeController {
	
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		// load database tables from .sql-files
		$this->db->loadSQLFile($this->moduleName, 'spawntime');
	}

	public function getLocationBlob(Spawntime $spawntime) {
		$blob = '';
		foreach ($spawntime->coordinates as $row) {
			$blob .= "<header2>$row->name<end>\n$row->answer";
			if ($row->playfield_id != 0 && $row->xcoord != 0 && $row->ycoord != 0) {
				$blob .= " " . $this->text->makeChatcmd("waypoint: {$row->xcoord}x{$row->ycoord} {$row->short_name}", "/waypoint {$row->xcoord} {$row->ycoord} {$row->playfield_id}");
			}
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("locations (" . count($spawntime->coordinates).")", $blob);
		return $msg;
	}
	
	/**
	 * Return the formatted entry for one mob
	 */
	protected function getMobLine(Spawntime $row, $displayDirectly): string {
		$line = "<highlight>" . $row->mob . "<end>: ";
		if ($row->spawntime !== null) {
			$line .= "<orange>" . strftime('%Hh%Mm%Ss', $row->spawntime) . "<end>";
		} else {
			$line .= "<orange>&lt;unknown&gt;<end>";
		}
		$line = preg_replace('/00[hms]/', '', $line);
		$line = preg_replace('/>0/', '>', $line);
		$flags = [];
		if ($row->can_skip_spawn) {
			$flags[] = 'can skip spawn';
		}
		if (strlen($row->placeholder)) {
			$flags[] = "placeholder: " . $row->placeholder;
		}
		if (count($flags)) {
			$line .= ' (' . join(', ', $flags) . ')';
		}
		if ($displayDirectly === true && count($row->coordinates)) {
			$line .= " [" . $this->getLocationBlob($row) . "]";
		} elseif (count($row->coordinates) > 1) {
			$line .= " [" .
				$this->text->makeChatcmd(
					"locations (" . count($row->coordinates) . ")",
					"/tell <myname> whereis " . $row->mob
				).
				"]";
		} elseif (count($row->coordinates) === 1) {
			$coords = $row->coordinates[0];
			if ($coords->playfield_id != 0 && $coords->xcoord != 0 && $coords->ycoord != 0) {
				$line .= " [".
					$this->text->makeChatcmd(
						"{$coords->xcoord}x{$coords->ycoord} {$coords->short_name}",
						"/waypoint {$coords->xcoord} {$coords->ycoord} {$coords->playfield_id}"
					).
					"]";
			}
		}
		return $line;
	}
	
	/**
	 * Command to add spawndata for a new mob
	 *
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("spawntime")
	 * @Matches("/^spawntime add\s+(.+)?\s+((?:\d+h)?(?:\d+m)?(?:\d+s)?)\s+(yes|no|1|0|true|false|ja|nein)(\s+.+)?$/i")
	 */
	/*
	public function spawntimeAddCommand($message, $channel, $sender, $sendto, $args) {
		$mob = trim($args[1]);
		$placeHolder = trim($args[4]);
		$spawntime = $this->util->parseTime($args[2]);
		if ($spawntime === 0) {
			$msg = 'Cannot parse the given time string.';
			$sendto->reply($msg);
			return;
		}
		$sql = 'SELECT * FROM spawntime WHERE LOWER(mob) = ?';
		if ($this->db->queryRow($sql, strtolower($mob))) {
			$msg = 'Information about spawntimes of '.
				'<highlight>' . $mob . '<end>'.
				' is already present.';
			$sendto->reply($msg);
			return;
		}
		$sql = 'INSERT INTO spawntime '.
			'(mob, placeholder, can_skip_spawn, spawntime) '.
			'VALUES (?, ?, ?, ?)';
		$inserted = $this->db->exec(
			$sql,
			$mob,
			$placeHolder,
			in_array($args[3], ['yes', '1', 'true', 'ja']),
			$spawntime
		);
		if ($inserted < 1) {
			$msg = 'There was an error saving your spawn definition for <highlight>'.
				$mob.
				'<end>.';
			$sendto->reply($msg);
			return;
		}
		$msg = 'Spawntime for <highlight>' . $mob . '<end> saved.';
		$sendto->reply($msg);
	}
	*/

	/**
	 * Command to list all Spawntimes
	 *
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("spawntime")
	 * @Matches("/^spawntime(\s+.+)?$/i")
	 */
	public function spawntimeListCommand($message, $channel, $sender, $sendto, $args) {
		$args[1] = trim($args[1]);
		if (strlen($args[1]) > 0) {
			$tokens = array_map(
				function($token) {
					return "%$token%";
				},
				explode(" ", $args[1])
			);
			$sql = "SELECT s.*, w.*, p.short_name, p.long_name ".
				"FROM spawntime s ".
				"LEFT JOIN whereis w ON ";
			if ($this->db->getType() === $this->db::MYSQL) {
				$sql .= "(LOWER(w.name) LIKE CONCAT(LOWER(s.mob), '%'))";
			} else {
				$sql .= "(LOWER(w.name) LIKE LOWER(s.mob) || '%')";
			}
			$sql .= " LEFT JOIN playfields p ON (p.id=w.playfield_id) ".
				"WHERE ";
			$partsMob = array_fill(0, count($tokens), "mob LIKE ?");
			$partsPlaceholder = array_fill(0, count($tokens), "placeholder LIKE ?");
			$sql .= "(" . join(" AND ", $partsMob).")".
				" OR ".
				"(" . join(" AND ", $partsPlaceholder) . ") ".
				"ORDER BY mob ASC";
			$allTimes = $this->db->query($sql, ...array_merge($tokens, $tokens));
		} else {
			$sql = "SELECT * FROM spawntime ORDER BY mob ASC";
			$allTimes = $this->db->query($sql);
		}
		if (!count($allTimes)) {
			$msg = 'There are currently no spawntimes in the database.';
			if (strlen($args[1]) > 0) {
				$msg = 'No spawntime matching <highlight>' . $args[1] . '<end>.';
			}
			$sendto->reply($msg);
			return;
		}
		$oldMob = null;
		$allData = array();
		foreach ($allTimes as $data) {
			if ($oldMob !== null && $oldMob->mob !== $data->mob) {
				$allData []= $oldMob;
				$oldMob = null;
			}
			if ($oldMob === null) {
				$oldMob = new Spawntime($data);
			}
			if (strlen($args[1]) > 0) {
				$oldMob->coordinates []= new WhereisCoordinates($data);
			}
		}
		$allData []= $oldMob;
		$allTimes = $allData;
		$displayDirectly = count($allTimes) < 4 && (strlen($args[1]) > 0);
		$timeLines = array_map(
			[$this,'getMobLine'],
			$allTimes,
			array_fill(0, count($allTimes), $displayDirectly)
		);
		if (count($timeLines) === 1) {
			$msg = $timeLines[0];
		} elseif ($displayDirectly) {
			$msg = "Spawntimes matching <highlight>" . $args[1] . "<end>:\n".
				join("\n", $timeLines);
		} else {
			$msg = $this->text->makeBlob('All known spawntimes', join("\n", $timeLines));
		}
		$sendto->reply($msg);
	}
}

class Spawntime {
	/** @var string $mob */
	public $mob;

	/** @var string $placeholder */
	public $placeholder;

	/** @var bool $can_skip_spawn */
	public $can_skip_spawn;

	/** @var int $spawntime */
	public $spawntime;

	/** @var \Budabot\User\Modules\WhereisCoordinates */
	public $coordinates = array();

	public function __construct(\Budabot\Core\DBRow $row) {
		$this->mob = $row->mob;
		$this->placeholder = $row->placeholder;
		$this->can_skip_spawn = (bool)$row->can_skip_spawn;
		$this->spawntime = $row->spawntime ? (int)$row->spawntime : null;
	}
}

class WhereisCoordinates {
	/** @var string */
	public $name;

	/** @var string */
	public $answer;

	/** @var string */
	public $keywords;

	/** @var int */
	public $playfield_id;

	/** @var string */
	public $short_name;

	/** @var string */
	public $long_name;

	/** @var int */
	public $xcoord;

	/** @var int */
	public $ycoord;

	public function __construct(\Budabot\Core\DBRow $row) {
		$this->name = $row->name;
		$this->answer = $row->answer;
		$this->keywords = $row->keywords;
		$this->playfield_id = $row->playfield_id;
		$this->xcoord = $row->xcoord;
		$this->ycoord = $row->ycoord;
		$this->short_name = $row->short_name;
		$this->long_name = $row->long_name;
	}
}
