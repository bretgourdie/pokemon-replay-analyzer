<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
	<head>
		<title>
			Pokemon Showdown Replay Analyzer
		</title>
		<meta http-equiv='Content-type' content='text/html;charset=UTF-8'>
		
	</head>
	
	<body>
		<center>
		<h1>Pokemon Showdown Replay Analyzer</h1>
		<h4>By Gor-Don :)</h4>
		<?php
		
		class Trainer
		{
			public $name = "null";
			public $p = "null";
			public $win = 0;
			
			function __construct($p, $name){
				$this->name = $name;
				$this->p = $p;
			}
		}
		
		//Array of trainers
		$trainers = array();
		
		class poke
		{
			public $species = "null";
			public $nickname = "null";
			//Used to maintain state in case of a toxic/burn kill
			public $statusBy = "null";
			//Used for other damaging debuffs
			public $startBy = array();
			public $kills = 0;
			public $fainted = 0;
			
			function __construct($species){
				$this->species = $species;
			}
		}
		
		//Arrays of mons indexed by trainer
		//	$pokes[$pX][pokeX]; or something
		$pokes = array();
		
		//Other variables associated with damaging moves
		$lastMoveUsed = "";
		$lastMovePoke = "";
		$sideStarted = array();
		//For weather
		$lastSwitchPoke = "";
		$currentWeatherSetter = "";
		$weatherMove = 0;
		
		//Flags to print things once if there's something to review
		$seenReplace = 0;
				
		//Check if POST variables were passed
		if(isset($_POST["page"]) && isset($_POST["show"])) {
		
			$url = $_POST["page"];
			$show = $_POST["show"];
			
			//Check if url is a pokemon showdown url
			$pos = strpos($url, "http://replay.pokemonshowdown.com/");
			
			//Also check if URL is not 404
			$response = "404";
			if($pos === 0){
				$response = get_http_response_code($url);
			}
			
			if($pos === 0 && $response != "404"){
				$source = file_get_contents($url);
			
				$sourceByLines = explode("\n", $source);
				
				for($ii = 0, $insideLog = false, $atTheEnd = false; $ii < $sourceByLines; $ii++){
					
					//check if we are at the log
					if(strpos($sourceByLines[$ii], 'class="log"') !== false){
						//echo "found the start of the log<br />";
						
						$insideLog = true;
						$sourceByLines[$ii] = str_replace('<script type="text/plain" class="log">', 
							"", 
							$sourceByLines[$ii]
						);
					}
					
					//found the end of the log; snip off the ending </script>
					else if($insideLog && strpos($sourceByLines[$ii], "</script>") !== false){
						//echo "found the end of the log<br />";
						$atTheEnd = true;
						$sourceByLines[$ii] = str_replace('</script>', 
							"", 
							$sourceByLines[$ii]
						);
					}
					
					
					//state logic here
					if($insideLog){
						//inside the log
						$currentLine = $sourceByLines[$ii];
						
						//Split currentLine by pipeline
						$splitLine = explode("|", $currentLine);
						
						//Skip any "blank" lines
						if(count($splitLine) > 1) {
						
							switch ($splitLine[1]){
							
							//////ADD TRAINER
							//If there's a new trainer, add them to the array
							//	but make sure that it's not the weird "player" command
							//	at the bottom of the log. (what does it mean?????????)
							case "player":
								if(count($splitLine) > 3){
									addPlayer($splitLine);
									addSide($splitLine);
								}
							break;
							
							//////ADD POKE
							//If there's a new pokemon, add it to the array
							//	indexed by the trainer
							case "poke":
								addPoke($splitLine);
							break;
							
							
							//////DETECT NICKNAME
							//If there's a switch-in, grab the nickname.
							//We need this because moves are performed by
							//	the pokemon by nickname, not species.
							//	(?????????????????????????????????? why.)
							case "switch":
							//////REPLACE POKE
							//Only seen with Zoroark (so far)
							//"Replace" is functionally the same as "switch",
							//	but we'll notify the user just in case things go wrong.
							case "replace":
								grabNickname($splitLine);
								
								//For weather
								$lastSwitchedPoke = $splitLine[2];
								
								if($splitLine[1] == "replace" && $seenReplace == 0){
									echo "A Pokemon changed appearance. Its kills are undetectable before this occurs. "
										."Please manually adjust the kill count.<br/>";
									$seenReplace = 1;
								}
							break;
							
							
							//////DETECT DAMAGE
							//If something fainted as a result of damage, record it
							case "-damage":
								checkDamage($splitLine);
							break;
							
							//////DETECT WIN
							//Someone won; record it
							case "win":
								recordWin($splitLine);
							break;
							
							//////RECORD MOVE
							//Keep track of the last move used in case someone dies
							case "move":
								handleMove($splitLine);
							break;
							
							//////CHANGE TO MEGA
							//When a poke changes to a mega form, change the species
							case "-formechange":
								setMega($splitLine);
							break;
							
							//////RECORD WEATHER
							//Keep track of who put the weather up
							//Don't functionize this :<
							case "-weather":
								//If it's 4, it's just upkeep
								//otherwise, 3 and not "none" means weather is starting
								if(count($splitLine) == 3 && $splitLine[2] != "none"){
									//record who set the weather on which team
									markWeather($splitLine);
								}
							break;
							
							//////RECORD STATUS EFFECT
							//If someone affects someone else with a status,
							//	record it just in case
							case "-status":
								recordStatus($splitLine);
							break;
							
							//////MARK SIDESTART
							//Stealth Rocks and such are added to an array here
							//	which we use to look up later
							case "-sidestart":
								addSidestart($splitLine);
							break;
							
							//DON'T ADD A CASE BELOW THIS LINE TO AVOID SYNTAX ERROR ):[
							}
						}
					}
					
					
					if($atTheEnd){
						$insideLog = false;
						break;
					}
				}
				
				//Arrange "[Winner] def [Loser] (X-0)" before printing the table
				echo "<h3><u>THE BOTTOM LINE:</u></h3>";
				$winner = "";
				$loser = "";
				$numberLeft = 0;
				//Detemine the winner and the loser
				foreach($trainers as $trainer){
					if($trainer->win == 1){
						$winner = $trainer;
						
						//Determine how many pokemon the winner had left
						foreach($pokes[$winner->p] as $species => $poke){
							if($poke->fainted == 0){
								$numberLeft += 1;
							}
						}
					}
					
					else{
						$loser = $trainer;
					}
				}
				
				echo "<b>". $winner->name ." def. ". $loser->name ." (". $numberLeft ."-0)</b><br/>";
				
				
				echo "(". $url .")<br/><br/>";
				
				//Everything is parsed; make two pretty tables!
				//Iterate through each trainer
				echo "Results:<br />";
				foreach($trainers as $trainer){
					
					echo "<b>". $trainer->name ."</b>";
					
					echo "<br/>";
					
					//Start the table
					echo "<table border=1>";
					
					echo "<tr>";
						echo addTH("Pokemon");
						echo addTH("Kills");
						echo addTH("Fainted");
					echo "</tr>";
					
					//Iterate through the current trainer's pokemon
					foreach($pokes[$trainer->p] as $species => $poke){
						echo "<tr>";
							echo addTD($poke->species);
							echo addTD($poke->kills);
							echo addTD( $poke->fainted == 1 ? colorFont("Yes", "red") : colorFont("No", "green") );
						echo "</tr>";
					}
					
					echo "</table>";
					
					echo "<br/>";
				}
			}
			
			else{
				echo "Please enter a valid Pokemon Showdown URL and make sure it exists.<br/>";
			}
		}
		
		//////CASE PLAYER
		function addPlayer($splitLine){
			global $pokes, $trainers;
		
			//Grab the player number
			$player = $splitLine[2];
			//Grab the trainer name
			$trainer = $splitLine[3];
			//Add the trainer
			$newTrainer = new Trainer($player, $trainer);
			//Append it to the trainers array
			array_push($trainers, $newTrainer);
			//Add a new poke array for the trainer,
			//	indexed by trainer
			$pokes[$player] = array();
		}
		
		//////CASE POKE
		function addPoke($splitLine){
			global $pokes;
			
			//Grab the trainer
			$ownedBy = $splitLine[2];
			$species = decoupleSpeciesFromGender($splitLine[3]);
			
			//Create new poke
			$newPoke = new Poke($species);
			//Append it to the pokes array
			$curTeam = $pokes[$ownedBy];
			$pokes[$ownedBy][$species] = $newPoke;
		}
		
		//////CASE SWITCH
		function grabNickname($splitLine){
			global $pokes;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			//Get the player and nickname
			$player = $playerAndNickname[0];
			$nickname = $playerAndNickname[1];
			
			//Grab the species and gender
			$species = decoupleSpeciesFromGender($splitLine[3]);
			
			$pokes[$player][$species]->nickname = $nickname;
		}
		
		//////CASE -DAMAGE
		function checkDamage($splitLine){
			global $lastMovePoke, $lastMoveUsed, $show;
			
			//Do we need to act on this instance?
			if($splitLine[3] == "0 fnt"){
				//yup
				
				//Get the player and nickname
				$playerAndNickname = getPlayerAndNickname($splitLine[2]);
				
				//Get the current poke
				$poke = &getPokeByPlayerAndNickname($playerAndNickname);
				
				//Record fainting
				$poke->fainted = 1;
				
				//Say what happened
				$killingMove = $lastMoveUsed;
				$killer = $lastMovePoke;
				
				if(count($splitLine) > 4){
					//Something other than a move
					$fromSource = $splitLine[4];
					$fromSource = str_replace("[from] ", "", $fromSource);
					$killingMove = $fromSource;
					
					//Recoil is attributed to the opposing poke.  Yeah, I know.
					//If it's recoil, it's a self-kill, so drop down
					if(count($splitLine) > 5 && $killingMove != "recoil"){
						//We have a "[of]" for attribution of the kill!  Hooray!
						$ofSource = $splitLine[5];
						$ofSource = str_replace("[of] ", "", $ofSource);
						$killerPlayerAndNickname = getPlayerAndNickname($ofSource);
						$killer = &getPokeByPlayerAndNickname($killerPlayerAndNickname);
						
						if($show == 1){
							echo "[of] detected; ";
						}
					}
					
					else{
						//No "[of]", requires variable state to determine
						//Otherwise, it's probably a self-death
						switch ($killingMove) {
							case "brn":
							case "psn":
								$killer = $poke->statusBy;
							break;
							
							/*
							case "sandstorm":
							case "hail":
								$killer = 
							break;
							*/
							
							default:
								$killer = $poke;
							break;
						}
						
						if($show == 1){
							echo "[from] detected; ";
						}
					}
					
				}
				
				//Get the player of the fainted poke
				$player = $playerAndNickname[0];
				
				//If the killer is on the same team, don't count that kill
				$killerOnSameTeam = checkIfKillerOnSameTeam($killer, $player);
				
				if($killerOnSameTeam == 0){
					$killer->kills += 1;
					if($show == 1){
						echo $killer->species ." killed ". $poke->species ." by ". $killingMove ."<br/>";
					}
				}
				
				else{
					if($show == 1){
						echo $killer->species ." killed itself or a friend by ". $killingMove ."<br/>";
					}
				}
			}
		}
		
		//////CASE -FORMECHANGE
		function setMega($splitLine){
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$poke = &getPokeByPlayerAndNickname($playerAndNickname);
			
			//Set species to new mega species (Mega-[species] but we parse it anyway)
			$poke->species = $splitLine[3];
		}
		
		//////CASE WIN
		function recordWin($splitLine){
			global $trainers;
			
			$winner = $splitLine[2];
			
			foreach($trainers as $trainer){
				if($trainer->name == $winner){
					$trainer->win = 1;
				}
			}
		}
		
		//////CASE MOVE
		function handleMove($splitLine){
			global $lastMoveUsed, $lastMovePoke;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$lastMovePoke = getPokeByPlayerAndNickname($playerAndNickname);
			
			$lastMoveUsed = $splitLine[3];
		}
		
		//////CASE -STATUS
		function recordStatus($splitLine){
			global $lastMovePoke;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$affectedPoke = getPokeByPlayerAndNickname($playerAndNickname);
			
			$affectedPoke->statusBy = $lastMovePoke;
			
			if($show == 1){
				echo $affectedPoke->statusBy->species ." statused ". $affectedPoke->species ."<br/>";
			}
		}
		
		//////CASE -SIDESTART
		function addSidestart($splitLine){
			global $sideStarted, $lastMovePoke;
			
			$player = decouplePlayerFromName($splitLine[2]);
			
			$started = decoupleStartedFromType($splitLine[3]);
			
			$sideStarted[$player][$started] = $lastMovePoke;
			
			if($show == 1){
				echo $sideStarted[$player][$started]->species ." just started ". $started ."<br/>";
			}
		}
		
		//////CASE -WEATHER
		function markWeather($splitLine){
		
		}
		
		function decoupleStartedFromType($segment){
			$typeAndStarted = explode(": ", $segment);
			
			$started = $typeAndStarted[1];
			
			return $started;
		}
		
		function decouplePlayerFromName($segment){
			$playerAndName = explode(": ", $segment);
			
			$player = $playerAndName[0];
			
			return $player;
		}
		
		function getPlayerAndName($segment){
			$playerAndName = explode(": ", $segment);
			
			return $playerAndName;
		}
		
		function checkIfKillerOnSameTeam($killer, $faintedTeam){
			global $pokes;
			
			foreach($pokes[$faintedTeam] as $curPoke){
				if($curPoke == $killer){
					return 1;
				}
			}
			
			return 0;
		}
		
		function decoupleSpeciesFromGender($segment){
			//$segment has the poke species and gender
			//Split the species from the gender
			$speciesAndGenderSplit = explode(", ", $segment);
			//Grab the species
			$species = $speciesAndGenderSplit[0];
			//Return it!
			return $species;
		}
		
		function getplayerAndNickname($segment){
			//Grab the player and nickname in $segment
			//Split the two
			$playerAndNicknameSplit = explode("a: ", $segment);
			
			return $playerAndNicknameSplit;
		}
		
		function &getPokeByPlayerAndNickname($playerAndNickname){
			global $pokes;
			
			$player = $playerAndNickname[0];
			$nickname = $playerAndNickname[1];
			
			foreach($pokes[$player] as $species => $curPoke){
				if($curPoke->nickname == $nickname){
					return $curPoke;
				}
			}
		}
		
		function addSide($splitLine){
			global $trainers, $sideStarted;

			//Grab the player number
			$player = $splitLine[2];
			//Add a new sidestart array for the trainer,
			//	indexed by trainer
			$sideStarted[$player] = array();
		}
		
		function get_http_response_code($url) {
			$headers = get_headers($url);
			return substr($headers[0], 9, 3);
		}
		
		function addTD($element){
			return "<td>". $element ."</td>";
		}
		
		function addTH($element){
			return "<th>". $element ."</th>";
		}
		
		function colorFont($element, $color){
			return "<font color='". $color ."'>". $element ."</font>";
		}
		
		?>
		
		<form name="input" action="replay_analyzer.php" method="post">
			<table>
				<tr>
					<td>URL of replay:</td>
					<td><input type="text" name="page" size="50" /></td>
				</tr>
				<tr>
					<td colspan="2" align="center" >
						<input type="submit" value="Analyze!" />
					</td>
				</tr>
				
				<tr>
					<td><input type="radio" name="show" value="1" <?php 
						if(ISSET($_POST["show"])){
							echo $show ? "checked" : "";
						}
						
						else{
							//Default to checked if first time here
							echo "checked";
						}
						
					?> /> Detailed Results</td>
					<td align="right"><input type="radio" name="show" value="0" <?php 
						if(ISSET($_POST["show"])){
							echo $show ? "" : "checked"; 
						}
						
					?> /> Bottom Line Only</td>
				</tr>
			</table>
		</form>
		
		</center>
	</body>
</html>