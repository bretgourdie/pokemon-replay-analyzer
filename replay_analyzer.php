<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
	<head>
		<title>
			Replay Analyzer
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
			public $dead = false;
			
			function __construct($species){
				$this->species = $species;
			}
		}
		
		//Arrays of mons indexed by trainer
		//	$pokes[$pX][pokeX]; or something
		$pokes = array();
				
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
						break;
						
						//////ADD POKE
						//If there's a new pokemon, add it to the array
						//	indexed by the trainer
						case "poke":
							//Grab the trainer
							$ownedBy = $splitLine[2];
							$species = decoupleSpeciesFromGender($splitLine[3]);
							
							//Create new poke
							$newPoke = new Poke($species);
							//Append it to the pokes array
							$curTeam = $pokes[$ownedBy];
							$pokes[$ownedBy][$species] = $newPoke;
						break;
						
						/*
						//////DETECT NICKNAME
						//If there's a switch-in, grab the nickname.
						//We need this because moves are performed by
						//	the pokemon by nickname, not species.
						//	(?????????????????????????????????? why.)
						case "switch":
							$playerAndNickname = getPlayerAndNickname(splitLine[2]);
							//Get the player and nickname
							$player = $playerAndNickname[0];
							$nickname = $playerAndNickname[1];
							//Grab the species and gender
							$species = decoupleSpeciesFromGender($splitLine[3]);
							
							$curPokes = $pokes[$player];
							
						break;
						*/
						
						}
					}
				}
				
				
				if($atTheEnd){
					$insideLog = false;
					break;
				}
			}
			
			echo "Players:<br />";
			foreach($trainers as $trainer){
				
				echo $trainer->name ."; Player ". $trainer->p ."<br/>";				
				
				foreach($pokes[$trainer->p] as $species => $poke){
					echo $poke->species ."; ";
				}
				
				
				echo "<br/>";
			}
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
			$playerAndNicknameSplit = explode(": ", $segment);
			//Remove the "a" from player
			$playerAndNicknameSplit[0] = substr(
				$playerAndNicknameSplit[0], 0, 2
			);
			return $playerAndNicknameSplit;
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