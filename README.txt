[#] v2.0 (02.02.2024)

(!) These changes and additions are driven by personal experience with using and administrating the RPG-Trial server. If your server's style differs, this version might not be suitable for you. Please read the introductory information and the list of changes before installation!


[*] Installation
1) Place plugin.flexitime_v2.php in the $xaseco/plugins/ directory;
2) Place flexitime.xml in the $xaseco/ directory;
3) Add the line "<plugin>plugin.flexitime_v2.php</plugin>" to plugins.xml;
4) Configure the plugin to your liking in flexitime.xml;
5) Restart XAseco.


[*] Introductory Information
This update is built around the idea of a whitelist. Some players are active enough to have the ability to extend the timer, but you may not trust them enough to grant them operator roles or higher for some reason. In this case, you can add them to the whitelist. They won't be able to use /timeleft; instead, they will be provided with the /tl command (the original functionality is modified), which will extend the time by a fixed value that you set in the config. Additionally, you can set a restriction on when this command becomes available based on the timer value. For example, the /tl command can only add 30 minutes when there are less than 10 minutes left in the round. Read below for other changes.


[*] Changes
(*) Command /whitelist
	/whitelist                - displays a window with the list of all players on the whitelist.
	/whitelist help           - displays the help window on how to use the command (what you're currently reading).
	/whitelist reload         - reloads the whitelist after manually made changes.
	/whitelist add <login>    - adds a player to the whitelist.
	/whitelist remove <login> - removes a player from the whitelist.
	(!) After add or remove, reloading the whitelist and restarting XAseco is NOT required.

(*) Command /tl
	/tl no longer sets the timer to 5 minutes but is used to extend the timer by a fixed value x1 for players on the whitelist. Optionally: only when the timer is below value x2. These values are set in flexitime.xml.
	For operators and above, if they are not on the whitelist, the following are available:
	- /tl at any timer value (not an equivalent to /timeleft).
	- /tl pause
	- /tl resume

(*) Removed admin level 0
	Due to the above, this is no longer necessary.

(*) Added whitelist admin levels
	Allows setting permissions for adding/removing new players to the whitelist for either Master Admins or both Master Admins and Admins.

(*) Nickname will now be displayed instead of login in all messages.

(*) Fixed prefixes
	Public messages are now displayed with the ">>" prefix, as they should, and private messages are displayed with the ">" prefix.

(*) Slightly improved formatting of messages.

(**) Added the ability to remove the upper time limit.
(**) /timeleft +<value>, /timeleft -<value>, "max_timeleft"
	At the time of my changes and writing this text, the plugin repository on the XAseco website contains the old version of this plugin (1.3.3), so the first 2 commands may not work correctly for you, and the upper time limit may be absent altogether. The current version (also listed as 1.3.3, although it is actually 1.4) is available on the author's GitHub (https://github.com/realh/flexitime). If you are not satisfied with my fork and have the old version installed, I recommend updating to the author's latest version.
