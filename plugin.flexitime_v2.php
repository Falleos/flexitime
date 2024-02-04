<?php
/*
 * xaseco flexitime plugin.
 * ----------------------------------------------------------------------------
 * Flexible time limit for tracks. Admins can dynamically adjust the remaining 
 * time using the /timeleft chat command. Moreover, the plugin includes a 
 * whitelist functionality, enabling selected players to utilize the /tl 
 * command for emergency time extensions.
 * ----------------------------------------------------------------------------
 * Copyright (c) 2015-2016 Tony Houghton ("realh")
 * Additional contributions by:
 * - falleos (v2.0, 02.02.2024)
 * ----------------------------------------------------------------------------
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 * ----------------------------------------------------------------------------
 */

Aseco::registerEvent("onStartup", "realh_flexitime_startup");
Aseco::registerEvent("onBeginRound", "realh_flexitime_begin_round");
Aseco::registerEvent("onEndRound", "realh_flexitime_end_round");
Aseco::registerEvent("onEverySecond", "realh_flexitime_tick");

Aseco::addChatCommand("timeleft",
	"Change or query time left: /timeleft [[+|-]MINUTES]|[pause|resume]");

Aseco::addChatCommand("tl",
	"Quickly add emergency time");
	
Aseco::addChatCommand("whitelist",
	"Whitelist management. check /whitelist help", true);

// You can comment out the next two lines if CUSTOM_TIME is false
Aseco::addChatCommand("timeset", "Sets custom timelimit in minutes for when " .
	"this track is played in future.");

global $realh_flexitime;

class FlexiTime {

	private $VERSION = "2.0.1";

	/* CONFIG OPTIONS, SET IN flexitime.xml */

	private $ADMIN_LEVEL = 1;

	private $CLOCK_COLOUR = "fff";

	private $WARN_TIME = 300;

	private $WARN_COLOUR = "ff4";

	private $DANGER_TIME = 60;

	private $DANGER_COLOUR = "f44";

	// Default time limit in minutes (maximum time if AUTHOR_MULT is not 0)
	private $DEFAULT_TIME = 120;
	
	// Upper time limit in minutes (0 - no limit)
	private $MAX_TIME = 1440;

	// Whether to use custom time database and /timeset
	private $CUSTOM_TIME = true;

	// Default time for each track is AUTHOR_MULT x track's author time
	// or 0 to use $DEFAULT_TIME
	private $AUTHOR_MULT = 0;

	// Minimum time in minutes if using $AUTHOR_MULT
	private $MIN_TIME = 15;

	// Whether to send chat messages showing time left at regular intervals
	private $USE_CHAT = false;

	// Whether to show time in a panel
	private $SHOW_PANEL = true;
	
	// Time in minutes to add using the /tl command
	private $EMERGENCY_TIME = 30;
	
	// Below what time in minutes can /tl be used (0 - no limit)
	private $EMERGENCY_MIN = 10;
	
	private $WHITELIST_ADMIN_LEVEL = 1;
	
	// Only users with logins in this array will be able to add emergency time
	private $WHITELIST = array();

	/* End of CONFIG OPTIONS */

	private $aseco;
	private $time_left;
	private $author_time;
	private $paused;

