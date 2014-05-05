<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
	<head>
		<title>
			Pokemon Showdown Replay Analyzer
		</title>
		
		<meta http-equiv='Content-type' content='text/html;charset=UTF-8'>
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link href="css/bootstrap.css" rel="stylesheet">
		
	</head>
	
	<body>
		<center>
		<h1>Pokemon Showdown Replay Analyzer</h1>
		<h4>-By Bret Gourdie</h4>
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
		$lastSwitchedPoke = "";
		$currentWeatherSetter = "";
		$weatherMove = 0;
		
		//Flags to print things once if there's something to review
		$seenFirstWeather = 0;
		$seenReplace = 0;
		
		//Turn counter, mostly for detailed results and debugging
		$turn = 0;
				
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
			
			echo "<div class='container'><div class='jumbotron'>";
			
			if($pos === 0 && $response != "404"){
				$source = file_get_contents($url);
			
				$sourceByLines = explode("\n", $source);
				
				for($ii = 0, $insideLog = false, $atTheEnd = false; $ii < $sourceByLines; $ii++){
					
					//check if we are at the log
					if(strpos($sourceByLines[$ii], 'class="log"') !== false){
						
						$insideLog = true;
						$sourceByLines[$ii] = str_replace('<script type="text/plain" class="log">', 
							"", 
							$sourceByLines[$ii]
						);
						
					}
					
					//found the end of the log; snip off the ending </script>
					else if($insideLog && strpos($sourceByLines[$ii], "</script>") !== false){
						$atTheEnd = true;
						$sourceByLines[$ii] = str_replace('</script>', 
							"", 
							$sourceByLines[$ii]
						);
					}
					
					
					//We are looking at log data from this point on
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
							//	indexed by the trainer and then by species
							case "poke":
								addPoke($splitLine);
							break;
							
							
							//////DETECT NICKNAME
							//If there's a switch-in, grab the nickname.
							//We need this because moves are performed by
							//	the pokemon by nickname, not species.
							//	(?????????????????????????????????? why.)
							case "switch":
							//Also check for "dragging in" a poke (same deal)
							case "drag":
							//////REPLACE POKE
							//Only seen with Zoroark (so far)
							//"Replace" is functionally the same as "switch",
							//	but we'll notify the user just in case things go wrong.
							case "replace":
								grabNickname($splitLine);								
								
								//For weather
								$playerAndNickname = getPlayerAndNickname($splitLine[2]);
								$lastSwitchedPoke = &getPokeByPlayerAndNickname($playerAndNickname);
								
								if($splitLine[1] == "replace" && $seenReplace == 0){
									echo colorFont("Warning: ", "Red") 
										. "A Pokemon changed appearance. "
										."Its kills are undetectable before this occurs. "
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
								
								//For weather
								$playerAndNickname = getPlayerAndNickname($splitLine[2]);
								$lastSwitchedPoke = &getPokeByPlayerAndNickname($playerAndNickname);
							break;
							
							//////RECORD WEATHER
							//Keep track of who put the weather up
							case "-weather":
								//If it's 4 long, it's just upkeep
								//otherwise, 3 long and not "none" means weather is starting
								if(count($splitLine) == 3 && $splitLine[2] != "none"){
									//record who set the weather on which team
									markWeather($splitLine);
								}
							break;
							
							//////RECORD STATUS EFFECT
							//Although we really only care about damaging status,
							//	like burn and poison, we just record any status
							//	just in case something silly happens
							case "-status":
								recordStatus($splitLine);
							break;
							
							//////ADD A START
							//Record a debuff to a specific poke.
							//	Typically anything over time is given this,
							//	such as Leech Seed or Substitute
							case "-start":
								addStart($splitLine);
							break;
							
							//////MARK SIDESTART
							//Record a debuff to an entire trainer's team
							//	Typically anything that stays after a switch-out,
							//	such as Stealth Rocks
							case "-sidestart":
								addSidestart($splitLine);
							break;
							
							//////INCREMENT TURN
							//Keep track of which turn it is. Useful for debugging.
							//	It's never too late to start! :D
							//	(this was added quite late into production)
							case "turn":
								incrementTurn($splitLine);
							break;
							
							//////HANDLE ACTIVATE
							//Activates are going to have to be handled on a "by case"
							//	basis unless I care enough to look into it.
							//	In the league, we really only see Destiny Bond,
							//	so we can handle it specifically in the method
							case "-activate":
								handleActivate($splitLine);
							break;
							
							}
						}
					}
					
					
					if($atTheEnd){
						$insideLog = false;
						//We don't care about anything else in the source, so break here
						break;
					}
				}
				
				//Start printing stuff
				if($show == 1){
					echo "<h3><u>THE BOTTOM LINE:</u></h3>";
				}
				
				//Arrange "[Winner] def [Loser] (X-Y)" before printing the table
				$winner = "";
				$loser = "";
				$winnerNumberLeft = 0;
				$loserNumberLeft = 0;
				//Determine the winner and the loser
				//	and then how many pokes they had left
				foreach($trainers as $trainer){
					if($trainer->win == 1){
						$winner = $trainer;
						
						foreach($pokes[$winner->p] as $species => $poke){
							if($poke->fainted == 0){
								$winnerNumberLeft += 1;
							}
						}
					}
					
					else{
						$loser = $trainer;
						
						foreach($pokes[$loser->p] as $species => $poke){
							if($poke->fainted == 0){
								$loserNumberLeft += 1;
							}
						}
					}
				}
				
				echo "<b>"
					. $winner->name 
					    ." def. "
					. $loser->name 
					." ("
						. $winnerNumberLeft 
						."-"
						. $loserNumberLeft 
					.")</b><br/>";
				
				
				echo "(". $url .")<br/><br/>";
				
				//Everything is parsed; make two pretty tables!
				//	(well, one for each trainer)
				//Iterate through each trainer
				if($show == 1){
					echo "Results:<br />";
				}
				
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
							echo addTD( 
								$poke->fainted == 1 ? colorFont("Yes", "red") : colorFont("No", "green")
							);
						echo "</tr>";
					}
					
					echo "</table>";
					
					echo "<br/>";
				}
				
			}
			
			else{
				echo "Please enter a valid Pokemon Showdown URL and make sure it exists.<br/>";
			}
			
			echo "</div></div>";
		}
		
		//Case functions are below
		//In a lot of them, we are given the following format:
		//pXa: pokeNickname
		//where X is either 1 or 2 (whichever player the poke belongs to)
		//We then split the two up, grabbing the player if needed,
		//	and find the poke by the trainer and the nickname
		//We must do this because the pokes are given to us without
		//	any nicknames and this operation is O(6) anyway
		
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
			
			//If there's an asterisk, we're gonna assume it's
			//	Something-Super (looking at you, Gourgeist)
			if(strpos($species, "*") !== false){
				$species = str_replace("*", "Super", $species);
			}
			
			//Create new poke
			$newPoke = new Poke($species);
			//Append it to the pokes array
			$curTeam = $pokes[$ownedBy];
			$pokes[$ownedBy][$species] = $newPoke;
		}
		
		//////CASE SWITCH
		function grabNickname($splitLine){
			global $pokes;
			
			//Get the player and nickname separately
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$player = $playerAndNickname[0];
			$nickname = $playerAndNickname[1];
			
			//Grab the species and gender
			$species = decoupleSpeciesFromGender($splitLine[3]);
			
			//Set the nickname we just got to the poke it belongs to
			$pokes[$player][$species]->nickname = $nickname;
		}
		
		//////CASE -DAMAGE
		function checkDamage($splitLine){
			global $turn, $lastMovePoke, $lastMoveUsed, $show, $currentWeatherSetter, $sideStarted;
			
			//Do we need to act on this instance?
			if($splitLine[3] == "0 fnt"){
				//yup
				
				//Get the player and nickname
				$playerAndNickname = getPlayerAndNickname($splitLine[2]);
				
				//Get the current poke and player
				$poke = &getPokeByPlayerAndNickname($playerAndNickname);
				$player = $playerAndNickname[0];
				
				//Record fainting
				$poke->fainted = 1;
				
				//Figure out what happened
				//Initially assume it was from a killing move
				//	(easiest)
				$killingMove = $lastMoveUsed;
				$killer = $lastMovePoke;
				
				if(count($splitLine) > 4){
					//Something other than a move: indirect damage
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
					}
					
					else{
						//No "[of]", requires variable state to determine
						//Otherwise, it's probably a self-death
						
						//Check status and weather
						switch ($killingMove) {
							case "brn":
							case "psn":
								$killer = $poke->statusBy;
							break;
							
							
							case "sandstorm":
							case "hail":
								$killer = $currentWeatherSetter;
							break;
							
							//Not status nor weather...
							default:
								//Check sidestarts
								$sidestartResult = $sideStarted[$player][$fromSource];
								
								if(!is_null($sidestartResult)){
									$killer = $sidestartResult;
								}
								
								else{
									//Check starts
									$startResult = $poke->startBy[$fromSource];
									
									if(!is_null($startResult)){
										$killer = $startResult;
									}
									
									else{
										$killer = $poke;
									}
								}
							break;
						}
						
					}
					
				}
				
				if($show == 1){
					echo "Turn ". $turn .": ";
				}
				
				//Get the player of the fainted poke
				$player = $playerAndNickname[0];
				
				//If the killer is on the same team, don't count that kill
				$killerOnSameTeam = checkIfKillerOnSameTeam($killer, $player);
				
				//If killer is not on same team
				if($killerOnSameTeam == 0){
					$killer->kills += 1;
					if($show == 1){
						echo $killer->species . colorFont(" killed ", "Red") 
							. $poke->species 
							." with ". $killingMove ."<br/>";
					}
				}
				
				//Else the killer was on the same team
				else{
					if($show == 1){
						if($killer == $poke){
							echo $killer->species 
								. colorFont(" killed ", "Red") 
								."itself by "
								. $killingMove ."<br/>";
						}
						
						else{
							echo $killer->species 
								. colorFont(" killed ", "Red") 
								. $poke->species 
								." (same-team) by "
								. $killingMove ."<br/>";
						}
					}
				}
			}
		}
		
		//////CASE -FORMECHANGE
		function setMega($splitLine){
			global $show, $turn;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$poke = &getPokeByPlayerAndNickname($playerAndNickname);
			
			//Set species to new mega species (Mega-[species] but we parse it anyway)
			$poke->species = $splitLine[3];
			
			if($show == 1){
				echo "Turn ". $turn .": "
					. $poke->nickname 
					. colorFont(" has become ", "Brown") 
					. $poke->species ."<br/>";
			}
		}
		
		//////CASE WIN
		function recordWin($splitLine){
			global $trainers;
			
			$winner = $splitLine[2];
			
			//Mark the winner on the trainer's attribute
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
			global $lastMovePoke, $show, $turn;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$affectedPoke = getPokeByPlayerAndNickname($playerAndNickname);
			
			if(count($splitLine) > 4){
				//Status self-inflicted from an item probably
				$affectedPoke->statusBy = $affectedPoke;
			}
			
			else{
				//Status from the last move used
				$affectedPoke->statusBy = $lastMovePoke;
			}
			
			if($show == 1){
				
				echo "Turn ". $turn .": ";
				
				if(count($splitLine) > 4){
					echo $affectedPoke->species 
						. colorFont(" statused ", "Purple") 
						."itself with "
						. $splitLine[3] ."<br/>";
				}
				
				else{
					echo $affectedPoke->statusBy->species 
						. colorFont(" statused ", "Purple") 
						. $affectedPoke->species 
						." with "
						. $splitLine[3] ."<br/>";
				}
			}
		}
		
		//////CASE -START
		function addStart($splitLine){
			global $pokes, $lastMovePoke, $show, $turn;
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$affectedPoke = getPokeByPlayerAndNickname($playerAndNickname);
			
			$started = $splitLine[3];
			
			//Mark who started what on this poke
			$affectedPoke->startBy[$started] = $lastMovePoke;
			
			if($show == 1){
				echo "Turn ". $turn .": "
					. $affectedPoke->startBy[$started]->species 
					. colorFont(" started ", "Green") 
					. $started 
					." on "
					. $affectedPoke->species ."<br/>";
			}
		}
		
		//////CASE -SIDESTART
		function addSidestart($splitLine){
			global $trainers, $sideStarted, $lastMovePoke, $show, $turn;
			
			$player = decouplePlayerFromName($splitLine[2]);
			
			$started = decoupleStartedFromType($splitLine[3]);
			
			//Mark who started what on $player's side
			$sideStarted[$player][$started] = $lastMovePoke;
			
			if($show == 1){
				echo "Turn ". $turn .": "
					. $sideStarted[$player][$started]->species 
					. colorFont(" started ", "Green") 
					. $started 
					." for "
					. $player ."'s side<br/>";
			}
		}
		
		//////CASE -WEATHER
		function markWeather($splitLine){
			global $lastMovePoke, $lastSwitchedPoke, $currentWeatherSetter, 
				$show, $turn, $seenFirstWeather;
			
			if($show == 1 && $turn > 0){
				echo "Turn ". $turn .": ";
			}
			
			$weather = $splitLine[2];
			
			//Did weather come about from a move?
			if($lastMovePoke == $weather){
				$currentWeatherSetter = $lastMovePoke;
				if($show == 1){
					echo "Using a move, ";
				}
			}
			
			//Else, it must have come from a switch
			else{
				$currentWeatherSetter = $lastSwitchedPoke;
				if($show == 1 && $turn > 0){
					echo "Switching in, ";
				}
			}
			
			if($show == 1 && $turn > 0){
				echo $currentWeatherSetter->species 
					. colorFont(" set the weather ", "Blue") 
					."to "
					. $weather ."<br/>";
			}
			
			//Turn 0 is the only time there's a a question as to who set the weather.
			//The order of which poke is sent out first is at the mercy of the log,
			//	and it is not always clear when the weather-setter was sent out (first or second)
			if($turn == 0){
				
				if($seenFirstWeather == 0){
					$seenFirstWeather = 1;
					echo colorFont("Warning: ", "Red") 
						. $currentWeatherSetter->species 
						." detected as"
						. colorFont(" having started ", "Blue") 
						. $weather 
						. ". If this is not correct, kills may have to be adjusted.<br/>";
				}
				
				else{
					echo colorFont("Warning: ", "Red") 
						. "Disregard previous message; "
						. $currentWeatherSetter->species 
						." is"
						. colorFont(" now responsible ", "Blue")
						. "for "
						. $weather 
						. ". If this is not correct, kills may have to be adjusted.<br/>";
				}
				
			}
		}
		
		//////CASE -ACTIVATE
		function handleActivate($splitLine){
			global $show, $lastMovePoke;
			
			$activated = $splitLine[3];
			
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			$activatedPoke = getPokeByPlayerAndNickname($playerAndNickname);
			
			//Right now we only care about Destiny Bond but we have room to expand
			switch ($activated){
				case "Destiny Bond":
					$nextLine = peekAtNextLine();
					
					$nextLineSplit = explode("|", $nextLine);
					
					if($nextLineSplit[1] == "faint"){
						$otherPlayerAndNickname = getPlayerAndNickname($nextLineSplit[2]);
						
						$faintedPoke = getPokeByPlayerAndNickname($otherPlayerAndNickname);
						
						$activatedPoke->kills += 1;
						
						$faintedPoke->fainted = 1;
						
						if($show == 1){
							echo $activatedPoke->species 
								. colorFont(" dragged ", "Green") . $faintedPoke->species 
								. " down with him with Destiny Bond<br/>";
						}
					}
				break;
			}
		}
		
		//////CASE TURN
		//Increment turn is a bit of a misnomer, we update the turn to whatever is given
		//	but it only goes up by one each turn, so
		function incrementTurn($splitLine){
			global $turn;
			
			$turn = $splitLine[2];
		}
		
		//End case functions
		
		//Begin helper functions
		
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
		
			$speciesAndGenderSplit = explode(", ", $segment);
			
			$species = $speciesAndGenderSplit[0];
			
			return $species;
		}
		
		function getplayerAndNickname($segment){
		
			$playerAndNicknameSplit = explode("a: ", $segment);
			
			//Sometimes we get a string in the format "pX: "
			//	Why? I dunno. Account for it here
			if(count($playerAndNicknameSplit) < 2){
				//Try exploding on ": " only, as we may not have the "a"
				$playerAndNicknameSplit = explode(": ", $segment);
			}
			
			return $playerAndNicknameSplit;
		}
		
		//May need the address returned sometimes. Anywhere where I
		//	use the address was done by trial and error and may not
		//	be needed anymore but I don't wan to touch it
		//$playerAndNickname is expected as an array returned from
		//	getPlayerAndNickname, not the splitLine segment
		function &getPokeByPlayerAndNickname($playerAndNickname){
			global $pokes;
			
			$player = $playerAndNickname[0];
			$nickname = $playerAndNickname[1];
			
			foreach($pokes[$player] as $species => $curPoke){
				if($curPoke->nickname == $nickname){
					return $curPoke;
				}
			}
			
			//Should never happen
			//(ha ha ha.)
			echo "ERROR: could not find ". $nickname ." in ". $player ."'s team<br/>";
			return new Poke("unknown", "oh no");
		}
		
		//Only used for Destiny Bond to see what is killed as a result
		function peekAtNextLine(){
			global $sourceByLines, $ii;
			
			return $sourceByLines[$ii + 1];
		}
		
		function addSide($splitLine){
			global $trainers, $sideStarted;

			$player = $splitLine[2];
			//Add a new sidestart array for the trainer,
			//	indexed by trainer
			$sideStarted[$player] = array();
		}
		
		//Gotten from StackOverflow, used to check for a 404
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
		

		<div class="jumbotron">
			<h2>Enter a Pokemon Showdown Replay URL:</h2>
			<form name="input" action="replay_analyzer.php" method="post">
				<table>
					<tr>
						<td>URL of replay:</td>
						<td><input type="text" name="page" size="50" value="<?php
							if(ISSET($_POST["page"])){
								echo $_POST["page"];
							}
						?>" /></td>
					</tr>
					<tr>
						<td colspan="2" align="center" >
							<input type="submit" class="btn btn-lg btn-primary" value="Analyze!" />
						</td>
					</tr>
					
					<tr>
						<td><label>
							<input type="radio" name="show" value="1" <?php 
							if(ISSET($_POST["show"])){
								echo $show ? "checked" : "";
							}
							
						?> /> Detailed Results</label></td>
						<td align="right"><label><input type="radio" name="show" value="0" <?php 
							if(ISSET($_POST["show"])){
								echo $show ? "" : "checked"; 
							}
							
							else{
								//Default to checked if first time here
								echo "checked";
							}
							
						?> /> Bottom Line Only</label></td>
					</tr>
				</table>
			</form>
		</div>

		
		</center>
	</body>
</html>