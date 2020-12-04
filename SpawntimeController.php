<?php declare(strict_types=1);

namespace Nadybot\User\Modules\SPAWNTIME_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\WHEREIS_MODULE\WhereisCoordinates;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
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
	
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadSQLFile($this->moduleName, 'spawntime');
	}

	/**
	 * @return string[]
	 */
	public function getLocationBlob(Spawntime $spawntime): string {
		$blob = '';
		foreach ($spawntime->coordinates as $row) {
			$blob .= "<header2>$row->name<end>\n$row->answer";
			if ($row->playfield_id !== 0 && $row->xcoord !== 0 && $row->ycoord !== 0) {
				$blob .= " " . $this->text->makeChatcmd("waypoint: {$row->xcoord}x{$row->ycoord} {$row->short_name}", "/waypoint {$row->xcoord} {$row->ycoord} {$row->playfield_id}");
			}
			$blob .= "\n\n";
		}
		return $this->text->makeBlob("locations (" . count($spawntime->coordinates).")", $blob);
	}
	
	/**
	 * Return the formatted entry for one mob
	 */
	protected function getMobLine(Spawntime $row, bool $displayDirectly): string {
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
		if (strlen($row->placeholder??"")) {
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
	 * Command to list all Spawntimes
	 *
	 * @HandlesCommand("spawntime")
	 * @Matches("/^spawntime?$/i")
	 */
	public function spawntimeListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM spawntime s ".
			"LEFT JOIN whereis w ON ";
		if ($this->db->getType() === $this->db::MYSQL) {
			$sql .= "(LOWER(w.name) LIKE CONCAT(LOWER(s.mob), '%'))";
		} else {
			$sql .= "(LOWER(w.name) LIKE LOWER(s.mob) || '%')";
		}
		$sql .= " LEFT JOIN playfields p ON (p.id=w.playfield_id) ORDER BY mob ASC";
		/** @var Spawntime[] */
		$allTimes = $this->db->fetchAll(Spawntime::class, $sql);
		if (!count($allTimes)) {
			$msg = 'There are currently no spawntimes in the database.';
			$sendto->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($allTimes);
		$msg = $this->text->makeBlob('All known spawntimes', join("\n", $timeLines));
		$sendto->reply($msg);
	}

	/**
	 * Command to list all Spawntimes
	 *
	 * @HandlesCommand("spawntime")
	 * @Matches("/^spawntime (.+)$/i")
	 */
	public function spawntimeSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[1] = trim($args[1]);
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
		$allTimes = $this->db->fetchAll(Spawntime::class, $sql, ...[...$tokens, ...$tokens]);
		if (!count($allTimes)) {
			$msg = "No spawntime matching <highlight>{$args[1]}<end>.";
			$sendto->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($allTimes);
		$count = count($timeLines);
		if ($count === 1) {
			$msg = $timeLines[0];
		} elseif ($count < 4) {
			$msg = "Spawntimes matching <highlight>{$args[1]}<end>:\n".
				join("\n", $timeLines);
		} else {
			$msg = $this->text->makeBlob("Spawntimes for \"{$args[1]}\" ($count)", join("\n", $timeLines));
		}
		$sendto->reply($msg);
	}

	/**
	 * @param Spawntime[] $spawntimes
	 * @return string[]
	 */
	protected function spawntimesToLines(array $spawntimes): array {
		$oldMob = null;
		$allData = [];
		foreach ($spawntimes as $spawntime) {
			if ($oldMob !== null && $oldMob->mob !== $spawntime->mob) {
				$allData []= $oldMob;
				$oldMob = null;
			}
			if ($oldMob === null) {
				$oldMob = $spawntime;
			}
			if (isset($spawntime->answer)) {
				$oldMob->coordinates []= new WhereisCoordinates($spawntime);
			}
		}
		$allData []= $oldMob;
		$spawntimes = $allData;
		$displayDirectly = count($spawntimes) < 4;
		$timeLines = array_map(
			[$this,'getMobLine'],
			$spawntimes,
			array_fill(0, count($spawntimes), $displayDirectly)
		);
		return $timeLines;
	}
}