	public function FlexiTime($aseco) {
		$this->aseco = $aseco;

		$xml = $aseco->xml_parser->parseXml("flexitime.xml");
		if ($xml && isset($xml['FLEXITIME'])) {
			$xml = $xml['FLEXITIME'];
			$this->ADMIN_LEVEL = $this->intFromXml($this->ADMIN_LEVEL, $xml, 'ADMIN_LEVEL');
			$this->WHITELIST_ADMIN_LEVEL = $this->intFromXml($this->WHITELIST_ADMIN_LEVEL, $xml, 'WHITELIST_ADMIN_LEVEL');
			$whitelist = $this->fromXml(null, $xml, 'WHITELIST');
			if ($whitelist) {
				$login = $whitelist['LOGIN'];
				if (is_array($whitelist)) $this->WHITELIST = $login;
			} else {
				$this->WHITELIST = [];
			}
			$this->EMERGENCY_TIME = $this->intFromXml($this->EMERGENCY_TIME, $xml, 'EMERGENCY_TIME');
			$this->EMERGENCY_MIN = $this->intFromXml($this->EMERGENCY_MIN, $xml, 'EMERGENCY_MIN');
			$this->DEFAULT_TIME = $this->intFromXml($this->DEFAULT_TIME, $xml, 'DEFAULT_TIME');
			$this->MAX_TIME = $this->intFromXml($this->MAX_TIME, $xml, 'MAX_TIME');
			$this->CUSTOM_TIME = $this->boolFromXml($this->CUSTOM_TIME, $xml, 'CUSTOM_TIME');
			$this->AUTHOR_MULT = $this->intFromXml($this->AUTHOR_MULT, $xml, 'AUTHOR_MULT');
			$this->MIN_TIME = $this->intFromXml($this->MIN_TIME, $xml, 'MIN_TIME');
			$this->USE_CHAT = $this->boolFromXml($this->USE_CHAT, $xml, 'USE_CHAT');
			$this->SHOW_PANEL = $this->boolFromXml($this->SHOW_PANEL, $xml, 'SHOW_PANEL');
			$this->CLOCK_COLOUR = $this->fromXml($this->CLOCK_COLOUR, $xml, 'COLOUR');
			$this->WARN_TIME = $this->intFromXml($this->WARN_TIME, $xml, 'WARN_TIME');
			$this->WARN_COLOUR = $this->fromXml($this->WARN_COLOUR, $xml, 'WARN_COLOUR');
			$this->DANGER_TIME = $this->intFromXml($this->DANGER_TIME, $xml, 'DANGER_TIME');
			$this->DANGER_COLOUR = $this->fromXml($this->DANGER_COLOUR, $xml, 'DANGER_COLOUR');
		} else {
			$aseco->console('[flexitime.php] flexitime.xml is missing or does not contain a <flexitime> tag');
		}

		$this->initTimer();

		$this->showChatMsg('Started flexitime v' . $this->VERSION);
	}

	private function fromXml($default, $xml, $tag) {
		$v = $xml[$tag];
		if (isset($v) && isset($v[0])) {
			return $v[0];
		}
		/*
		if (isset($v)) {
			print($tag . " is set but doesn't contain [0]; it's: " .
				print_r($v, false) . "\n");
		} else {
			print("No " . $tag . " in xml\n");
		}
		 */
		return $default;
	}

	private function intFromXml($default, $xml, $tag) {
		return intval($this->fromXml($default, $xml, $tag));
	}

	private function boolFromXml($default, $xml, $tag) {
		return $this->intFromXml($default, $xml, $tag) ? true : false;
	}

	public function initTimer() {
		$this->paused = false;

		$custom = $this->CUSTOM_TIME;
		$challenge = $this->getTrackInfo();
		$this->author_time = round($challenge->authortime / 1000);
		if ($custom) {
			$result = $this->arrayQuery(
				"SELECT tracktime FROM custom_tracktimes WHERE " .
				"challenge_uid='" .	 $challenge->uid . "';");
			if (!empty($result)) {
				$timelimit = split(":", trim($result[0]['tracktime']));
				$this->time_left = $timelimit[0] * 60 + $timelimit[1];
			} else {
				$custom = false;
			}
		}
		if (!$custom) {
			if ($this->AUTHOR_MULT) {
				$this->time_left = ceil($challenge->authortime / 60000 *
					$this->AUTHOR_MULT) * 60;
				if ($this->time_left > $this->DEFAULT_TIME * 60) {
					$this->time_left = $this->DEFAULT_TIME * 60;
				} elseif ($this->time_left < $this->MIN_TIME * 60) {
					$this->time_left = $this->MIN_TIME * 60;
				}
			} else {
				$this->time_left = $this->DEFAULT_TIME * 60;
			}
		}
		$this->showPanel();
		if ($this->USE_CHAT)
			$this->showTimeLeftInChat();
	}

