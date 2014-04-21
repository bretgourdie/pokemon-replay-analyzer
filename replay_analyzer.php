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
				
		//Check if POST variable was passed
		if(isset($_POST["page"])) {
		
			$url = $_POST["page"];
			
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
							grabNickname($splitLine);
						break;
						
						
						//////DETECT FAINTING
						//If someone fainted, record it
						case "faint":
							recordFaint($splitLine);
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
						
						}
					}
				}
				
				
				if($atTheEnd){
					$insideLog = false;
					break;
				}
			}
			
			//Arrange "[Winner] def [Loser] (X-0)" before printing the table
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
			
			echo "<b>". $winner->name ." def. ". $loser->name ." (". $numberLeft ."-0)</b><br/><br/>";
			
			//Everything is parsed; make two pretty tables!
			//Iterate through each trainer
			echo "Results:<br />";
			foreach($trainers as $trainer){
				
				echo "<b>". $trainer->name ."</b>";
				
				echo "<br/>";
				
				//Start the table
				echo "<table border=1>";
				
				echo "<tr>";
					addTH("Pokemon");
					addTH("Kills");
					addTH("Fainted");
				echo "</tr>";
				
				//Iterate through the current trainer's pokemon
				foreach($pokes[$trainer->p] as $species => $poke){
					echo "<tr>";
						addTD($poke->species);
						addTD($poke->kills);
						addTD( $poke->fainted == 1 ? "Yes" : "No" );
					echo "</tr>";
				}
				
				echo "</table>";
				
				echo "<br/>";
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
		
		//////CASE FAINT
		function recordFaint($splitLine){
			global $pokes;
			
			//Get the player and nickname
			$playerAndNickname = getPlayerAndNickname($splitLine[2]);
			
			//Get the current poke
			$poke = &getPokeByPlayerAndNickname($playerAndNickname);
			
			//Assign it as fainted
			$poke->fainted = 1;
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
		
		function addTD($element){
			echo "<td>". $element ."</td>";
		}
		
		function addTH($element){
			echo "<th>". $element ."</th>";
		}
		
		?>
		
		<form name="input" action="replay_analyzer.php" method="post">
			<table>
				<tr>
					<td>URL of replay:</td>
					<td><input type="text" name="page" size="50" /></td>
				</tr>
				<tr>
					<td colspan="2" align="center" ><input type="submit" value="Analyze!" />
					</td>
				</tr>
			</table>
		</form>
	</body>
</html>