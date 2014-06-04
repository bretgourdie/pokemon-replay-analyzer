pokemon-replay-analyzer
=======================

Parses a Pokemon Showdown replay website and determines K/D of each Pokemon.
Website currently hosted at:
	http://www.pokemonbattlefederation.com/replay_analyzer.php

Instructions
------
	
	Enter a Pokemon Showdown Replay URL, click analyze, then sit back as
	the PHP script determines the kill/death and fainting of each 'mon
	as well as the winning trainer.

Program Flow
------
	
	After some initialization, the program checks the supplied URL and makes 
	sure that it is from replay.pokemonshowdown.com, then ensures that the
	provided replay exists (even a valid replay URL can eventually be deleted
	after a certain amount of time).
	
	The program then reads the HTML source of the replay, digging for the
	beginning of the battle, which begins at the "log" class script.
	
	From here on, the program follows somewhat of a pattern. Battle logs
	start with the definition of two players, then their six Pokemon on 
	their teams, after which the Pokemon do battle against one another
	until a player forfeits or runs out of Pokemon, ending with the other
	player earning a win. Usually, each line starts with a command and who
	it affects, requiring subcommands in the following lines if necessary.
	
	The program parses the command by using a Switch Case structure.
	Typically, each useful (to us) command requires a different course
	of action, but some are effectually equivalent, like switch-ins and
	drag-ins.
	
	Although there is a "faint" (or death) command, we don't use it
	because there isn't enough useful information attached to it. It
	is far more useful to parse the "-damage" subcommand, as we then
	have the Pokemon who fainted as well as the source of damage
	that caused the faint. This source may be from an item or
	external force.
	
	Speaking of which, we keep track of any such "circumstantial"
	damage sources as they appear, such as field hazards, weather,
	and status damages.
	
	The way this all works is by utilizing a few hash maps; one for
	trainers and one for Pokemon. We also have a bunch of incidental 
	variables for pointers both on the Pokemon and separately to keep
	track of anything on a "by Pokemon" (i.e. status effects) or 
	"by Trainer" (i.e. Stealth Rocks) basis.
	
	When a Pokemon faints, we simply grab the pointer to the Pokemon(s)
	involved and mark the necessary variables based on the actions that
	have occurred. Easy.
	
	After all is said and done, we make a nice lil' table outlining
	who did what on each team. If you want more information, you
	can flip on the Debug information radio button and see what I
	determine as "points of interest" (status-setting, killing,
	side-starting, etc.).
	
Some weird stuff I came across
------
	
	I have to grab the nickname of each Pokemon as it switches in because
	it isn't included in the initial Pokemon definition and yet is required
	when attributing damage. If it was given to me initially, I could index
	the Pokemon based on nickname and everything would be just swell, but
	instead I have to use a function to iterate through them and figure out
	which one is which because the species isn't always given to me. Just a
	weird quirk.
	
	Recoil looks like it's attributed as a damage source from the other
	Pokemon, even when it's from something like Double Edge. That's silly,
	because Item damage sources (like Rocky Helmet AND Life Orb) are
	conveyed correctly.
	
	There's a "player" command way far down in the log. I haven't looked
	too far into it but it's either when the other player leaves the room
	or a complete and total mystery.
	
	It's impossible to determine who started weather on the 0th turn
	without having a list of Pokemon who can cause weather on a switch-in.
	This isn't consistent with ANY OTHER turn (I think) as the weather
	starts as soon as the relevant switch-in occurs, not after both
	switch-ins happen.