	public function commandTimeLeft($command, $emergency) {
		$param = trim($command["params"]);
		$login = $command["author"]->login;
		$nickname = $command["author"]->nickname;
		
		if (!$emergency && empty($param)) {
			$this->showPrivateMsg($login, $this->getTimeLeftText());
		} else {
			if ($emergency && in_array($login, $this->WHITELIST)) {
				if ($this->EMERGENCY_MIN != 0 && $this->time_left > $this->EMERGENCY_MIN * 60) {
					$this->showPrivateMsg($login,
						"Emergency time not added: current timer is over {$this->EMERGENCY_MIN} minutes.");
				} else {
					$this->time_left = $this->time_left + ($this->EMERGENCY_TIME * 60);
					$this->showPanel();
					$this->showChatMsg($nickname . " \$z\$s\$fffadded emergency time: " .
						$this->getTimeLeftText());
				}
			} elseif ($this->authenticateCommand($command)) {
				if (!strcasecmp($param, "pause")) {
					$this->paused = true;
					$this->showChatMsg($nickname . " \$z\$s\$fffpaused the timer.");
					return;
				} elseif (!strcasecmp($param, "resume")) {
					$this->paused = false;
					$this->showChatMsg($nickname . " \$z\$s\$fffunpaused the timer.");
					return;
				}
				
				$plus = false;
				$minus = false;
				if ($emergency) {
					$val = $this->EMERGENCY_TIME * 60;
					$plus = true;
				} else {
					$plus = ($param[0] === "+");
					$minus = ($param[0] === "-");
					$val = $param;
					if ($plus || $minus) {
						$val = substr($val, 1);
					}
					$val = intval($val);
					if (!$val && !($param === "0")) {
						$this->showPrivateMsg($login,
							"Invalid parameter to /timeleft.");
						return;
					}
					$val *= 60;
				}

				$tl = $this->time_left;
				if ($plus) {
					$tl += $val;
				} elseif ($minus) {
					$tl -= $val;
				} else {
					$tl = $val;
				}

				if ($this->MAX_TIME != 0 && $tl > ($this->MAX_TIME * 60)) {
					$this->showPrivateMsg($login,
						"Time limit over ".$this->MAX_TIME." minutes is not allowed.");
					$tl = $this->MAX_TIME * 60;
				}
				if ($tl < 0) {
					$this->showPrivateMsg($login,
						"Can't set remaining time to less than zero.");
				}
				else
				{
					$this->time_left = $tl;
					$this->showPanel();
					$this->showChatMsg($nickname . " \$z\$s\$fffchanged time left: " .
						$this->getTimeLeftText());
					if ($this->time_left == 0) {
						$this->nextRound();
					}
				}
			}
		}
	}

	public function commandTimeSet($command) {
		// TODO: Allow (non-admin) users to query current value
		// if no param is given
		$login = $command["author"]->login;
		$nickname = $command["author"]->nickname;
		if (!$this->CUSTOM_TIME) {
			$this->showPrivateMsg($login,
				"/timeset command not enabled in plugin config.");
			return;
		}
		if (!$this->authenticateCommand($command)) {
			return;
		}
		$param = intval(trim($command["params"]));
		if (!$param) {
			$this->showPrivateMsg($login,
				"Usage (where 120 is number of minutes): /timeset 120");
			return;
		}
		$challenge = $this->getTrackInfo();
		$uid = $challenge->uid;
		// Would be better if challenge_uid was unique key, but want to be
		// backwards compatible with custom_time plugin's database
		$result = $this->arrayQuery(
			"SELECT * FROM custom_tracktimes WHERE challenge_uid = '" .
			$uid . "';");
		if (empty($result)) {
			mysql_query("INSERT INTO custom_tracktimes (challenge_uid, " .
				"tracktime) VALUES ('" .  $uid . "','" . $param . "');");
		} else {
			mysql_query("UPDATE custom_tracktimes SET tracktime='" . $param .
				"' WHERE challenge_uid='" . $uid . "';");
		}
		$this->showChatMsg($nickname . " \$z\$s\$fffset future time for this track to " .
			$param . " minutes.");
	}

