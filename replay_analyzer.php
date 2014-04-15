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
		
		//Check if POST variable was passed
		if(isset($_POST["page"])) {
		
			//echo "page was set!<br />";
		
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
				
				if($insideLog){
					//inside the log
					echo $sourceByLines[$ii] . "<br />";
					
					//state logic here
				}
				
				if($atTheEnd){
					$insideLog = false;
					break;
				}
			}
			
			echo "<p>Parse another replay...<br />";
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