	public function tick() {
		if (!$this->paused && $this->time_left > 0) {
			--$this->time_left;
		}
		$secs = $this->time_left;
		$mins = floor($secs / 60);
		$secs = $secs % 60;
		$this->showPanel();
		if ($USE_CHAT && !$this->paused && ((!$secs &&
				(!($mins % 10) || ($mins < 60 && !($mins % 5)) || $mins == 1))
			|| (!$mins && ($secs == 30 || $secs == 10 || $secs == 0))))
		{
			$this->showTimeLeftInChat();
		}
		if (!$this->paused && $this->time_left <= 0) {
			$this->nextRound();
		}
	}

	private function authenticateCommand($command) {
		$user = $command["author"];
		$login = $user->login;
		if ($this->ADMIN_LEVEL == 4 ||
			($this->aseco->isMasterAdmin($user) && $this->ADMIN_LEVEL > 0) ||
			($this->aseco->isAdmin($user) && $this->ADMIN_LEVEL > 1) ||
			($this->aseco->isOperator($user) && $this->ADMIN_LEVEL > 2)) {
			return true;
		} else {
			$this->showPrivateMsg($login,
				"\$f00\$iYou do not have permission to change the remaining time.");
		}
		return false;
	}

	private function nextRound() {
		$this->paused = true;
		$this->aseco->client->query("NextChallenge");
	}

	private function getTimeLeftText() {
		$t = $this->getTimeLeftAsString();
		$suf = ($this->time_left >= 3600) ? " (h:m:s)" : " (m:s)";
		$status = $this->paused ? " (paused)." : ".";
		return $t . $suf . " until round end" . $status;
	}

	private function showTimeLeftInChat() {
		$this->showChatMsg($this->getTimeLeftText());
	}

	private function showChatMsg($msg) {
		$this->aseco->client->query("ChatSendServerMessage", "> " . $msg);
	}

	private function showPrivateMsg($login, $msg) {
		$this->aseco->client->query("ChatSendServerMessageToLogin",
			$msg, $login);
	}

	private function getTrackInfo() {
		$aseco = $this->aseco;
		$aseco->client->query('GetCurrentChallengeIndex');
		$trkid = $aseco->client->getResponse();
		$rtn = $aseco->client->query('GetChallengeList', 1, $trkid);
		$track = $aseco->client->getResponse();
		$rtn = $aseco->client->query('GetChallengeInfo', $track[0]['FileName']);
		$trackinfo = $aseco->client->getResponse();
		return new Challenge($trackinfo);
	}

	private function arrayQuery($query) 
	{
		$q = mysql_query($query);
		$error = mysql_error();
		if (strlen($error)) {
			$this->aseco->console("[flexitime.php] Error with flexitime's MYSQL query" . $error);
			return null;
		}
		while(true) {
			$row = mysql_fetch_assoc($q);
			if (!$row) {
				break;
			}
			$data[]=$row;
		}	
		mysql_free_result($q);
		return $data;
	}

	private function showHud($body) {
		if (!$this->SHOW_PANEL) {
			return;
		}
		// Arbitrary id = ('r' << 8) | 'h'
		$hud = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<manialink id="29288">' . $body . '</manialink>';
		$this->aseco->client->query("SendDisplayManialinkPage", $hud, 0, false);
	}

	private function showPanel() {
		if (!$this->SHOW_PANEL) {
			return;
		}
		$s = $this->time_left % 60;
		$m = floor($this->time_left / 60);
		$h = floor($m / 60);
		$m %= 60;
		if ($h) {
			$h = sprintf("%02d:", $h);
		} else {
			$h = "";
		}
		$colour = $this->CLOCK_COLOUR;
		if ($this->time_left < $this->DANGER_TIME)
			$colour = $this->DANGER_COLOUR;
		elseif ($this->time_left < $this->WARN_TIME ||
			$this->time_left < $this->author_time)
			$colour = $this->WARN_COLOUR;

		$showtime = $this->getTimeLeftAsString();
		$xpos = $this->paused ? "120" : "60";
		$this->showHud(
			'<frame scale="1" posn="' . $xpos . ' 20">' .
			'<quad posn="8 0 0" sizen="18 5 0.08" halign="right" ' .
			'valign="center" style="BgsPlayerCard" ' .
			'substyle="BgPlayerCardBig"/>' .
			'<label posn="3.5 0.1 0.1" halign="right" valign="center" ' .
			'scale="0.6"' .
			'style="TextRaceChrono" text="$s$' . $colour . $showtime . '"/>' .
			'</frame>');
	}

	private function getTimeLeftAsString() {
		$s = $this->time_left % 60;
		$m = floor($this->time_left / 60);
		$h = floor($m / 60);
		$m %= 60;
		if ($h) {
			$h = sprintf("%02d:", $h);
		} else {
			$h = "";
		}
		return $h . sprintf("%02d:%02d", $m, $s);
	}

	public function hidePanel() {
		$this->paused = true;
		$this->showHud("");
	}
	
	public function commandWhitelist($command) {
		$player = $command["author"];
		$login = $player->login;
		$nickname = $player->nickname;
		
		// split params into arrays & insure optional parameters exist
		$params = explode(' ', preg_replace('/ +/', ' ', $command['params']));
		if (!isset($params[1])) $params[1] = '';
		
		if (!($this->aseco->isMasterAdmin($player) && $this->WHITELIST_ADMIN_LEVEL >= 1) &&
			!($this->aseco->isAdmin($player) && $this->WHITELIST_ADMIN_LEVEL == 2)) {
			$this->showPrivateMsg($login,
				"\$f00\$iYou do not have permission to use /whitelist command.");
		} else {
			if ($params[0] == '') {
				$this->showWhitelistManialink($player);
			} elseif ($params[0] == 'help') {
				$this->showHelpManialink($login);
			} elseif ($params[0] == 'reload') {
				$this->reloadWhitelist();
				$this->showPrivateMsg($login, "Whitelist successfully reloaded.");
			} elseif ($params[0] == 'add') {
				if ($params[1] == '') {
					$this->showPrivateMsg($login, "\$f00Error: \$fffLogin is not specified.");
					return;
				}
				
				$target = $this->getTarget($params[1], $player, true);
				
				if (in_array($params[1], $this->WHITELIST)) {
					$this->showPrivateMsg($login,
						"\$f00Error: \$fff" . $target . " \$z\$s\$fffis already whitelisted.");
				} else {
					$this->addToWhitelist($params[1]);
					$this->showChatMsg($nickname . " \$z\$s\$fffadded " . $target . " \$z\$s\$fffto the whitelist");
				}
			} elseif ($params[0] == 'remove') {
				if ($params[1] == '') {
					$this->showPrivateMsg($login, "\$f00Error: \$fffLogin is not specified.");
					return;
				}
				
				$target = $this->getTarget($params[1]);
				
				if (!in_array($params[1], $this->WHITELIST)) {
					$this->showPrivateMsg($login,
						"\$f00Error: \$fff" . $target . " \$z\$s\$fffis not whitelisted.");
				} else {
					$this->removeFromWhitelist($params[1]);
					$this->showChatMsg($nickname . " \$z\$s\$fffremoved " . $target . " \$z\$s\$ffffrom the whitelist");
				}
			} else {
				$this->showPrivateMsg($login, 
					"\$f00Error: \$fffUnknown parameter. Use \$0bf/whitelist help \$ffffor more information.");
			}
		}
	}
	
	private function getTarget($login, $player = null, $showMsg = false) {
		$query = 'SELECT NickName FROM players WHERE login=' . quotedString($login); 
		$result = mysql_query($query);
		// target's nickname if target is in db
		if (mysql_num_rows($result) > 0) {
			$row = mysql_fetch_object($result);
			$target = $row->NickName;
		// otherwise specified login and warning that login is not found in db 
		} else {
			$target = $login;
			if ($showMsg) {
				$this->showPrivateMsg($player->login, 
					"\$f00Warning: \$fffLogin not found in the database.");
			}
		}
		mysql_free_result($result);
		
		return $target;
	}
	
	private function showHelpManialink($login) {
		$header = '{#black}/whitelist$g displays a window with a list of all whitelisted players';

		$help = array();
		$help[] = array('...', '{#black}add <login>',
						'Adds a player to the whitelist');
		$help[] = array('...', '{#black}remove <login>',
						'Removes a player from the whitelist');
		$help[] = array('...', '{#black}reload',
						'Reloads the whitelist');
		$help[] = array('...', '{#black}help',
						'Displays this help window');
		$help[] = array();
		$help[] = array('(!)', '{#black}reload', 'Required only after making manual changes');
		$help[] = array();
		
		display_manialink($login, $header, array('Icons64x64_1', 'TrackInfo', -0.02), $help, array(1.1, 0.05, 0.29, 0.05), 'OK');
	}
	
	private function showWhitelistManialink($player) {
		$head = 'Whitelisted players:';
		$list = array();
		$lines = 0;
		$player->msgs = array();
		$player->msgs[0] = array(1, $head, array(0.7), array('Icons128x128_1', 'Buddies', 0.01));

		foreach ($this->WHITELIST as $whitelisted) {
			$list[] = array('{#black}' . $whitelisted . ' $000/$6cf ' . $this->getTarget($whitelisted));
			if (++$lines > 14) {
				$player->msgs[] = $list;
				$lines = 0;
				$list = array();
			}
		}
		
		if (!empty($list)) {
			$player->msgs[] = $list;
		} else {
			$player->msgs[] = "";
		}

		display_manialink_multi($player);
	}
	
	private function addToWhitelist($login) {
		$xml = simplexml_load_file('flexitime.xml');
		if ($xml === false) {
			$this->aseco->console('[flexitime.php] cannot load flexitime.xml');
			return;
		}
		$xml = $xml->asXML();
		$dom = new DOMDocument();
		$dom->loadXML($xml);

		$whitelist = $dom->getElementsByTagName('whitelist')->item(0);
		$tabNode = $dom->createTextNode("\t");
		$whitelist->appendChild($tabNode);
		
		$newLogin = $dom->createElement('login', $login);
		$whitelist->appendChild($newLogin);
		
		$newlineTabNode = $dom->createTextNode("\n\t");
		$whitelist->appendChild($newlineTabNode);

		file_put_contents('flexitime.xml', $dom->saveXML()); 
		$this->reloadWhitelist();
	}
	
	private function removeFromWhitelist($login) {
		// TODO: remove blank lines after login removal
		$xml = simplexml_load_file('flexitime.xml');
		if ($xml === false) {
			$this->aseco->console('[flexitime.php] cannot load flexitime.xml');
			return;
		}
		$xml = $xml->asXML();
		$dom = new DOMDocument();
		$dom->loadXML($xml);		
		$xpath = new DOMXPath($dom);
		
		$loginNodes = $xpath->query("//whitelist/login[text()='$login']");
		foreach ($loginNodes as $loginNode) {
			$loginNode->parentNode->removeChild($loginNode);
		}

		file_put_contents('flexitime.xml', $dom->saveXML());
		$this->reloadWhitelist();
	}
	
	private function reloadWhitelist() {
		$xml = $this->aseco->xml_parser->parseXml("flexitime.xml");
		if ($xml && isset($xml['FLEXITIME'])) {
			$xml = $xml['FLEXITIME'];
			$whitelist = $this->fromXml(null, $xml, 'WHITELIST');				 
			if ($whitelist) {
				$login = $whitelist['LOGIN'];
				if (is_array($whitelist)) $this->WHITELIST = $login;
			} else {
				$this->WHITELIST = [];
			}		
		} else {
			$this->aseco->console('[flexitime.php] flexitime.xml is missing or does not contain a <flexitime> tag');
		}
	}
}


function realh_flexitime_startup($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime = new FlexiTime($aseco);
}

function realh_flexitime_begin_round($aseco) {
	global $realh_flexitime;
	$realh_flexitime->initTimer();
}

function realh_flexitime_end_round($aseco) {
	global $realh_flexitime;
	$realh_flexitime->hidePanel();
}

function realh_flexitime_tick($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime->tick();
}

function chat_timeleft($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime->commandTimeLeft($command, false);
}

function chat_tl($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime->commandTimeLeft($command, true);
}

function chat_timeset($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime->commandTimeSet($command);
}

function chat_whitelist($aseco, $command) {
	global $realh_flexitime;
	$realh_flexitime->commandWhitelist($command);
}
